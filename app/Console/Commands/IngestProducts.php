<?php

namespace App\Console\Commands;

use App\Jobs\FetchProductsPage;
use App\Models\IngestionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class IngestProducts extends Command
{
    protected $signature = 'foodfacts:ingest
        {--full : Ignore the last watermark and page up to --max-pages}
        {--max-pages= : Override foodfacts.max_pages for this run}
        {--resume-only : Only resume a stalled run; never start a new one}';

    protected $description = 'Start (or resume) an Open Food Facts ingestion run on the queue';

    public function handle(): int
    {
        // Two schedulers / a manual run racing the cron must not create two runs.
        $lock = Cache::lock('foodfacts:ingest:start', 30);

        if (! $lock->get()) {
            $this->warn('Another process is starting a run.');

            return self::SUCCESS;
        }

        try {
            return $this->start();
        } finally {
            $lock->release();
        }
    }

    private function start(): int
    {
        $active = IngestionRun::active()->latest('id')->first();

        if ($active && ! $active->isStalled()) {
            $this->info("Run #{$active->id} is still in progress (page {$active->next_page}). Nothing to do.");

            return self::SUCCESS;
        }

        if ($active) {
            // Worker died, job lost, or dispatch failed after commit. The cursor is
            // durable, so resume from the exact page instead of starting over.
            $active->forceFill(['last_progress_at' => now()])->save();
            FetchProductsPage::dispatch($active->id, $active->next_page);
            $this->warn("Run #{$active->id} was stalled; resumed at page {$active->next_page}.");

            return self::SUCCESS;
        }

        if ($this->option('resume-only')) {
            $this->line('No stalled run to resume.');

            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');

        $run = IngestionRun::create([
            'status' => IngestionRun::PENDING,
            'mode' => $full ? 'full' : 'incremental',
            'max_pages' => (int) ($this->option('max-pages') ?: config('foodfacts.max_pages')),
            'cutoff' => $full ? null : IngestionRun::lastCompletedWatermark(),
            'next_page' => 1,
        ]);

        FetchProductsPage::dispatch($run->id, 1);

        $this->info(sprintf(
            'Queued run #%d (%s, up to %d pages%s) on queue [%s].',
            $run->id,
            $run->mode,
            $run->max_pages,
            $run->cutoff ? ', stops at changes older than '.date('Y-m-d H:i', $run->cutoff) : '',
            config('foodfacts.queue'),
        ));

        return self::SUCCESS;
    }
}
