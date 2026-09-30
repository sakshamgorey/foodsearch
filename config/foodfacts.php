<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open Food Facts API
    |--------------------------------------------------------------------------
    |
    | OFF allows 10 search requests / minute / IP and asks every client to
    | identify itself with "AppName/Version (contact)". Going over the limit
    | can get the server IP banned, so the pipeline stays below it.
    |
    */

    'base_url' => env('OFF_BASE_URL', 'https://world.openfoodfacts.org'),

    'user_agent' => env('OFF_USER_AGENT', 'FoodSearch/1.0 (you@example.com)'),

    'timeout' => (int) env('OFF_TIMEOUT', 30),

    'fields' => ['code', 'product_name', 'brands', 'categories', 'image_url', 'last_modified_t'],

    'page_size' => (int) env('OFF_PAGE_SIZE', 100),

    // Upper bound per run. 250 pages x 100 = 25,000 products ≈ 31 minutes at 8 req/min.
    // Incremental runs usually stop long before this; it mostly bounds --full runs.
    'max_pages' => (int) env('OFF_MAX_PAGES', 250),

    // Keep a safety margin under OFF's 10 req/min search limit.
    'requests_per_minute' => (int) env('OFF_REQUESTS_PER_MINUTE', 8),

    // Newest-modified first, so a weekly run can stop once it reaches
    // products it already ingested last week (see FetchProductsPage).
    'sort_by' => env('OFF_SORT_BY', 'last_modified_t'),

    // Extra search filters, e.g. ['countries_tags_en' => 'india'].
    'filters' => array_filter([
        'countries_tags_en' => env('OFF_COUNTRY'),
    ]),

    'queue' => env('OFF_QUEUE', 'ingestion'),

    // A run with no progress for this long is treated as dead.
    'stall_after_minutes' => (int) env('OFF_STALL_AFTER_MINUTES', 120),

];
