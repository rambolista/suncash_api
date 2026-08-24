<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('landing_page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('content')->nullable();
            $table->string('primary_link_label')->nullable();
            $table->string('primary_link_url', 2048)->nullable();
            $table->string('secondary_link_label')->nullable();
            $table->string('secondary_link_url', 2048)->nullable();
            $table->string('image_path')->nullable();
            $table->string('background_image_path')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['landing_page_id', 'is_enabled', 'sort_order'], 'landing_sections_public_index');
        });

        Schema::create('landing_page_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_section_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('content')->nullable();
            $table->string('link_label')->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->string('image_path')->nullable();
            $table->string('icon')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['landing_page_section_id', 'is_enabled', 'sort_order'], 'landing_items_public_index');
        });

        Schema::table('project_settings', function (Blueprint $table) {
            $table->foreignId('landing_page_id')
                ->nullable()
                ->after('authentication_type')
                ->constrained('landing_pages')
                ->nullOnDelete();
        });

        $now = now();
        $pageId = DB::table('landing_pages')->insertGetId([
            'name' => 'Default Landing Page',
            'slug' => 'default',
            'description' => 'The default public landing page for AdminStarterKit.',
            'status' => 'published',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('landing_page_sections')->insert([
            'landing_page_id' => $pageId,
            'type' => 'hero',
            'title' => 'AdminStarterKit',
            'subtitle' => 'Build administration experiences faster.',
            'content' => 'A reusable responsive Bootstrap 5 admin foundation.',
            'primary_link_label' => 'Get Started',
            'primary_link_url' => '/login',
            'sort_order' => 0,
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('landing_page_sections')->insert([
            'landing_page_id' => $pageId,
            'type' => 'footer',
            'title' => 'AdminStarterKit',
            'content' => 'Built by BYX.',
            'sort_order' => 100,
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('project_settings')->where('id', 1)->update(['landing_page_id' => $pageId]);
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landing_page_id');
        });

        Schema::dropIfExists('landing_page_section_items');
        Schema::dropIfExists('landing_page_sections');
        Schema::dropIfExists('landing_pages');
    }
};
