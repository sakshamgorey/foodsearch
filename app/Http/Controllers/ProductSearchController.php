<?php

namespace App\Http\Controllers;

use App\Models\IngestionRun;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class ProductSearchController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $this->term($request);

        return Inertia::render('products/search', [
            'q' => $q,
            'products' => fn () => $this->present($this->search($q)->withQueryString()),
            // Not re-sent on live-search keystrokes (the page asks only for q + products).
            'lastRun' => fn () => $this->lastRun(),
            'total' => fn () => Product::count(),
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $page = $this->search($this->term($request));

        return response()->json([
            'data' => collect($page->items())->map->only(['code', 'product_name', 'brands', 'categories', 'image_url']),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    private function term(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }

    private function search(string $q): LengthAwarePaginator
    {
        // Blank query: the pgsql engine orders by id desc (newest ingested first).
        // Otherwise: WHERE search_vector @@ websearch_to_tsquery(...) OR <trigram match>
        //            ORDER BY ts_rank(...) * 1.0 + similarity(...) * 0.25 DESC
        return Product::search($q)
            ->query(fn ($query) => $query->select([
                'id', 'code', 'product_name', 'brands', 'categories', 'image_url', 'off_modified_at',
            ]))
            ->paginate(24)
            // Scout appends ?query=<term> to page links; we already carry ?q=.
            // A null value is dropped by http_build_query.
            ->appends('query', null);
    }

    /** Shape the paginator for the React page: only what it renders. */
    private function present(LengthAwarePaginator $page): array
    {
        $current = $page->currentPage();
        $from = max(1, $current - 2);
        $to = min($page->lastPage(), $current + 2);

        return [
            'data' => $page->getCollection()->map(function (Product $p) {
                $categories = array_values(array_filter(array_map('trim', explode(',', (string) $p->categories))));

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->product_name,
                    'brands' => $p->brands,
                    'categories' => array_slice($categories, 0, 3),
                    'more_categories' => max(0, count($categories) - 3),
                    'image_url' => $p->image_url,
                    'url' => $p->offUrl(),
                ];
            })->all(),
            'current_page' => $current,
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'prev_url' => $page->previousPageUrl(),
            'next_url' => $page->nextPageUrl(),
            'first_url' => $page->url(1),
            'last_url' => $page->url($page->lastPage()),
            'window' => collect($page->getUrlRange($from, $to))
                ->map(fn ($url, $n) => ['page' => $n, 'url' => $url])
                ->values()
                ->all(),
        ];
    }

    private function lastRun(): ?array
    {
        $run = IngestionRun::latest('id')->first();

        return $run ? [
            'status' => $run->status,
            'when' => ($run->finished_at ?? $run->started_at ?? $run->created_at)->diffForHumans(),
            'pages_fetched' => $run->pages_fetched,
            'products_seen' => $run->products_seen,
            'stop_reason' => $run->stop_reason,
        ] : null;
    }
}
