<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    protected $fillable = [
        'code', 'product_name', 'brands', 'categories', 'image_url', 'off_modified_at', 'last_run_id',
    ];

    // Never select the tsvector into PHP.
    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return ['off_modified_at' => 'datetime'];
    }

    /**
     * With the database engine the keys here are *column names*, not a document.
     * Return only the stored tsvector: any other key adds an ILIKE clause that
     * stops Postgres from using the GIN index. The attribute tells the engine to
     * match it with `search_vector @@ websearch_to_tsquery(...)`, and to retry
     * with `product_name % ?` (pg_trgm) only when full-text finds nothing.
     */
    #[SearchUsingFullText(['search_vector'], ['vector' => true, 'mode' => 'websearch', 'trigram' => ['product_name']])]
    public function toSearchableArray(): array
    {
        return ['search_vector' => null];
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class, 'last_run_id');
    }

    public function offUrl(): string
    {
        return rtrim(config('foodfacts.base_url'), '/').'/product/'.$this->code;
    }

    public function categoryList(int $limit = 3): array
    {
        return array_slice(array_filter(array_map('trim', explode(',', (string) $this->categories))), 0, $limit);
    }
}
