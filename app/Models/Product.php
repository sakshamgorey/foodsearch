<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * With the pgsql engine the keys here are *column names*, not a document.
     * They must match the columns passed to $table->searchable() in the
     * migration; the engine uses them to decide which trigram columns apply.
     */
    public function toSearchableArray(): array
    {
        return [
            'code' => $this->code,
            'product_name' => $this->product_name,
            'brands' => $this->brands,
            'categories' => $this->categories,
        ];
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
