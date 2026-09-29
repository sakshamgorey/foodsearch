<?php

namespace App\Services\OpenFoodFacts;

use Illuminate\Support\Carbon;

/**
 * Turns raw OFF rows into rows for products upsert.
 * OFF data is crowd-sourced: expect missing names, whitespace-only fields,
 * numeric barcodes, and the same code appearing twice across pages.
 */
class ProductNormalizer
{
    /** @return array<string, array> keyed by code (dedupes within a page) */
    public function normalize(array $rawProducts, int $runId): array
    {
        $now = now();
        $rows = [];

        foreach ($rawProducts as $raw) {
            $code = $this->clean($raw['code'] ?? null);

            // Barcodes are digits; OFF has a few junk codes. Skip rather than fail the page.
            if ($code === null || ! preg_match('/^\d{4,32}$/', $code)) {
                continue;
            }

            $rows[$code] = [
                'code' => $code,
                'product_name' => $this->clean($raw['product_name'] ?? null),
                'brands' => $this->clean($raw['brands'] ?? null),
                'categories' => $this->clean($raw['categories'] ?? null),
                'image_url' => $this->cleanUrl($raw['image_url'] ?? null),
                'off_modified_at' => isset($raw['last_modified_t']) && is_numeric($raw['last_modified_t'])
                    ? Carbon::createFromTimestamp((int) $raw['last_modified_t'])
                    : null,
                'last_run_id' => $runId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function cleanUrl(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value !== null && str_starts_with($value, 'https://') ? $value : null;
    }
}
