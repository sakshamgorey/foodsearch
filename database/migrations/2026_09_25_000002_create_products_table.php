<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // barcode — the upsert key
            $table->text('product_name')->nullable();
            $table->text('brands')->nullable();
            $table->text('categories')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('off_modified_at')->nullable()->index();
            $table->foreignId('last_run_id')->nullable()->constrained('ingestion_runs')->nullOnDelete();
            $table->timestamps();

            // From laravel/scout PR #998. Adds:
            //   search_vector tsvector GENERATED ALWAYS AS (
            //     setweight(to_tsvector('english', coalesce(product_name,'')), 'A') || ...
            //   ) STORED
            //   + a GIN index on search_vector
            //   + a GIN gin_trgm_ops index on product_name (typo tolerance)
            //
            // Because the vector is a generated column, Postgres keeps it in sync
            // on every INSERT/UPDATE — including bulk upserts that never fire
            // Eloquent events. Scout's indexing step becomes a no-op.
            $table->searchable(['code', 'product_name', 'brands', 'categories'], [
                'weights' => [
                    'code' => 'A',
                    'product_name' => 'A',
                    'brands' => 'B',
                    'categories' => 'C',
                ],
                'trigram' => [
                    'columns' => ['product_name'],
                    'create_extension' => true,
                ],
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
