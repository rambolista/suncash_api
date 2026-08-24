<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_tabs', function (Blueprint $table): void {
            $table->boolean('supports_view')->default(true);
            $table->boolean('supports_add')->default(true);
            $table->boolean('supports_edit')->default(true);
            $table->boolean('supports_delete')->default(true);
            $table->boolean('supports_approve')->default(false);
            $table->boolean('supports_execute')->default(false);
            $table->boolean('supports_cancel')->default(false);
            $table->boolean('supports_reverse')->default(false);
            $table->boolean('supports_export')->default(false);
            $table->boolean('supports_print')->default(false);
        });

        Schema::table('role_menu_tab_permissions', function (Blueprint $table): void {
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->boolean('can_execute')->default(false);
            $table->boolean('can_cancel')->default(false);
            $table->boolean('can_reverse')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_print')->default(false);
        });

        DB::table('role_menu_tab_permissions')
            ->where('can_view', true)
            ->update([
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('role_menu_tab_permissions', function (Blueprint $table): void {
            $table->dropColumn([
                'can_add',
                'can_edit',
                'can_delete',
                'can_approve',
                'can_execute',
                'can_cancel',
                'can_reverse',
                'can_export',
                'can_print',
            ]);
        });

        Schema::table('menu_tabs', function (Blueprint $table): void {
            $table->dropColumn([
                'supports_view',
                'supports_add',
                'supports_edit',
                'supports_delete',
                'supports_approve',
                'supports_execute',
                'supports_cancel',
                'supports_reverse',
                'supports_export',
                'supports_print',
            ]);
        });
    }
};
