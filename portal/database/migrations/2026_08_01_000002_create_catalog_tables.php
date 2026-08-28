<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('sp_categories');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('sp_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('category_id')->nullable()->constrained('sp_categories');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit', 32)->default('pcs');
            $table->decimal('cost_price', 14, 4)->default(0);
            $table->decimal('quantity_on_hand', 14, 4)->default(0);
            $table->decimal('quantity_reserved', 14, 4)->default(0);
            $table->decimal('low_stock_threshold', 14, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_items');
        Schema::dropIfExists('sp_categories');
    }
};
