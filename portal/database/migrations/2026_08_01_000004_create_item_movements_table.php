<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only inventory ledger. Rows are never updated or deleted;
        // corrections are new rows with `reversal_of` pointing at the original.
        Schema::create('sp_item_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('item_id')->constrained('sp_items');
            $table->enum('movement_type', [
                'initial', 'purchase_in', 'adjustment', 'reserve', 'confirm', 'release', 'return', 'sync_fix',
            ]);
            $table->decimal('qty_delta', 14, 4)->default(0);
            $table->decimal('reserved_delta', 14, 4)->default(0);
            $table->decimal('on_hand_after', 14, 4)->default(0);
            $table->decimal('reserved_after', 14, 4)->default(0);
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('reversal_of')->nullable()->constrained('sp_item_movements');
            $table->decimal('cost_price_at_movement', 14, 4)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('sp_users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_item_movements');
    }
};
