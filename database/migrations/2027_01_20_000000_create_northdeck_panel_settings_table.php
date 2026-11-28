<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NORTHDECK: values instance admins set on /admin/panel (the panel's name,
// support and mail addresses). See App\Northdeck\Admin\PanelSettings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('northdeck_panel_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('northdeck_panel_settings');
    }
};
