<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('item_id')->constrained('sp_items');
            $table->enum('adjustment_type', ['add', 'remove']);
            $table->decimal('quantity', 14, 4);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('sp_users');
            $table->timestamps();
        });

        Schema::create('sp_stock_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('item_id')->constrained('sp_items');
            $table->enum('warning_type', ['low_stock'])->default('low_stock');
            $table->enum('status', ['active', 'cleared', 'dismissed'])->default('active');
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();

            $table->index(['item_id', 'status']);
        });

        Schema::create('sp_asset_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->timestamp('captured_at')->useCurrent();
            $table->decimal('total_value', 16, 2);
            $table->json('per_category')->nullable();

            $table->index(['captured_at']);
        });

        Schema::create('sp_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('sp_organizations');
            $table->foreignId('user_id')->nullable()->constrained('sp_users');
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('sp_settings', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->string('key');
            $table->json('value')->nullable();

            $table->primary(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_settings');
        Schema::dropIfExists('sp_audit_log');
        Schema::dropIfExists('sp_asset_snapshots');
        Schema::dropIfExists('sp_stock_warnings');
        Schema::dropIfExists('sp_stock_adjustments');
    }
};
