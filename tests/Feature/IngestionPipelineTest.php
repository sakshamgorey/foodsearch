<?php

namespace Tests\Feature;

use App\Jobs\FetchProductsPage;
use App\Models\IngestionRun;
use App\Models\Product;
use App\Services\OpenFoodFacts\InvalidResponse;
use App\Services\OpenFoodFacts\OpenFoodFactsClient;
use App\Services\OpenFoodFacts\ProductNormalizer;
use App\Services\OpenFoodFacts\RemoteUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'foodfacts.page_size' => 2,
            'foodfacts.requests_per_minute' => 1000,
            'foodfacts.max_pages' => 50,
        ]);
    }

    private ?array $offCatalog = null;

    /**
     * Fake OFF: $catalog is sorted newest-modified first, served page_size per page.
     * Http::fake() stubs stack (first match wins), so register once and swap the data.
     */
    private function fakeOff(array $catalog): void
    {
        $registered = $this->offCatalog !== null;
        $this->offCatalog = $catalog;

        if ($registered) {
            return;
        }

        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
            $size = (int) $q['page_size'];
            $slice = array_slice($this->offCatalog, ((int) $q['page'] - 1) * $size, $size);

            return Http::response(['count' => count($this->offCatalog), 'page' => (int) $q['page'], 'products' => $slice]);
        });
    }

    private function product(string $code, string $name, int $modified, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'product_name' => $name,
            'brands' => 'Brand '.$code,
            'categories' => 'Snacks, Sweet snacks',
            'image_url' => "https://images.openfoodfacts.org/{$code}.jpg",
            'last_modified_t' => $modified,
        ], $extra);
    }

    private function catalog(): array
    {
        return [
            $this->product('3017620422003', 'Nutella', 1_700_000_500),
            $this->product('5449000000996', 'Coca-Cola', 1_700_000_400),
            $this->product('7622210449283', 'Oreo', 1_700_000_300),
            $this->product('8000500310427', 'Kinder Bueno', 1_700_000_200),
            $this->product('3228857000166', 'Pain de mie', 1_700_000_100),
        ];
    }

    public function test_full_run_pages_until_exhausted(): void
    {
        $this->fakeOff($this->catalog());

        $this->artisan('foodfacts:ingest')->assertSuccessful();

        $run = IngestionRun::sole();
        $this->assertSame(IngestionRun::COMPLETED, $run->status);
        $this->assertSame('exhausted', $run->stop_reason);
        $this->assertSame(3, $run->pages_fetched);
        $this->assertSame(5, $run->products_seen);
        $this->assertSame(1_700_000_500, (int) $run->watermark);
        $this->assertSame(5, Product::count());

        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', config('foodfacts.user_agent'))
            && str_contains($r->url(), 'fields=code%2Cproduct_name')
            && str_contains($r->url(), 'sort_by=last_modified_t'));
    }

    public function test_rerun_upserts_instead_of_duplicating(): void
    {
        $this->fakeOff($this->catalog());
        $this->artisan('foodfacts:ingest');

        $changed = $this->catalog();
        $changed[0]['product_name'] = 'Nutella 750g';
        $this->fakeOff($changed);
        $this->artisan('foodfacts:ingest --full');

        $this->assertSame(5, Product::count());
        $this->assertSame('Nutella 750g', Product::where('code', '3017620422003')->value('product_name'));
        $this->assertSame(IngestionRun::latest('id')->value('id'), (int) Product::where('code', '3017620422003')->value('last_run_id'));
    }

    public function test_incremental_run_stops_once_it_reaches_last_watermark(): void
    {
        $this->fakeOff($this->catalog());
        $this->artisan('foodfacts:ingest'); // watermark = 1_700_000_500

        // Two products changed since; the rest are old news.
        $this->fakeOff(array_merge([
            $this->product('4000000000001', 'New Granola', 1_700_000_900),
            $this->product('4000000000002', 'New Muesli', 1_700_000_800),
            $this->product('4000000000003', 'New Oats', 1_700_000_700),
        ], $this->catalog()));

        $this->artisan('foodfacts:ingest')->assertSuccessful();

        $run = IngestionRun::latest('id')->first();
        $this->assertSame('incremental', $run->mode);
        $this->assertSame(1_700_000_500, (int) $run->cutoff);
        $this->assertSame('caught_up', $run->stop_reason);
        $this->assertSame(2, $run->pages_fetched);            // page 2 contains Nutella (== cutoff) → stop
        $this->assertSame(1_700_000_900, (int) $run->watermark);
        $this->assertSame(8, Product::count());
        Http::assertSentCount(3 + 2);                         // run 1: 3 pages, run 2: 2 pages
    }

    public function test_max_pages_bounds_a_run(): void
    {
        $this->fakeOff($this->catalog());

        $this->artisan('foodfacts:ingest --max-pages=1');

        $run = IngestionRun::sole();
        $this->assertSame('max_pages', $run->stop_reason);
        $this->assertSame(2, Product::count());
    }

    public function test_junk_rows_are_skipped_and_duplicates_collapsed(): void
    {
        $this->fakeOff([
            $this->product('3017620422003', '  Nutella  ', 10, ['brands' => '   ', 'image_url' => 'http://insecure/x.jpg']),
            $this->product('not-a-barcode', 'Junk', 9),
            $this->product('', 'No code', 8),
            $this->product('3017620422003', 'Nutella dup', 7),
        ]);

        $this->artisan('foodfacts:ingest');

        $p = Product::sole();
        $this->assertSame('Nutella dup', $p->product_name); // last write within a page wins
        $this->assertSame(2, IngestionRun::sole()->products_skipped);

        $rows = (new ProductNormalizer)->normalize([$this->product('1234', '  A   b ', 1, ['brands' => ' ', 'image_url' => 'http://x'])], 1);
        $this->assertSame('A b', $rows['1234']['product_name']);
        $this->assertNull($rows['1234']['brands']);
        $this->assertNull($rows['1234']['image_url']);
    }

    public function test_429_releases_the_job_for_retry_after(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '45'])]);
        $run = $this->pendingRun();

        $job = (new FetchProductsPage($run->id, 1))->withFakeQueueInteractions();
        $job->handle(app(OpenFoodFactsClient::class), new ProductNormalizer);

        $job->assertReleased(delay: 45);
        $this->assertSame(1, $run->fresh()->next_page);
    }

    public function test_client_error_fails_the_job_without_retrying(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad field'], 400)]);
        $run = $this->pendingRun();

        $job = (new FetchProductsPage($run->id, 1))->withFakeQueueInteractions();
        $job->handle(app(OpenFoodFactsClient::class), new ProductNormalizer);

        $job->assertFailedWith(InvalidResponse::class);
    }

    public function test_server_errors_and_html_pages_are_transient(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('<html>maintenance</html>', 200, ['Content-Type' => 'text/html'])
            ->push('oops', 502)]);

        $client = app(OpenFoodFactsClient::class);

        foreach ([1, 2] as $_) {
            try {
                $client->searchPage(1);
                $this->fail('expected RemoteUnavailable');
            } catch (RemoteUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_failed_job_marks_the_run_failed(): void
    {
        $run = $this->pendingRun();

        (new FetchProductsPage($run->id, 3))->failed(new RemoteUnavailable('HTTP 503'));

        $this->assertSame(IngestionRun::FAILED, $run->fresh()->status);
        $this->assertStringContainsString('Page 3: HTTP 503', $run->fresh()->error);
    }

    public function test_duplicate_delivery_of_a_committed_page_is_a_noop(): void
    {
        Http::fake();
        $run = $this->pendingRun(['next_page' => 4, 'status' => IngestionRun::RUNNING]);

        (new FetchProductsPage($run->id, 3))->handle(app(OpenFoodFactsClient::class), new ProductNormalizer);

        Http::assertNothingSent();
        $this->assertSame(4, $run->fresh()->next_page);
    }

    public function test_command_does_not_start_a_second_run_while_one_is_active(): void
    {
        Queue::fake();
        $this->pendingRun(['status' => IngestionRun::RUNNING, 'last_progress_at' => now()]);

        $this->artisan('foodfacts:ingest')->expectsOutputToContain('still in progress');

        Queue::assertNothingPushed();
        $this->assertSame(1, IngestionRun::count());
    }

    public function test_command_resumes_a_stalled_run_from_its_cursor(): void
    {
        Queue::fake();
        $run = $this->pendingRun([
            'status' => IngestionRun::RUNNING, 'next_page' => 7, 'last_progress_at' => now()->subHours(5),
        ]);

        $this->artisan('foodfacts:ingest --resume-only')->expectsOutputToContain('resumed at page 7');

        Queue::assertPushed(FetchProductsPage::class, fn ($job) => $job->runId === $run->id && $job->page === 7);
        Queue::assertPushedOn('ingestion', FetchProductsPage::class);
        $this->assertSame(1, IngestionRun::count());
    }

    public function test_resume_only_never_starts_a_new_run(): void
    {
        Queue::fake();

        $this->artisan('foodfacts:ingest --resume-only')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, IngestionRun::count());
    }

    private function pendingRun(array $attributes = []): IngestionRun
    {
        return IngestionRun::create(array_merge([
            'status' => IngestionRun::PENDING, 'mode' => 'full', 'max_pages' => 10, 'next_page' => 1,
        ], $attributes));
    }
}
