<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NORTHDECK: updates to a new commit of Northdeck's repository, asked for on
// /admin/updates or by auto-update, and carried out by the server's updater.
// See App\Northdeck\Updates\SourceUpdates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('northdeck_updates', function (Blueprint $table) {
            $table->id();
            $table->string('commit', 40);
            $table->string('from_commit', 40)->nullable();
            $table->string('status', 16)->default('requested');
            $table->boolean('auto')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('northdeck_updates');
    }
};
