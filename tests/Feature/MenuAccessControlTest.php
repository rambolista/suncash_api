<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_capabilities_and_customer_tabs_are_seeded(): void
    {
        $customerMenu = Menu::where('slug', 'pages:apps-customers')->firstOrFail();

        $this->assertTrue($customerMenu->supports_view);
        $this->assertTrue($customerMenu->supports_add);
        $this->assertTrue($customerMenu->supports_edit);
        $this->assertTrue($customerMenu->supports_delete);
        $this->assertFalse($customerMenu->supports_approve);
        $this->assertFalse($customerMenu->supports_export);
        $this->assertSame('horizontal', $customerMenu->tab_layout);
        $this->assertSame(13, $customerMenu->tabs()->count());
        $this->assertTrue($customerMenu->tabs()->first()->supports_view);
        $this->assertTrue($customerMenu->tabs()->first()->supports_add);
        $this->assertFalse($customerMenu->tabs()->first()->supports_approve);
        $this->assertSame(
            ['overview', 'information', 'accounts', 'service-connections', 'meters'],
            $customerMenu->tabs()->limit(5)->pluck('key')->all(),
        );
        $this->assertSame(
            'Personal/Business Info',
            $customerMenu->tabs()->where('key', 'information')->value('label'),
        );
    }

    public function test_role_permissions_only_save_actions_supported_by_the_menu(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Billing Reviewer']);
        $customerMenu = Menu::where('slug', 'pages:apps-customers')->firstOrFail();
        $overviewTab = $customerMenu->tabs()->where('key', 'overview')->firstOrFail();
        $this->grantRoleManagement($user);

        $payload = [
            'permissions' => [[
                'menu_id' => $customerMenu->id,
                'can_view' => true,
                'can_add' => false,
                'can_edit' => true,
                'can_delete' => false,
                'can_approve' => true,
                'can_execute' => true,
                'can_cancel' => true,
                'can_reverse' => true,
                'can_export' => true,
                'can_print' => true,
            ]],
            'tab_permissions' => [[
                'tab_id' => $overviewTab->id,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_export' => true,
            ]],
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/access-management/roles/{$role->id}/menu-permissions", $payload)
            ->assertOk();

        $this->assertDatabaseHas('role_menu_permissions', [
            'role_id' => $role->id,
            'menu_id' => $customerMenu->id,
            'can_view' => true,
            'can_edit' => true,
            'can_approve' => false,
            'can_execute' => false,
            'can_export' => false,
            'can_print' => false,
        ]);
        $this->assertDatabaseHas('role_menu_tab_permissions', [
            'role_id' => $role->id,
            'menu_tab_id' => $overviewTab->id,
            'can_view' => true,
            'can_add' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_export' => false,
        ]);

        $customerMenu->update(['supports_export' => true]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/access-management/roles/{$role->id}/menu-permissions", $payload)
            ->assertOk();

        $this->assertDatabaseHas('role_menu_permissions', [
            'role_id' => $role->id,
            'menu_id' => $customerMenu->id,
            'can_export' => true,
        ]);

        $overviewTab->update(['supports_export' => true]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/access-management/roles/{$role->id}/menu-permissions", $payload)
            ->assertOk();
        $this->assertDatabaseHas('role_menu_tab_permissions', [
            'role_id' => $role->id,
            'menu_tab_id' => $overviewTab->id,
            'can_export' => true,
        ]);
    }

    public function test_role_and_auth_payloads_include_capabilities_and_tab_visibility(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Customer Support']);
        $customerMenu = Menu::where('slug', 'pages:apps-customers')->firstOrFail();
        $overviewTab = $customerMenu->tabs()->where('key', 'overview')->firstOrFail();

        $this->grantRoleManagement($user);
        $role->users()->attach($user);
        $role->menuPermissions()->attach($customerMenu->id, [
            'can_view' => true,
            'can_add' => false,
            'can_edit' => false,
            'can_delete' => false,
        ]);
        $role->menuTabPermissions()->attach($overviewTab->id, [
            'can_view' => true,
            'can_add' => true,
            'can_edit' => false,
            'can_delete' => false,
        ]);

        $permissions = $this->actingAs($user, 'sanctum')
            ->getJson("/api/access-management/roles/{$role->id}/menu-permissions")
            ->assertOk()
            ->json();

        $customerPermission = collect($permissions)->firstWhere('menu_id', $customerMenu->id);
        $this->assertTrue($customerPermission['supports_view']);
        $this->assertSame('horizontal', $customerPermission['tab_layout']);
        $this->assertFalse($customerPermission['supports_approve']);
        $this->assertTrue(collect($customerPermission['tabs'])->firstWhere('key', 'overview')['can_view']);
        $this->assertTrue(collect($customerPermission['tabs'])->firstWhere('key', 'overview')['can_add']);

        $authPayload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/user')
            ->assertOk()
            ->json('user.menu_permissions');

        $customerAuthPermission = collect($authPayload)->firstWhere('menu_id', $customerMenu->id);
        $this->assertTrue($customerAuthPermission['can_view']);
        $this->assertSame('horizontal', $customerAuthPermission['tab_layout']);
        $this->assertFalse($customerAuthPermission['can_export']);
        $this->assertSame('overview', $customerAuthPermission['tabs'][0]['key']);
        $this->assertTrue($customerAuthPermission['tabs'][0]['can_view']);
        $this->assertTrue($customerAuthPermission['tabs'][0]['can_add']);
    }

    public function test_inactive_tabs_are_hidden_from_roles_and_authenticated_users(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Active Tab Viewer']);
        $this->grantRoleManagement($user);
        $customerMenu = Menu::where('slug', 'pages:apps-customers')->firstOrFail();
        $overviewTab = $customerMenu->tabs()->where('key', 'overview')->firstOrFail();
        $overviewTab->update(['is_active' => false]);

        $role->users()->attach($user);
        $role->menuPermissions()->attach($customerMenu->id, [
            'can_view' => true,
            'can_add' => false,
            'can_edit' => false,
            'can_delete' => false,
        ]);
        $role->menuTabPermissions()->attach($overviewTab->id, ['can_view' => true]);

        $roleMenus = $this->actingAs($user, 'sanctum')
            ->getJson("/api/access-management/roles/{$role->id}/menu-permissions")
            ->assertOk()
            ->json();
        $customerRoleMenu = collect($roleMenus)->firstWhere('menu_id', $customerMenu->id);
        $this->assertNull(collect($customerRoleMenu['tabs'])->firstWhere('key', 'overview'));

        $authMenus = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/user')
            ->assertOk()
            ->json('user.menu_permissions');
        $customerAuthMenu = collect($authMenus)->firstWhere('menu_id', $customerMenu->id);
        $this->assertNull(collect($customerAuthMenu['tabs'])->firstWhere('key', 'overview'));
    }

    public function test_customer_show_requires_view_permission(): void
    {
        $user = User::factory()->create();
        $customer = \App\Models\Customer::factory()->create();
        $role = Role::create(['name' => 'Customer Viewer']);
        $customerMenu = Menu::where('slug', 'pages:apps-customers')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/access-management/customers/{$customer->id}")
            ->assertForbidden();

        $role->users()->attach($user);
        DB::table('role_menu_permissions')->insert([
            'role_id' => $role->id,
            'menu_id' => $customerMenu->id,
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson("/api/access-management/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('id', $customer->id);
    }

    public function test_role_permission_matrix_requires_role_management_access(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Restricted Role']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/access-management/roles/{$role->id}/menu-permissions")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/access-management/roles/{$role->id}/menu-permissions", ['permissions' => []])
            ->assertForbidden();
    }

    private function grantRoleManagement(User $user): void
    {
        $managementRole = Role::create(['name' => 'Role Manager ' . $user->id]);
        $rolesMenu = Menu::where('url', '/apps/access-management/roles')->firstOrFail();

        $managementRole->users()->attach($user);
        $managementRole->menuPermissions()->attach($rolesMenu->id, [
            'can_view' => true,
            'can_add' => false,
            'can_edit' => true,
            'can_delete' => false,
        ]);
    }
}
