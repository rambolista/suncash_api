<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `is_hidden` is a DB-only flag with no UI toggle: set it directly on a row to pull that
 * menu out of the Access Management > Menus listing and out of the Role > Menu Permissions
 * checkbox tree, without touching whatever access an existing role already has to it (the
 * logged-in user's own sidebar is unaffected — it's driven by role_menu_permissions, not
 * this list). Defaults to false so every menu stays exposed as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_special');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
