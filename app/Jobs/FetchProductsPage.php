<?php

namespace App\Jobs;

use App\Models\IngestionRun;
use App\Models\Product;
use App\Services\OpenFoodFacts\InvalidResponse;
use App\Services\OpenFoodFacts\OpenFoodFactsClient;
use App\Services\OpenFoodFacts\ProductNormalizer;
use App\Services\OpenFoodFacts\RateLimitedByRemote;
use App\Services\OpenFoodFacts\SearchPage;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches ONE page of /api/v2/search, upserts it, then dispatches the next page.
 *
 * Why a self-dispatching chain rather than Bus::batch() of N page jobs:
 *  - OFF allows ~10 search req/min, so parallel workers buy nothing.
 *  - Incremental runs don't know N up front: they stop when they reach
 *    products older than last run's watermark. A batch would fetch pages
 *    it doesn't need.
 *  - The cursor (ingestion_runs.next_page) lives in the database, so a dead
 *    worker or a lost job can be resumed from the exact page.
 */
class FetchProductsPage implements ShouldQueue
{
    use Queueable;

    /** Exceptions (5xx, timeouts) tolerated before the run is failed. */
    public int $maxExceptions = 5;

    public int $timeout = 120;

    public function __construct(public int $runId, public int $page)
    {
        $this->onQueue(config('foodfacts.queue'));
    }

    /**
     * RateLimited *releases* the job when over budget. Releases count as
     * attempts, so a fixed $tries would eventually fail healthy jobs that were
     * merely waiting their turn. Bound by wall-clock time (retryUntil) and by
     * real errors ($maxExceptions) instead.
     */
    public function middleware(): array
    {
        return [new RateLimited('openfoodfacts')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(OpenFoodFactsClient $client, ProductNormalizer $normalizer): void
    {
        $run = IngestionRun::find($this->runId);

        // Cancelled/finished run, or a duplicate delivery of a page already committed.
        if (! $run || ! $run->isActive() || $run->next_page !== $this->page) {
            return;
        }

        try {
            $page = $client->searchPage($this->page);
        } catch (RateLimitedByRemote $e) {
            $this->release($e->retryAfter);

            return;
        } catch (InvalidResponse $e) {
            $this->fail($e); // retrying a 400 just burns rate limit

            return;
        }
        // RemoteUnavailable bubbles up: counts toward $maxExceptions, retried with backoff.

        $rows = $normalizer->normalize($page->products, $run->id);
        $stopReason = $this->stopReason($run, $page);

        $committed = DB::transaction(function () use ($run, $page, $rows, $stopReason) {
            /** @var IngestionRun $locked */
            $locked = IngestionRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $locked->isActive() || $locked->next_page !== $this->page) {
                return false;
            }

            if ($rows !== []) {
                // ON CONFLICT (code) DO UPDATE. No Eloquent events fire, and none are
                // needed: search_vector is a generated column, Postgres recomputes it.
                Product::upsert(
                    array_values($rows),
                    uniqueBy: ['code'],
                    update: ['product_name', 'brands', 'categories', 'image_url', 'off_modified_at', 'last_run_id', 'updated_at'],
                );
            }

            $locked->forceFill([
                'status' => $stopReason ? IngestionRun::COMPLETED : IngestionRun::RUNNING,
                'next_page' => $this->page + 1,
                'pages_fetched' => $locked->pages_fetched + 1,
                'products_seen' => $locked->products_seen + count($rows),
                'products_skipped' => $locked->products_skipped + (count($page->products) - count($rows)),
                'watermark' => max((int) $locked->watermark, $this->maxModified($page)) ?: null,
                'started_at' => $locked->started_at ?? now(),
                'last_progress_at' => now(),
                'stop_reason' => $stopReason,
                'finished_at' => $stopReason ? now() : null,
            ])->save();

            return true;
        });

        // Dispatch only after the cursor is committed. If the process dies between
        // commit and dispatch, the run looks stalled and `foodfacts:ingest` resumes it.
        if ($committed && $stopReason === null) {
            static::dispatch($this->runId, $this->page + 1);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Open Food Facts ingestion failed', [
            'run' => $this->runId, 'page' => $this->page, 'error' => $e?->getMessage(),
        ]);

        IngestionRun::find($this->runId)?->markFailed(
            "Page {$this->page}: ".($e?->getMessage() ?? 'unknown error')
        );
    }

    private function stopReason(IngestionRun $run, SearchPage $page): ?string
    {
        if ($page->products === [] || $page->isLast()) {
            return 'exhausted';
        }

        // Sorted newest-modified first: once the oldest item on this page is at or
        // before the previous run's watermark, everything after it is already stored.
        if ($run->cutoff !== null) {
            $modified = array_filter(array_column($page->products, 'last_modified_t'), 'is_numeric');

            if ($modified !== [] && min($modified) <= $run->cutoff) {
                return 'caught_up';
            }
        }

        if ($this->page >= $run->max_pages) {
            return 'max_pages';
        }

        return null;
    }

    private function maxModified(SearchPage $page): int
    {
        $modified = array_filter(array_column($page->products, 'last_modified_t'), 'is_numeric');

        return $modified === [] ? 0 : (int) max($modified);
    }
}
