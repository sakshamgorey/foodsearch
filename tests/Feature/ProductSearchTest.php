<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Scout\EngineManager;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bulk upsert on purpose: no Eloquent events, no Scout observers.
        // search_vector must still be populated by Postgres itself.
        Product::upsert([
            $this->row('3017620422003', 'Nutella', 'Ferrero', 'Spreads, Sweet spreads, Hazelnut spreads'),
            $this->row('8000500310427', 'Kinder Bueno', 'Ferrero, Kinder', 'Snacks, Chocolate bars'),
            $this->row('7622210449283', 'Oreo Original', 'Oreo, Mondelez', 'Snacks, Biscuits and cakes, Chocolate biscuits'),
            $this->row('7613035974685', 'Dark Chocolate 70%', 'Lindt', 'Snacks, Chocolates'),
            $this->row('5449000000996', 'Coca-Cola', 'Coca-Cola', 'Beverages, Sodas'),
            $this->row('3228857000166', 'Pain de mie complet', 'Harrys', 'Breads'),
        ], ['code']);
    }

    private function row(string $code, string $name, string $brands, string $categories): array
    {
        return ['code' => $code, 'product_name' => $name, 'brands' => $brands, 'categories' => $categories,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function names(string $q): array
    {
        return Product::search($q)->get()->pluck('product_name')->all();
    }

    public function test_generated_vector_is_filled_by_postgres_on_bulk_upsert(): void
    {
        $this->assertSame(0, DB::table('products')->whereNull('search_vector')->count());
        $this->assertSame('pgsql', config('scout.driver'));
    }

    public function test_matches_name_brand_category_and_barcode(): void
    {
        $this->assertSame(['Nutella'], $this->names('nutella'));
        $this->assertEqualsCanonicalizing(['Nutella', 'Kinder Bueno'], $this->names('ferrero'));
        $this->assertSame(['Coca-Cola'], $this->names('sodas'));
        $this->assertSame(['Oreo Original'], $this->names('7622210449283'));
    }

    public function test_stemming_matches_word_forms(): void
    {
        // english config: "biscuit" and "Biscuits" both stem to "biscuit".
        $this->assertContains('Oreo Original', $this->names('biscuit'));
    }

    public function test_name_matches_outrank_category_matches(): void
    {
        // 'chocolate' is in Lindt's name (weight A) but only categories (C) for the others.
        $this->assertSame('Dark Chocolate 70%', $this->names('chocolate')[0]);
    }

    public function test_trigram_catches_typos_that_full_text_misses(): void
    {
        $this->assertContains('Nutella', $this->names('nutela'));

        config(['scout.pgsql.trigram.enabled' => false]);
        app(EngineManager::class)->forgetDrivers();

        $this->assertNotContains('Nutella', $this->names('nutela'));
    }

    /**
     * Tradeoff worth knowing: the trigram branch is OR'ed with the tsquery and
     * compares the *raw* query string, so websearch operators like -exclusion
     * only hold when trigram is off.
     */
    public function test_websearch_exclusion_is_undone_by_trigram_or_branch(): void
    {
        $this->assertContains('Nutella', $this->names('ferrero -nutella'));

        config(['scout.pgsql.trigram.enabled' => false]);
        app(EngineManager::class)->forgetDrivers();

        $this->assertSame(['Kinder Bueno'], $this->names('ferrero -nutella'));
    }

    public function test_search_page_renders_results(): void
    {
        $this->get('/?q=nutella')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('products/search')
                ->where('q', 'nutella')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Nutella')
                ->where('products.data.0.categories', ['Spreads', 'Sweet spreads', 'Hazelnut spreads'])
                ->where('products.data.0.url', 'https://world.openfoodfacts.org/product/3017620422003')
                ->where('products.total', 1)
                ->where('total', 6)
                ->where('lastRun', null));
    }

    public function test_blank_and_no_match_queries(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('q', '')
            ->where('products.total', 6)
            ->where('products.data.0.name', 'Pain de mie complet')); // newest id first

        $this->get('/?q=zzzzqqqq')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 0)
            ->where('products.total', 0));
    }

    private function seedSnacks(int $count = 60): void
    {
        Product::upsert(collect(range(1, $count))->map(fn ($i) => $this->row((string) (9000000000000 + $i), "Snack bar {$i}", 'Acme', 'Snacks'))->all(), ['code']);
    }

    private function inertiaHeaders(array $extra = []): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
            ...$extra,
        ];
    }

    public function test_products_is_an_infinite_scroll_prop(): void
    {
        $this->seedSnacks();

        $this->get('/?q=snack')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 24)
            ->where('products.current_page', 1)
            ->where('products.last_page', 3)
            ->where('products.total', 63) // 60 snack bars + 3 products in the Snacks category
            ->missing('products.window')
            ->missing('products.next_url'));

        $this->get('/?q=snack', $this->inertiaHeaders())
            ->assertJsonPath('scrollProps.products', [
                'pageName' => 'page',
                'previousPage' => null,
                'nextPage' => 2,
                'currentPage' => 1,
                'reset' => false,
            ])
            ->assertJsonPath('mergeProps', ['products.data']);
    }

    public function test_scrolling_fetches_the_next_page_for_appending(): void
    {
        $this->seedSnacks();

        $first = $this->get('/?q=snack', $this->inertiaHeaders())->json('props.products.data.*.id');

        $response = $this->get('/?q=snack&page=2', $this->inertiaHeaders([
            'X-Inertia-Partial-Component' => 'products/search',
            'X-Inertia-Partial-Data' => 'products',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'append',
        ]))->assertOk()
            ->assertJsonPath('props.products.current_page', 2)
            ->assertJsonCount(24, 'props.products.data')
            ->assertJsonPath('mergeProps', ['products.data'])
            ->assertJsonPath('scrollProps.products.previousPage', 1)
            ->assertJsonPath('scrollProps.products.nextPage', 3)
            ->assertJsonMissingPath('props.total')
            ->assertJsonMissingPath('props.lastRun');

        $this->assertEmpty(array_intersect($first, $response->json('props.products.data.*.id')));
    }

    public function test_scrolling_through_every_page_never_repeats_a_product(): void
    {
        // All 60 snack bars tie on rank, which is where unstable ordering shows up.
        $this->seedSnacks();

        $ids = [];
        $page = 1;

        while ($page !== null) {
            $response = $this->get("/?q=snack&page={$page}", $this->inertiaHeaders());
            $ids = [...$ids, ...$response->json('props.products.data.*.id')];
            $page = $response->json('scrollProps.products.nextPage');
        }

        $this->assertCount(63, $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_last_page_has_no_next_page(): void
    {
        $this->seedSnacks();

        $this->get('/?q=snack&page=3', $this->inertiaHeaders())
            ->assertJsonCount(15, 'props.products.data')
            ->assertJsonPath('scrollProps.products.previousPage', 2)
            ->assertJsonPath('scrollProps.products.nextPage', null);
    }

    public function test_scrolling_up_asks_the_server_to_prepend(): void
    {
        $this->seedSnacks();

        $this->get('/?q=snack&page=1', $this->inertiaHeaders([
            'X-Inertia-Partial-Component' => 'products/search',
            'X-Inertia-Partial-Data' => 'products',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ]))->assertJsonPath('prependProps', ['products.data'])
            ->assertJsonMissingPath('mergeProps');
    }

    public function test_new_search_resets_the_list_instead_of_merging(): void
    {
        $this->seedSnacks();

        $this->get('/?q=ferrero', $this->inertiaHeaders([
            'X-Inertia-Partial-Component' => 'products/search',
            'X-Inertia-Partial-Data' => 'q,products',
            'X-Inertia-Reset' => 'products',
        ]))->assertOk()
            ->assertJsonPath('props.q', 'ferrero')
            ->assertJsonPath('props.products.total', 2)
            ->assertJsonPath('scrollProps.products.reset', true)
            ->assertJsonMissingPath('mergeProps');
    }

    public function test_live_search_partial_reload_skips_expensive_props(): void
    {
        $this->get('/?q=ferrero', $this->inertiaHeaders([
            'X-Inertia-Partial-Component' => 'products/search',
            'X-Inertia-Partial-Data' => 'q,products',
        ]))->assertOk()
            ->assertJsonPath('props.products.total', 2)
            ->assertJsonMissingPath('props.total')
            ->assertJsonMissingPath('props.lastRun');
    }

    public function test_json_endpoint(): void
    {
        $this->getJson('/api/products?q=ferrero')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['code', 'product_name', 'brands', 'categories', 'image_url']]]);
    }
}
