<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\MenuPermissions;

class RoleController extends Controller
{
    /** GET /access-management/roles */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->orderBy('name')->get();

        return response()->json($roles);
    }

    /** POST /access-management/roles */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management/roles', 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:80', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'key_responsibilities' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:120'],
            'user_ids'    => ['nullable', 'array'],
            'user_ids.*'  => ['integer', 'exists:users,id'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'key_responsibilities' => $data['key_responsibilities'] ?? null,
            'icon' => $this->normalizeRoleIcon($data['icon'] ?? null),
        ]);

        if (array_key_exists('user_ids', $data)) {
            $role->users()->sync($data['user_ids'] ?? []);
        }
        $role->loadCount('users');

        return response()->json($role, 201);
    }

    /** PUT /access-management/roles/{role} */
    public function update(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management/roles', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:80', 'unique:roles,name,' . $role->id],
            'description' => ['nullable', 'string', 'max:255'],
            'key_responsibilities' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:120'],
            'user_ids'    => ['nullable', 'array'],
            'user_ids.*'  => ['integer', 'exists:users,id'],
        ]);

        $role->update([
            'name' => $data['name'] ?? $role->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
            'key_responsibilities' => array_key_exists('key_responsibilities', $data) ? $data['key_responsibilities'] : $role->key_responsibilities,
            'icon' => array_key_exists('icon', $data) ? $this->normalizeRoleIcon($data['icon']) : $role->icon,
        ]);

        if (array_key_exists('user_ids', $data)) {
            $role->users()->sync($data['user_ids'] ?? []);
        }
        $role->loadCount('users');

        return response()->json($role);
    }

    /** DELETE /access-management/roles/{role} */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management/roles', 'can_delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * GET /access-management/roles/{role}/menu-permissions
     *
     * Returns every menu with the current permission flags for this role.
     * Menus without an explicit record default to all-false.
     */
    public function menuPermissions(Request $request, Role $role): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/access-management/roles', 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Build a quick lookup: menu_id => pivot row
        $existing = $role->menuPermissions()
            ->get()
            ->keyBy('id');   // keyed by menu_id

        $tabPermissions = $role->menuTabPermissions()->get()->keyBy('id');

        $menus = Menu::with('tabs')->orderBy('sort_order')->orderBy('id')->get()->map(function ($menu) use ($existing, $tabPermissions) {
            $pivot = $existing->get($menu->id)?->pivot;

            $payload = [
                'menu_id'    => $menu->id,
                'label'      => $menu->label,
                'slug'       => $menu->slug,
                'is_title'   => $menu->is_title,
                'parent_id'  => $menu->parent_id,
                'tab_layout' => $menu->tab_layout,
                'tabs' => $menu->tabs
                    ->filter(fn ($tab) => $tab->is_active)
                    ->map(function ($tab) use ($tabPermissions) {
                    $payload = [
                        'tab_id' => $tab->id,
                        'key' => $tab->key,
                        'label' => $tab->label,
                        'icon' => $tab->icon,
                        'sort_order' => $tab->sort_order,
                        'is_active' => $tab->is_active,
                    ];
                    $pivot = $tabPermissions->get($tab->id)?->pivot;

                    foreach (MenuPermissions::ACTIONS as $action) {
                        $capability = MenuPermissions::CAPABILITIES[$action];
                        $payload[$capability] = (bool) $tab->{$capability};
                        $payload[$action] = $payload[$capability] && (bool) ($pivot->{$action} ?? false);
                    }

                    return $payload;
                    })->values(),
            ];

            foreach (MenuPermissions::ACTIONS as $action) {
                $capability = MenuPermissions::CAPABILITIES[$action];
                $payload[$capability] = (bool) $menu->{$capability};
                $payload[$action] = $payload[$capability] && (bool) ($pivot->{$action} ?? false);
            }

            return $payload;
        });

        return response()->json($menus);
    }

    /**
     * POST /access-management/roles/{role}/menu-permissions
     *
     * Payload: { permissions: [{ menu_id, can_view, can_add, can_edit, can_delete }] }
     *
     * Uses sync-with-pivot to replace all existing permission rows.
     */
    public function saveMenuPermissions(Request $request, Role $role): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/access-management/roles', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'permissions'              => ['required', 'array'],
            'permissions.*.menu_id'    => ['required', 'integer', 'exists:menus,id'],
            'permissions.*.can_view'   => ['boolean'],
            'permissions.*.can_add'    => ['boolean'],
            'permissions.*.can_edit'   => ['boolean'],
            'permissions.*.can_delete' => ['boolean'],
            'permissions.*.can_approve' => ['boolean'],
            'permissions.*.can_execute' => ['boolean'],
            'permissions.*.can_cancel' => ['boolean'],
            'permissions.*.can_reverse' => ['boolean'],
            'permissions.*.can_export' => ['boolean'],
            'permissions.*.can_print' => ['boolean'],
            'tab_permissions' => ['nullable', 'array'],
            'tab_permissions.*.tab_id' => ['required', 'integer', 'exists:menu_tabs,id'],
            ...collect(MenuPermissions::ACTIONS)
                ->mapWithKeys(fn (string $action) => ["tab_permissions.*.{$action}" => ['boolean']])
                ->all(),
        ]);

        $menus = Menu::whereIn('id', collect($request->input('permissions'))->pluck('menu_id'))
            ->get()
            ->keyBy('id');
        $sync = [];
        foreach ($request->input('permissions') as $perm) {
            $menu = $menus->get($perm['menu_id']);
            $sync[$perm['menu_id']] = collect(MenuPermissions::ACTIONS)
                ->mapWithKeys(fn (string $action) => [
                    $action => $menu?->supportsAction($action) && (bool) ($perm[$action] ?? false),
                ])
                ->all();
        }

        $role->menuPermissions()->sync($sync);
        $tabs = \App\Models\MenuTab::whereIn(
            'id',
            collect($request->input('tab_permissions', []))->pluck('tab_id'),
        )->get()->keyBy('id');
        $role->menuTabPermissions()->sync(
            collect($request->input('tab_permissions', []))
                ->mapWithKeys(function (array $permission) use ($tabs) {
                    $tab = $tabs->get($permission['tab_id']);

                    return [
                        $permission['tab_id'] => collect(MenuPermissions::ACTIONS)
                            ->mapWithKeys(fn (string $action) => [
                                $action => $tab?->supportsAction($action) && (bool) ($permission[$action] ?? false),
                            ])
                            ->all(),
                    ];
                })
                ->all(),
        );

        return response()->json(['message' => 'Permissions saved.']);
    }

    private function normalizeRoleIcon(mixed $icon): string
    {
        if (! is_string($icon)) {
            return 'shield';
        }

        $trimmed = trim($icon);

        return $trimmed !== '' ? $trimmed : 'shield';
    }
}
