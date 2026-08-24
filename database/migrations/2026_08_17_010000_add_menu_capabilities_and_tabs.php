<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
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

        Schema::table('role_menu_permissions', function (Blueprint $table): void {
            $table->boolean('can_approve')->default(false);
            $table->boolean('can_execute')->default(false);
            $table->boolean('can_cancel')->default(false);
            $table->boolean('can_reverse')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_print')->default(false);
        });

        Schema::create('menu_tabs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('label', 100);
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['menu_id', 'key']);
        });

        Schema::create('role_menu_tab_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_tab_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->timestamps();
            $table->unique(['role_id', 'menu_tab_id']);
        });

        $customerMenuId = DB::table('menus')
            ->where('slug', 'pages:apps-customers')
            ->value('id');

        if (! $customerMenuId) {
            return;
        }

        $tabs = [
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'layout-dashboard'],
            ['key' => 'information', 'label' => 'Personal / Business Information', 'icon' => 'address-book'],
            ['key' => 'accounts', 'label' => 'Accounts', 'icon' => 'wallet'],
            ['key' => 'service-connections', 'label' => 'Service Connections', 'icon' => 'plug-connected'],
            ['key' => 'meters', 'label' => 'Meters', 'icon' => 'gauge'],
            ['key' => 'bills', 'label' => 'Bills', 'icon' => 'file-invoice'],
            ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card-pay'],
            ['key' => 'consumption', 'label' => 'Consumption', 'icon' => 'chart-line'],
            ['key' => 'complaints', 'label' => 'Complaints', 'icon' => 'message-report'],
            ['key' => 'service-requests', 'label' => 'Service Requests', 'icon' => 'clipboard-list'],
            ['key' => 'documents', 'label' => 'Documents', 'icon' => 'files'],
            ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell'],
            ['key' => 'audit-history', 'label' => 'Audit History', 'icon' => 'history'],
        ];

        foreach ($tabs as $sortOrder => $tab) {
            DB::table('menu_tabs')->insert([
                'menu_id' => $customerMenuId,
                ...$tab,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tabIds = DB::table('menu_tabs')->where('menu_id', $customerMenuId)->pluck('id');
        $roleIds = DB::table('role_menu_permissions')
            ->where('menu_id', $customerMenuId)
            ->where('can_view', true)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            foreach ($tabIds as $tabId) {
                DB::table('role_menu_tab_permissions')->insert([
                    'role_id' => $roleId,
                    'menu_tab_id' => $tabId,
                    'can_view' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_menu_tab_permissions');
        Schema::dropIfExists('menu_tabs');

        Schema::table('role_menu_permissions', function (Blueprint $table): void {
            $table->dropColumn([
                'can_approve',
                'can_execute',
                'can_cancel',
                'can_reverse',
                'can_export',
                'can_print',
            ]);
        });

        Schema::table('menus', function (Blueprint $table): void {
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
