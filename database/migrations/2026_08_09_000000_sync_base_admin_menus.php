<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = require __DIR__ . '/data/2026_08_09_base_admin_menus.php';
        $slugs = array_column($items, 'slug');

        DB::table('menus')->whereNotIn('slug', $slugs)->delete();

        foreach ($items as $item) {
            DB::table('menus')->updateOrInsert(
                ['slug' => $item['slug']],
                [
                    'label' => $item['label'],
                    'url' => $item['url'],
                    'icon' => $item['icon'],
                    'parent_id' => null,
                    'sort_order' => $item['sort_order'],
                    'is_title' => $item['is_title'],
                    'is_active' => true,
                    'badge_text' => $item['badge_text'],
                    'badge_class' => $item['badge_class'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($items as $item) {
            $parentId = $item['parent_slug']
                ? DB::table('menus')->where('slug', $item['parent_slug'])->value('id')
                : null;

            DB::table('menus')->where('slug', $item['slug'])->update(['parent_id' => $parentId]);
        }

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');

        if ($adminRoleId) {
            $permissions = DB::table('menus')->pluck('id')->map(fn ($menuId) => [
                'role_id' => $adminRoleId,
                'menu_id' => $menuId,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            DB::table('role_menu_permissions')->upsert(
                $permissions,
                ['role_id', 'menu_id'],
                ['can_view', 'can_add', 'can_edit', 'can_delete', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        $items = require __DIR__ . '/data/2026_08_09_base_admin_menus.php';

        DB::table('menus')->whereIn('slug', array_column($items, 'slug'))->delete();
    }
};
