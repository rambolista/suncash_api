<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->string('header_sign_in_label')->nullable()->after('description');
            $table->string('header_sign_in_url', 2048)->nullable()->after('header_sign_in_label');
            $table->string('header_sign_up_label')->nullable()->after('header_sign_in_url');
            $table->string('header_sign_up_url', 2048)->nullable()->after('header_sign_up_label');
        });

        DB::table('landing_pages')->update([
            'header_sign_in_label' => 'SIGN IN',
            'header_sign_in_url' => '/auth/sign-in',
            'header_sign_up_label' => 'Sign Up',
            'header_sign_up_url' => '/auth/sign-up',
        ]);
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn([
                'header_sign_in_label',
                'header_sign_in_url',
                'header_sign_up_label',
                'header_sign_up_url',
            ]);
        });
    }
};
