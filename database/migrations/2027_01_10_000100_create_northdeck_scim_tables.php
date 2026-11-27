<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Northdeck: SCIM 2.0 provisioning (preview, NORTHDECK_SCIM). See docs/sso.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('northdeck_scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('northdeck_scim_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('user_name', 255);
            $table->string('external_id', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'user_name']);
        });

        Schema::create('northdeck_scim_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 255);
            $table->string('external_id', 255)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'display_name']);
        });

        Schema::create('northdeck_scim_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('northdeck_scim_groups')->cascadeOnDelete();
            $table->foreignId('scim_user_id')->constrained('northdeck_scim_users')->cascadeOnDelete();

            $table->unique(['group_id', 'scim_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('northdeck_scim_group_members');
        Schema::dropIfExists('northdeck_scim_groups');
        Schema::dropIfExists('northdeck_scim_users');
        Schema::dropIfExists('northdeck_scim_tokens');
    }
};
