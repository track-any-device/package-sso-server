<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Human-readable label, e.g. "Central marketing", "My (end-user
            // app)", or "{tenant.name}". Surfaced in the Filament admin.
            $table->string('name', 191);

            // central | my | tenant. Drives where the client lives and which
            // env vars (if any) provide its secret on seed.
            $table->string('kind', 16)->index();

            // Set only when kind = tenant. One row per tenant.
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->unique('tenant_id');

            // Public identifier used in the /oauth/authorize?client_id=...
            // redirect from any client. URL-safe random string.
            $table->string('client_id', 64)->unique();

            // bcrypt() of the plain secret. The plain value is shown once at
            // creation (Filament UI / seeder output / env) and then discarded
            // server-side. Same model as Stripe / GitHub app secrets.
            $table->string('client_secret_hash');

            // Whitelist of exact redirect URIs the client may use as the
            // OAuth2 redirect_uri parameter. Tenants are auto-populated with
            // ['https://{slug}.{parent}/sso/callback']; central + my get
            // theirs from env on seed.
            $table->json('redirect_uris');

            // For back-channel single sign-out — login.* will POST a signed
            // logout event here when the user's identity session ends.
            // Null for clients that don't participate in back-channel SSO.
            $table->string('logout_webhook_url')->nullable();

            // Soft kill-switch — set false to suspend a client without
            // deleting its history.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
