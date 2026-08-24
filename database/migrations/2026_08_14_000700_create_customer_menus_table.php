<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_menus', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('slug')->unique();
            $table->string('url')->nullable();
            $table->string('icon')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('customer_menus')->onDelete('cascade');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_title')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_disabled')->default(false);
            $table->boolean('is_special')->default(false);
            $table->boolean('show_in_customer_page')->default(true);
            $table->string('badge_text')->nullable();
            $table->string('badge_class')->nullable();
            $table->timestamps();
        });

        $now = now();
        $records = [
            ['slug' => 'customer-overview', 'label' => 'Overview', 'url' => '/customer/dashboard', 'icon' => 'brand-windows-filled', 'sort_order' => 0],
            ['slug' => 'customer-view-customer-details', 'label' => 'View Customer Details', 'url' => '/customer/view-customer-details', 'icon' => 'user-filled', 'sort_order' => 1],
            ['slug' => 'customer-my-bills', 'label' => 'My Bills', 'url' => '/customer/my-bills', 'icon' => 'list-filled', 'sort_order' => 2],
            ['slug' => 'customer-payments', 'label' => 'Payments', 'url' => '/customer/payments', 'icon' => 'credit-card-pay', 'sort_order' => 3],
            ['slug' => 'customer-my-portal-account', 'label' => 'My Portal Account', 'url' => '/customer/my-portal-account', 'icon' => 'user-circle', 'sort_order' => 4],
            ['slug' => 'customer-enroll-account', 'label' => 'Enroll Account', 'url' => '/customer/enroll-account', 'icon' => 'user-plus', 'sort_order' => 5],
            ['slug' => 'customer-manage-enrolled-accounts', 'label' => 'Manage Enrolled Accounts', 'url' => '/customer/manage-enrolled-accounts', 'icon' => 'user-cancel', 'sort_order' => 6],
        ];

        foreach ($records as $record) {
            DB::table('customer_menus')->updateOrInsert(
                ['slug' => $record['slug']],
                [
                    'label' => $record['label'],
                    'url' => $record['url'],
                    'icon' => $record['icon'],
                    'parent_id' => null,
                    'sort_order' => $record['sort_order'],
                    'is_title' => false,
                    'is_active' => true,
                    'is_disabled' => false,
                    'is_special' => false,
                    'show_in_customer_page' => true,
                    'badge_text' => null,
                    'badge_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('menus')->updateOrInsert(
            ['slug' => 'pages:apps-access-management-customer-menus'],
            [
                'label' => 'Customer Menus',
                'url' => '/apps/access-management/customer-menus',
                'icon' => 'menu-2',
                'parent_id' => DB::table('menus')->where('slug', 'pages:apps-access-management')->value('id'),
                'sort_order' => 3,
                'is_title' => false,
                'is_active' => true,
                'is_disabled' => false,
                'is_special' => false,
                'badge_text' => null,
                'badge_class' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        $menuId = DB::table('menus')->where('slug', 'pages:apps-access-management-customer-menus')->value('id');

        if ($adminRoleId && $menuId) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role_id' => $adminRoleId, 'menu_id' => $menuId],
                [
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:apps-access-management-customer-menus')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }

        Schema::dropIfExists('customer_menus');
    }
};
