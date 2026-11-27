<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Northdeck: SSO connections, the identities they sign in, and group to
// role mappings. See docs/sso.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('northdeck_sso_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('protocol', 8); // oidc, saml
            $table->string('preset', 32);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_instance')->default(false);
            $table->json('settings');
            $table->text('client_secret')->nullable();
            $table->text('sp_private_key')->nullable();
            $table->text('sp_certificate')->nullable();
            $table->boolean('jit')->default(true);
            $table->string('default_role', 16)->default('member');
            $table->timestamp('last_tested_at')->nullable();
            $table->json('last_test')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'enabled']);
            $table->index('is_instance');
        });

        Schema::create('northdeck_sso_connection_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('northdeck_sso_connections')->cascadeOnDelete();
            $table->foreignId('workspace_email_domain_id')->constrained('workspace_email_domains')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'workspace_email_domain_id'], 'northdeck_sso_connection_domains_unique');
        });

        Schema::create('northdeck_sso_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('northdeck_sso_connections')->cascadeOnDelete();
            $table->string('subject', 255);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255)->nullable();
            $table->timestamp('last_sign_in_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'subject']);
            $table->index('user_id');
        });

        Schema::create('northdeck_sso_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('northdeck_sso_connections')->cascadeOnDelete();
            $table->string('group', 255);
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['connection_id', 'group']);
        });

        // The panel's Workspace model knows this column, but upstream only
        // creates it from its paid package. Add it when it isn't there.
        if (! Schema::hasColumn('workspaces', 'require_sso')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->boolean('require_sso')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('northdeck_sso_group_mappings');
        Schema::dropIfExists('northdeck_sso_identities');
        Schema::dropIfExists('northdeck_sso_connection_domains');
        Schema::dropIfExists('northdeck_sso_connections');
    }
};
