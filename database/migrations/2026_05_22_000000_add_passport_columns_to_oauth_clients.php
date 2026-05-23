<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `oauth_clients` compatible with Laravel Passport's Client model.
 *
 * Passport expects: user_id, secret, personal_access_client, password_client,
 * revoked. Our existing table stores client secrets in `client_secret_hash`
 * and activity in `is_active`; the OAuthClient model provides virtual
 * accessors that bridge both schemas without duplicating data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            // Passport requires these columns on the clients table.
            // user_id: owner for personal-access clients; null for SSO clients.
            $table->unsignedBigInteger('user_id')->nullable()->after('id');

            // personal_access_client / password_client: Passport grant-type flags.
            // All our SSO clients use the authorization-code grant, so both default false.
            $table->boolean('personal_access_client')->default(false)->after('is_active');
            $table->boolean('password_client')->default(false)->after('personal_access_client');

            // revoked: Passport's soft-delete flag for clients.
            // Our OAuthClient::getRevokedAttribute() derives this from !is_active,
            // but Passport also writes this column directly on revocation.
            $table->boolean('revoked')->default(false)->after('password_client');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'personal_access_client', 'password_client', 'revoked']);
        });
    }
};
