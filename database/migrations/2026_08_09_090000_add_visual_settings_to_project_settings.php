<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default((int) date('Y'))->after('author');
            $table->string('sidenav_gradient_start', 7)->default('#1a455f')->after('authentication_type');
            $table->string('sidenav_gradient_end', 7)->default('#262549')->after('sidenav_gradient_start');
            $table->string('topbar_gradient_start', 7)->default('#1a455f')->after('sidenav_gradient_end');
            $table->string('topbar_gradient_end', 7)->default('#262549')->after('topbar_gradient_start');
            $table->string('sidenav_image_path')->nullable()->after('auth_background_path');
        });
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn([
                'year',
                'sidenav_gradient_start',
                'sidenav_gradient_end',
                'topbar_gradient_start',
                'topbar_gradient_end',
                'sidenav_image_path',
            ]);
        });
    }
};
