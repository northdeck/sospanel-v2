<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NORTHDECK: people an instance admin invited from /admin/users. An invite
// lets that email make an account whatever the sign-up rules say. See
// App\Northdeck\Admin\SignUpPolicy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('northdeck_panel_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('northdeck_panel_invites');
    }
};
