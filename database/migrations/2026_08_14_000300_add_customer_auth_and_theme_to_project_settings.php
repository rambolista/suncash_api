<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table): void {
            $table->string('customer_authentication_type', 20)->default('basic')->after('authentication_type');
            $table->string('customer_theme', 20)->default('light')->after('customer_authentication_type');
        });
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table): void {
            $table->dropColumn(['customer_authentication_type', 'customer_theme']);
        });
    }
};
