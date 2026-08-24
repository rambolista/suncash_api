<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restructure the Access Management sidebar entry so it becomes a parent
 * with three children: Menus, Roles, Users.
 *
 * Before: single link  pages:apps-access-management  → /apps/access-management
 * After:  parent       pages:apps-access-management  (no URL, has children)
 *           Menus       → /apps/access-management
 *           Roles       → /apps/access-management/roles
 *           Users       → /apps/access-management/users
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Turn the existing row into a parent (clear its URL)
        DB::table('menus')
            ->where('slug', 'pages:apps-access-management')
            ->update(['url' => null]);

        // Grab the parent ID
        $parentId = DB::table('menus')
            ->where('slug', 'pages:apps-access-management')
            ->value('id');

        if (!$parentId) {
            return; // safety guard — parent not found
        }

        // 2. Determine base sort_order from existing children (if any re-run)
        $baseOrder = DB::table('menus')
            ->where('parent_id', $parentId)
            ->max('sort_order') ?? 0;

        // 3. Insert the three child menu items (skip if already present)
        $children = [
            [
                'label'      => 'Menus',
                'slug'       => 'pages:apps-access-management-menus',
                'url'        => '/apps/access-management',
                'icon'       => 'menu-2',
                'sort_order' => $baseOrder + 1,
                'parent_id'  => $parentId,
                'is_title'   => false,
                'is_active'  => true,
            ],
            [
                'label'      => 'Roles',
                'slug'       => 'pages:apps-access-management-roles',
                'url'        => '/apps/access-management/roles',
                'icon'       => 'shield-check',
                'sort_order' => $baseOrder + 2,
                'parent_id'  => $parentId,
                'is_title'   => false,
                'is_active'  => true,
            ],
            [
                'label'      => 'Users',
                'slug'       => 'pages:apps-access-management-users',
                'url'        => '/apps/access-management/users',
                'icon'       => 'users',
                'sort_order' => $baseOrder + 3,
                'parent_id'  => $parentId,
                'is_title'   => false,
                'is_active'  => true,
            ],
        ];

        foreach ($children as $child) {
            $exists = DB::table('menus')->where('slug', $child['slug'])->exists();
            if (!$exists) {
                DB::table('menus')->insert(array_merge($child, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        // Restore the original single link
        DB::table('menus')
            ->where('slug', 'pages:apps-access-management')
            ->update(['url' => '/apps/access-management']);

        // Remove the child entries
        DB::table('menus')->whereIn('slug', [
            'pages:apps-access-management-menus',
            'pages:apps-access-management-roles',
            'pages:apps-access-management-users',
        ])->delete();
    }
};
