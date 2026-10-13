<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->assertSame('database', config('scout.driver'));
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

    public function test_trigram_fallback_catches_typos_that_full_text_misses(): void
    {
        $this->assertContains('Nutella', $this->names('nutela'));

        // Per-query override: no trigram, so the typo finds nothing.
        $this->assertSame([], Product::search('nutela')->options(['trigram' => null])->get()->all());
    }

    public function test_trigram_fallback_only_runs_when_full_text_finds_nothing(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->names('nutella');
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, '% ?')));

        $this->names('nutela');
        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, '% ?')));
    }

    /**
     * In the default "fallback" mode, trigram only runs when full-text finds
     * nothing, so websearch operators like -exclusion hold. "blend" ORs the raw
     * string's trigram match into every query, which undoes the exclusion.
     */
    public function test_websearch_exclusion_holds_unless_trigram_is_blended(): void
    {
        $this->assertSame(['Kinder Bueno'], $this->names('ferrero -nutella'));

        $blended = Product::search('ferrero -nutella')->options(['trigram_mode' => 'blend'])->get()->pluck('product_name')->all();

        $this->assertContains('Nutella', $blended);
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

    public function test_pagination_window_keeps_the_query_string(): void
    {
        Product::upsert(collect(range(1, 60))->map(fn ($i) => $this->row((string) (9000000000000 + $i), "Snack bar {$i}", 'Acme', 'Snacks'))->all(), ['code']);

        $this->get('/?q=snack&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('products.current_page', 2)
            ->where('products.last_page', 3)
            ->where('products.from', 25)
            ->has('products.window', 3)
            ->where('products.next_url', fn ($url) => str_contains($url, 'q=snack') && str_contains($url, 'page=3') && ! str_contains($url, 'query=')));
    }

    public function test_live_search_partial_reload_skips_expensive_props(): void
    {
        $this->get('/?q=ferrero', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'products/search',
            'X-Inertia-Partial-Data' => 'q,products',
        ])->assertOk()
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
