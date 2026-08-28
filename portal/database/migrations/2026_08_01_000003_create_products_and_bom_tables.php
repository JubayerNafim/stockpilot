<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('store_id')->nullable()->constrained('sp_store_connections');
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->enum('source', ['woo', 'manual'])->default('manual');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('product_type', 32)->default('simple');
            $table->string('publish_status', 32)->default('publish');
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('regular_price', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 4)->nullable();
            $table->string('stock_status', 32)->nullable();
            $table->decimal('stock_quantity', 14, 4)->nullable();
            $table->json('categories')->nullable();       // [{id, name}]
            $table->string('image_url')->nullable();
            $table->json('attributes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['store_id', 'woo_product_id']);
            $table->index(['organization_id', 'store_id']);
        });

        Schema::create('sp_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('product_id')->constrained('sp_products')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('sp_items');
            $table->decimal('quantity', 14, 4);
            $table->timestamps();

            $table->unique(['product_id', 'item_id']);
            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_bom_lines');
        Schema::dropIfExists('sp_products');
    }
};
