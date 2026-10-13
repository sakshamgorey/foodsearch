# Food Search

Laravel 13 app that ingests Open Food Facts products every week through queued jobs and serves search through **Laravel Scout's `database` engine with native PostgreSQL full-text search**, from the unmerged [laravel/scout#1016](https://github.com/laravel/scout/pull/1016) (branch `feat/database-pgsql-search`).

```
schedule (Sun 02:13 IST)
  └─ foodfacts:ingest ──creates──> ingestion_runs row (cursor, cutoff)
        └─ dispatch FetchProductsPage(run, 1)            queue: ingestion
              ├─ RateLimited('openfoodfacts')  8 req/min, global
              ├─ GET /api/v2/search?page=N&sort_by=last_modified_t
              ├─ BEGIN
              │    upsert products ON CONFLICT (code)       ← Postgres recomputes search_vector
              │    ingestion_runs.next_page = N+1           ← cursor moves with the data
              │  COMMIT
              └─ stop? (exhausted | caught_up | max_pages) else dispatch page N+1

GET /?q=…  →  Product::search($q)  →  WHERE search_vector @@ websearch_to_tsquery(…)
                                      ORDER BY ts_rank(…) DESC, id DESC
                                      0 hits? re-run with OR product_name % …   (pg_trgm, typos)
```

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# Postgres ≥ 12 (generated columns). pg_trgm must be creatable by the DB user (it's a trusted extension on PG 13+).
php artisan migrate

php artisan queue:work --queue=ingestion,default   # in production: Supervisor/Horizon
php artisan schedule:work                         # in production: cron `* * * * * php artisan schedule:run`

npm install && npm run build                       # or `npm run dev` while working on the UI

php artisan foodfacts:ingest --max-pages=5        # try it now
php artisan foodfacts:status
php artisan serve                                  # http://localhost:8000
```

Set `OFF_USER_AGENT` to your app name and a real contact email. OFF asks for it and may block anonymous-looking traffic.

### Installing the unmerged Scout PR

`composer.json` requires `"laravel/scout": "dev-feat/database-pgsql-search"`. The PR branch lives on `laravel/scout` itself, so Packagist already exposes it as a `dev-` version and you need no custom repository. **Pin the commit** so an upstream force-push can't change your code under you:

```json
"laravel/scout": "dev-feat/database-pgsql-search#0e6677fb798192eb02f60c9fda9855f0889c36b7"
```

When the PR merges, switch to the tagged release (`^11.x`). The API may change before then, because the PR is still in review.

PR #1016 replaces the earlier draft [#998](https://github.com/laravel/scout/pull/998). It adds no new driver. The built-in `database` engine learns Postgres full-text search, and the config lives in two places:

```php
// migration: generated, weighted tsvector + GIN index, plus pg_trgm and a trigram index
$table->searchable(['code' => 'A', 'product_name' => 'A', 'brands' => 'B', 'categories' => 'C'], 'english', trigram: ['product_name']);

