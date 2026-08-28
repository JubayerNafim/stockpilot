<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-level BOM: products become fully stock-tracked and can appear as
 * components in other products' recipes. The ledger (sp_item_movements) and
 * the component snapshot (sp_order_item_components) gain product references.
 *
 * Guarded with hasColumn/hasIndex so a partially-applied re-run (MySQL DDL is
 * auto-committing) never fails with "duplicate column".
 */
return new class extends Migration
{
    public function up(): void
    {
        // sp_products — finished-goods stock can now be reserved like items.
        Schema::table('sp_products', function (Blueprint $table) {
            if (! Schema::hasColumn('sp_products', 'quantity_reserved')) {
                $table->decimal('quantity_reserved', 14, 4)->default(0);
            }

            if (! Schema::hasIndex('sp_products', 'sp_products_org_active_idx')) {
                $table->index(['organization_id', 'is_active'], 'sp_products_org_active_idx');
            }
        });

        // sp_bom_lines — a recipe line can reference an item OR a product.
        Schema::table('sp_bom_lines', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();

            if (! Schema::hasColumn('sp_bom_lines', 'component_type')) {
                $table->string('component_type', 8)->default('item');
            }

            if (! Schema::hasColumn('sp_bom_lines', 'component_product_id')) {
                $table->foreignId('component_product_id')->nullable()->after('item_id')->constrained('sp_products');
            }

            if (! Schema::hasIndex('sp_bom_lines', 'sp_bom_lines_prod_component_unique')) {
                $table->unique(['product_id', 'component_product_id'], 'sp_bom_lines_prod_component_unique');
            }
        });

        // sp_order_item_components — the component snapshot may be an item or a product.
        Schema::table('sp_order_item_components', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();

            if (! Schema::hasColumn('sp_order_item_components', 'component_product_id')) {
                $table->foreignId('component_product_id')->nullable()->after('item_id')->constrained('sp_products');
            }

            if (! Schema::hasIndex('sp_order_item_components', 'sp_oic_order_component_unique')) {
                $table->unique(['order_item_id', 'component_product_id'], 'sp_oic_order_component_unique');
            }
        });

        // sp_item_movements — the ledger now covers products too.
        Schema::table('sp_item_movements', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();

            if (! Schema::hasColumn('sp_item_movements', 'product_id')) {
                $table->foreignId('product_id')->nullable()->after('item_id')->constrained('sp_products');
            }

            if (! Schema::hasIndex('sp_item_movements', 'sp_item_movements_product_idx')) {
                $table->index(['product_id'], 'sp_item_movements_product_idx');
            }
        });

        // sp_order_items — persist the Woo product id for diagnostics / re-matching.
        Schema::table('sp_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sp_order_items', 'woocommerce_product_id')) {
                $table->unsignedBigInteger('woocommerce_product_id')->nullable()->after('woocommerce_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sp_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('sp_order_items', 'woocommerce_product_id')) {
                $table->dropColumn('woocommerce_product_id');
            }
        });

        Schema::table('sp_item_movements', function (Blueprint $table) {
            if (Schema::hasIndex('sp_item_movements', 'sp_item_movements_product_idx')) {
                $table->dropIndex('sp_item_movements_product_idx');
            }
            if (Schema::hasColumn('sp_item_movements', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            }
        });

        Schema::table('sp_order_item_components', function (Blueprint $table) {
            if (Schema::hasIndex('sp_order_item_components', 'sp_oic_order_component_unique')) {
                $table->dropUnique('sp_oic_order_component_unique');
            }
            if (Schema::hasColumn('sp_order_item_components', 'component_product_id')) {
                $table->dropForeign(['component_product_id']);
                $table->dropColumn('component_product_id');
            }
        });

        Schema::table('sp_bom_lines', function (Blueprint $table) {
            if (Schema::hasIndex('sp_bom_lines', 'sp_bom_lines_prod_component_unique')) {
                $table->dropUnique('sp_bom_lines_prod_component_unique');
            }
            if (Schema::hasColumn('sp_bom_lines', 'component_product_id')) {
                $table->dropForeign(['component_product_id']);
                $table->dropColumn('component_product_id');
            }
            if (Schema::hasColumn('sp_bom_lines', 'component_type')) {
                $table->dropColumn('component_type');
            }
        });

        Schema::table('sp_products', function (Blueprint $table) {
            if (Schema::hasIndex('sp_products', 'sp_products_org_active_idx')) {
                $table->dropIndex('sp_products_org_active_idx');
            }
            if (Schema::hasColumn('sp_products', 'quantity_reserved')) {
                $table->dropColumn('quantity_reserved');
            }
        });
    }
};
