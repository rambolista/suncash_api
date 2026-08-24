<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('AdminStarterKit');
            $table->string('author')->default('BYX');
            $table->text('description')->nullable();
            $table->string('authentication_type', 20)->default('basic');
            $table->string('favicon_path')->nullable();
            $table->string('logo_sm_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('logo_light_path')->nullable();
            $table->timestamps();
        });

        DB::table('project_settings')->insert([
            'id' => 1,
            'name' => 'AdminStarterKit',
            'author' => 'BYX',
            'description' => 'AdminStarterKit is a reusable responsive Bootstrap 5 admin foundation for CRM, CMS, project management, and custom web applications.',
            'authentication_type' => 'basic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('project_settings');
    }
};
