<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending')->index();
            $table->string('mode', 20)->default('incremental'); // incremental | full

            // Cursor: the next page to fetch. Advanced in the same transaction
            // as the upsert, which makes page jobs safe to retry.
            $table->unsignedInteger('next_page')->default(1);
            $table->unsignedInteger('max_pages');

            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('products_seen')->default(0);
            $table->unsignedInteger('products_skipped')->default(0);

            // Newest OFF last_modified_t seen in this run (becomes next run's cutoff).
            $table->unsignedBigInteger('watermark')->nullable();
            // Previous completed run's watermark: stop paging once we pass it.
            $table->unsignedBigInteger('cutoff')->nullable();

            $table->string('stop_reason')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
