<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('store_id')->constrained('sp_store_connections');
            $table->unsignedBigInteger('woocommerce_order_id')->nullable();
            $table->string('event_id', 64);
            $table->string('nonce', 64);
            $table->string('signature', 128)->nullable();
            $table->enum('event_type', ['order.sync', 'product.sync', 'product.delete', 'health', 'test']);
            $table->string('wc_status', 32)->nullable();
            $table->mediumText('payload');
            $table->enum('status', ['received', 'queued', 'processing', 'processed', 'duplicate', 'noop', 'failed'])->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'nonce']);
            $table->unique(['store_id', 'event_id']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_webhook_events');
    }
};
