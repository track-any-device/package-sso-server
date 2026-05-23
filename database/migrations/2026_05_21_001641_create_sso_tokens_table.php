<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_tokens', function (Blueprint $table): void {
            $table->id();

            // sha256() of the plain token. The token itself is never stored
            // in cleartext — it lives in the redirect URL once, and from
            // that point on we only know its hash.
            $table->string('token_hash', 64)->unique();

            // Who the token authenticates. Cascade delete on user removal
            // so we don't keep orphan tokens.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Which client the token was minted for. A token is only valid
            // when consumed by the exact client it was issued to.
            $table->foreignUuid('oauth_client_id')
                ->constrained('oauth_clients')
                ->cascadeOnDelete();

            // OAuth2 `state` echoed back to the client on callback so the
            // client can match the response to the request it made (CSRF
            // guard at the client end).
            $table->string('state', 191)->nullable();

            $table->ipAddress('issued_to_ip')->nullable();
            $table->ipAddress('consumed_from_ip')->nullable();

            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();

            // Set the moment the token is exchanged. NULL = unused. We
            // never delete consumed rows — they're the audit trail of
            // "who logged into which client when."
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            // Common lookup: "is this hash the next valid token for this
            // client?" — we want it indexed together for the consume path.
            $table->index(['oauth_client_id', 'consumed_at']);
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_tokens');
    }
};