// model: search the stored vector, websearch syntax, trigram as a fallback
#[SearchUsingFullText(['search_vector'], ['vector' => true, 'mode' => 'websearch', 'trigram' => ['product_name']])]
public function toSearchableArray(): array
{
    return ['search_vector' => null];
}
```

Any option can be overridden per query, for example `Product::search($q)->options(['trigram_mode' => 'blend'])` or `->options(['trigram' => null])`.

## Frontend

Inertia + React + TypeScript, styled with **shadcn/ui** (new-york style, Tailwind v4). This is the same stack as Laravel's React starter kit.

- `resources/js/pages/products/search.tsx` is the search page. It has live search (a 300 ms debounce into partial Inertia visits that reload only `q` and `products`), `/` to focus the box, Esc to clear, suggestion chips, empty states and shadcn pagination.
- `resources/js/components/ui/*` holds the shadcn components (button, input, card, badge, separator, pagination). `components.json` is set up, so `npx shadcn@latest add dialog` works as it does in any shadcn project.
- Theme tokens are in `resources/css/app.css`. The base is neutral with a green `--primary`, and dark mode follows the OS.
- The controller sends only what the page renders (`present()`). `lastRun` and `total` are lazy props, so typing in the box doesn't re-count the table.

## Demo

This fixed-size dark-mode GIF shows a chocolate search with product photos from Open Food Facts.

![Food Search demo](docs/screenshots/search-demo.gif)

## Commands

| command | what it does |
|---|---|
| `foodfacts:ingest` | Starts an incremental run, or does nothing if one is active. It resumes a stalled run from its cursor. |
| `foodfacts:ingest --full` | Ignores the watermark and pages up to `max_pages`. |
| `foodfacts:ingest --max-pages=N` | Sets a per-run page cap. |
| `foodfacts:ingest --resume-only` | Runs hourly. It only revives stalled runs and never starts a new one. |
| `foodfacts:status` | Shows the last runs, their stop reasons and errors, and the index size. |

## Design decisions

**Why a self-dispatching chain rather than `Bus::batch()`?** OFF allows 10 search requests per minute per IP, so parallel page jobs gain nothing. An incremental run also doesn't know its page count up front. It stops when it reaches products older than last week's watermark, and a batch would have to pre-create jobs for pages it never needs. The chain keeps its cursor in Postgres, so any failure resumes from the exact page.

**Why is the cursor updated in the same transaction as the upsert?** It gives exactly-once effects on top of at-least-once delivery. If a worker dies after the commit, the redelivered job sees `next_page != page` and exits. If it dies before the commit, nothing was written and the page is fetched again. The upsert is idempotent on `code` either way.

**Why `retryUntil()` instead of `$tries`?** The `RateLimited` middleware *releases* jobs, and each release counts as an attempt. With `$tries = 3`, a healthy job that waited for its rate-limit slot three times would be marked failed. Wall-clock time (6h) and `$maxExceptions = 5` bound real failures instead.

**Why doesn't the HTTP client retry?** The queue already retries with backoff (30s, 2m, 5m, 10m). Retrying in both layers multiplies calls against a 10/min budget.

**How errors are classified:**
- A 429 releases the job for `Retry-After` seconds and doesn't count as an exception.
- A 5xx, a timeout, or an HTML maintenance page served with a 200 throws `RemoteUnavailable`. That error is transient and the job is retried.
- Any other 4xx throws `InvalidResponse`. The job fails immediately and the run is marked failed.

**Why doesn't Scout's `queue` setting matter here?** The database engine's `update()` and `delete()` are no-ops, because it queries the table directly. `search_vector` is a `GENERATED ALWAYS … STORED` column, so Postgres recomputes it on every write, including `Product::upsert()`, which fires no Eloquent events. With Algolia or Meilisearch, bulk upserts would silently skip indexing. Here they can't. The tradeoff is that the vector is recomputed on every write and you can't index derived data from other tables.

**Why are the weights A/A/B/C?** The barcode and name are weighted A, brands B and categories C. A search for "chocolate" therefore ranks *Dark Chocolate 70%* above biscuits that are only *categorised* as chocolate.

## Known limits

- **OFF's search API is not a bulk export.** OFF asks clients that need more than a few hundred products to use the daily JSONL/CSV dumps. At 8 req/min × 100 products, one page cap of 50 pages takes about 6 minutes and covers 5,000 products. The full catalogue (3M+ products) would take days. Use the API for a scoped slice (`OFF_COUNTRY`, a category filter) or for weekly deltas. For the whole database, change the job to stream the JSONL dump in chunks: the upsert path and the search stay the same.
- The incremental cutoff assumes `sort_by=last_modified_t` returns products newest-first. If OFF changes that ordering, runs fall back to `max_pages` and stay correct, just slower.
- **Typo matching is a fallback, and it only works on short names.** The trigram query runs only when full-text search returns nothing, so a query with one typo in an otherwise-matching phrase gets no fuzzy help. It uses `product_name % ?`, which compares the query with the *whole* name. `nutela` → *Nutella* matches, but `biscit` doesn't match *Parle-G Glucose Biscuits*, because the similarity against a long string stays under 0.3. You'd need `word_similarity` (`<%`) for per-word fuzziness, and the PR doesn't use it yet.
- **`blend` mode ignores websearch operators.** In the default `fallback` mode, `ferrero -nutella` returns only Kinder Bueno. With `trigram_mode => blend`, the trigram predicate is OR'ed into every query with the raw string, so Nutella comes back. `ProductSearchTest` pins both behaviours, so you'll notice if the PR changes them.
- Products deleted on OFF are never removed here. A future `deleted_at` sweep could compare `last_run_id` after a `--full` run.
- The trigram threshold is Postgres's `pg_trgm.similarity_threshold` (default 0.3). The PR has no option for it.
- With trigram configured, the engine checks once per connection that `pg_trgm` is installed and throws a `RuntimeException` if it isn't. The migration creates the extension, so the DB user needs permission to do that (it's a trusted extension on PG 13+).
- `onOneServer()` and the rate limiter require a shared cache store (database or Redis), not `file` or `array`.

## Tests

The tests run against a real Postgres database (`foodsearch_test`, set in `phpunit.xml`), because tsvector, pg_trgm and generated columns can't be faked in SQLite.

```bash
createdb foodsearch_test && php artisan test     # 25 tests: pipeline + search
```
