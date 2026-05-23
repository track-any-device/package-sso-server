<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Passport token tables that Passport itself would normally create.
 *
 * We call Passport::ignoreMigrations() because our own
 * 2026_05_21_001640_create_oauth_clients_table owns the oauth_clients schema.
 * Passport's other tables (auth codes, access tokens, refresh tokens, device
 * codes) are still required and are created here with client_id columns sized
 * at varchar(100) to accommodate our tci_*_ prefixed client identifiers
 * (which exceed Passport's default char(36) UUID-sized columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->string('client_id', 100);
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('client_id', 100);
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_device_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('client_id', 100)->index();
            $table->char('user_code', 8)->unique();
            $table->text('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
    }
};
