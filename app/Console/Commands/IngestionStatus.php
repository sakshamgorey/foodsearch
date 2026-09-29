<?php

namespace App\Console\Commands;

use App\Models\IngestionRun;
use App\Models\Product;
use Illuminate\Console\Command;

class IngestionStatus extends Command
{
    protected $signature = 'foodfacts:status {--limit=10}';

    protected $description = 'Show recent Open Food Facts ingestion runs';

    public function handle(): int
    {
        $runs = IngestionRun::latest('id')->limit((int) $this->option('limit'))->get();

        $this->table(
            ['#', 'status', 'mode', 'pages', 'products', 'skipped', 'stop', 'started', 'finished', 'error'],
            $runs->map(fn (IngestionRun $r) => [
                $r->id,
                $r->status.($r->isStalled() ? ' (stalled)' : ''),
                $r->mode,
                "{$r->pages_fetched}/{$r->max_pages}",
                $r->products_seen,
                $r->products_skipped,
                $r->stop_reason,
                $r->started_at?->toDateTimeString(),
                $r->finished_at?->toDateTimeString(),
                str($r->error)->limit(60),
            ]),
        );

        $this->line('Products in index: '.number_format(Product::count()));

        return self::SUCCESS;
    }
}
