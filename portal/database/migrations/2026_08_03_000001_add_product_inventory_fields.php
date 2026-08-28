<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Finished-goods tracking per product (manually maintained in the portal).
        Schema::table('sp_products', function (Blueprint $table) {
            $table->decimal('cost', 14, 4)->nullable()->after('price');
            $table->decimal('quantity_on_hand', 14, 4)->default(0)->after('cost');
            $table->decimal('low_stock_threshold', 14, 4)->default(0)->after('quantity_on_hand');
        });

        // Low-stock warnings can now reference an item OR a product.
        Schema::table('sp_stock_warnings', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();
            $table->foreignId('product_id')->nullable()->after('item_id')->constrained('sp_products');
        });
    }

    public function down(): void
    {
        Schema::table('sp_stock_warnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('sp_products', function (Blueprint $table) {
            $table->dropColumn(['cost', 'quantity_on_hand', 'low_stock_threshold']);
        });
    }
};
