<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->string('name');
            $table->string('store_url');
            $table->string('api_key_hash')->unique();      // SHA-256 of the api key (identifier)
            $table->text('api_secret_encrypted');          // AES-256-CBC, needed to verify HMAC
            $table->string('plugin_version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedBigInteger('sync_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_store_connections');
    }
};
