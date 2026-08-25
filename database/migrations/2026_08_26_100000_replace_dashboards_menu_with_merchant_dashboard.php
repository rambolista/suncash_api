<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldMenuIds = DB::table('menus')
            ->whereIn('slug', ['dashboards', 'pages:dashboard-analytics', 'pages:dashboard-projects'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $oldMenuIds)->delete();
        DB::table('menus')->whereIn('id', $oldMenuIds)->delete();

        $mainMenuId = DB::table('menus')->where('slug', 'main')->value('id');

        $newMenuId = DB::table('menus')->insertGetId([
            'label' => 'Dashboard',
            'slug' => 'dashboard:merchants',
            'url' => '/dashboard/merchants',
            'icon' => 'layout-dashboard',
            'parent_id' => $mainMenuId,
            'sort_order' => 0,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'created_at' => now(),
            'updated_at' => now(),
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 1,
        ]);

        $roleIds = DB::table('roles')->pluck('id');
        $now = now();
        DB::table('role_menu_permissions')->insert(
            $roleIds->map(fn ($roleId) => [
                'role_id' => $roleId,
                'menu_id' => $newMenuId,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        $newMenuId = DB::table('menus')->where('slug', 'dashboard:merchants')->value('id');

        if ($newMenuId) {
            DB::table('role_menu_permissions')->where('menu_id', $newMenuId)->delete();
            DB::table('menus')->where('id', $newMenuId)->delete();
        }

        $mainMenuId = DB::table('menus')->where('slug', 'main')->value('id');
        $now = now();

        $dashboardsId = DB::table('menus')->insertGetId([
            'label' => 'Dashboards',
            'slug' => 'dashboards',
            'url' => null,
            'icon' => 'dashboard',
            'parent_id' => $mainMenuId,
            'sort_order' => 0,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'created_at' => $now,
            'updated_at' => $now,
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 1,
        ]);

        DB::table('menus')->insert([
            [
                'label' => 'Analytics',
                'slug' => 'pages:dashboard-analytics',
                'url' => '/dashboard/analytics',
                'icon' => null,
                'parent_id' => $dashboardsId,
                'sort_order' => 0,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'created_at' => $now,
                'updated_at' => $now,
                'supports_view' => 1,
                'supports_add' => 1,
                'supports_edit' => 1,
                'supports_delete' => 1,
            ],
            [
                'label' => 'Projects',
                'slug' => 'pages:dashboard-projects',
                'url' => '/dashboard/projects',
                'icon' => null,
                'parent_id' => $dashboardsId,
                'sort_order' => 1,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'created_at' => $now,
                'updated_at' => $now,
                'supports_view' => 1,
                'supports_add' => 1,
                'supports_edit' => 1,
                'supports_delete' => 1,
            ],
        ]);
    }
};
