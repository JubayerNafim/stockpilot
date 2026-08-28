<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('store_id')->nullable()->constrained('sp_store_connections');
            $table->unsignedBigInteger('woocommerce_id');
            $table->string('woocommerce_number')->nullable();
            $table->string('status', 32)->default('pending');
            $table->enum('reservation_state', ['none', 'reserved', 'confirmed', 'returned', 'released', 'partial'])->default('none');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('refund_total', 14, 2)->default(0);
            $table->decimal('return_charge', 14, 2)->default(0);
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('order_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('sync_status', 32)->default('pending'); // pending, processed, error, mismatch
            $table->string('sync_hash', 64)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('last_webhook_event_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'woocommerce_id']);
            $table->index(['store_id', 'status']);
            $table->index(['reservation_state']);
        });

        Schema::create('sp_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('sp_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_item_id');
            $table->foreignId('product_id')->nullable()->constrained('sp_products');
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'woocommerce_item_id']);
        });

        // BOM snapshot per order line + live reservation state.
        Schema::create('sp_order_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('order_id')->constrained('sp_orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('sp_order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('sp_products');
            $table->foreignId('item_id')->constrained('sp_items');
            $table->decimal('quantity_per_unit', 14, 4);
            $table->decimal('order_quantity', 14, 4);
            $table->decimal('total_quantity', 14, 4);
            $table->decimal('reserved_quantity', 14, 4)->default(0);
            $table->decimal('confirmed_quantity', 14, 4)->default(0);
            $table->decimal('returned_quantity', 14, 4)->default(0);
            $table->enum('status', ['pending', 'reserved', 'confirmed', 'released', 'returned', 'partial'])->default('pending');
            $table->decimal('charge_amount', 14, 4)->default(0);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'item_id']);
            $table->index(['item_id', 'status']);
        });

        Schema::create('sp_order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('sp_orders')->cascadeOnDelete();
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32);
            $table->string('webhook_event_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_order_status_events');
        Schema::dropIfExists('sp_order_item_components');
        Schema::dropIfExists('sp_order_items');
        Schema::dropIfExists('sp_orders');
    }
};
