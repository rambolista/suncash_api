<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Support\MenuPermissions;

class MenuController extends Controller
{
    /**
     * GET /api/access-management/menus
     * Return all menus ordered by sort_order (flat list).
     */
    public function index(Request $request): JsonResponse
    {
        $allMenus = Menu::with('tabs')->orderBy('sort_order')->orderBy('id')->get();

        if ($request->boolean('all')) {
            if (! $this->userHasPermission($request->user(), '/apps/access-management', 'can_view')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return response()->json($allMenus);
        }

        $visibleIds = collect($this->getAccessibleMenuIds($request->user()))
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
        $menusById = $allMenus->keyBy('id');

        foreach (array_keys($visibleIds) as $menuId) {
            $parentId = $menusById->get($menuId)?->parent_id;

            while ($parentId && ! isset($visibleIds[$parentId])) {
                $visibleIds[$parentId] = true;
                $parentId = $menusById->get($parentId)?->parent_id;
            }
        }

        $menus = $allMenus
            ->filter(fn (Menu $menu) => isset($visibleIds[$menu->id]))
            ->values();

        return response()->json($menus);
    }

    /**
     * POST /api/access-management/menus
     * Create a new menu item.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management', 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'slug'        => ['required', 'string', 'max:120', 'unique:menus,slug'],
            'url'         => ['nullable', 'string', 'max:255'],
            'icon'        => ['nullable', 'string', 'max:60'],
            'parent_id'   => ['nullable', 'integer', 'exists:menus,id'],
            'sort_order'  => ['nullable', 'integer'],
            'is_title'    => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
            'is_disabled' => ['nullable', 'boolean'],
            'is_special'  => ['nullable', 'boolean'],
            'badge_text'  => ['nullable', 'string', 'max:30'],
            'badge_class' => ['nullable', 'string', 'max:100'],
            'tab_layout'  => ['nullable', 'string', 'in:horizontal,vertical'],
            ...$this->capabilityRules(),
            ...$this->tabRules(),
        ]);

        $menu = DB::transaction(function () use ($data): Menu {
            $menu = Menu::create(collect($data)->except('tabs')->all());
            $this->syncTabs($menu, $data['tabs'] ?? []);

            return $menu->load('tabs');
        });
        $this->notifyMenuChange($request, $menu, 'created');

        return response()->json($menu, 201);
    }

    /**
     * PUT /api/access-management/menus/{menu}
     * Update an existing menu item.
     */
    public function update(Request $request, Menu $menu): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'label'       => ['sometimes', 'required', 'string', 'max:100'],
            'slug'        => ['sometimes', 'required', 'string', 'max:120', 'unique:menus,slug,' . $menu->id],
            'url'         => ['nullable', 'string', 'max:255'],
            'icon'        => ['nullable', 'string', 'max:60'],
            'parent_id'   => ['nullable', 'integer', 'exists:menus,id'],
            'sort_order'  => ['nullable', 'integer'],
            'is_title'    => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
            'is_disabled' => ['nullable', 'boolean'],
            'is_special'  => ['nullable', 'boolean'],
            'badge_text'  => ['nullable', 'string', 'max:30'],
            'badge_class' => ['nullable', 'string', 'max:100'],
            'tab_layout'  => ['nullable', 'string', 'in:horizontal,vertical'],
            ...$this->capabilityRules(),
            ...$this->tabRules(),
        ]);

        DB::transaction(function () use ($menu, $data): void {
            $menu->update(collect($data)->except('tabs')->all());
            if (array_key_exists('tabs', $data)) {
                $this->syncTabs($menu, $data['tabs']);
            }
        });
        $menu->load('tabs');
        $this->notifyMenuChange($request, $menu, 'updated');

        return response()->json($menu);
    }

    /**
     * DELETE /api/access-management/menus/{menu}
     * Delete a menu item (children cascade via FK).
     */
    public function destroy(Request $request, Menu $menu): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management', 'can_delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->notifyMenuChange($request, $menu, 'deleted');
        $menu->delete();

        return response()->json(['message' => 'Menu deleted.']);
    }

    private function notifyMenuChange(Request $request, Menu $menu, string $action): void
    {
        $actor = $request->user();
        $title = match ($action) {
            'created' => 'Menu created',
            'deleted' => 'Menu deleted',
            default => 'Menu updated',
        };

        User::query()
            ->where('status', 'active')
            ->eachById(function (User $user) use ($actor, $menu, $action, $title) {
                $user->notify(new SystemNotification(
                    title: $title,
                    message: sprintf('"%s" was %s by %s.', $menu->label, $action, $actor->name),
                    eventType: 'menu.' . $action,
                    icon: 'menu-2',
                    color: $action === 'deleted' ? 'danger' : ($action === 'created' ? 'success' : 'primary'),
                    actionUrl: '/apps/access-management',
                    metadata: ['menu_id' => $menu->id, 'actor_id' => $actor->id],
                ));
            });
    }

    private function capabilityRules(): array
    {
        return collect(MenuPermissions::CAPABILITIES)
            ->mapWithKeys(fn (string $capability) => [$capability => ['nullable', 'boolean']])
            ->all();
    }

    private function tabRules(): array
    {
        return [
            'tabs' => ['nullable', 'array'],
            'tabs.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
            'tabs.*.label' => ['required', 'string', 'max:100'],
            'tabs.*.icon' => ['nullable', 'string', 'max:60'],
            'tabs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'tabs.*.is_active' => ['nullable', 'boolean'],
            ...collect(MenuPermissions::CAPABILITIES)
                ->mapWithKeys(fn (string $capability) => ["tabs.*.{$capability}" => ['nullable', 'boolean']])
                ->all(),
        ];
    }

    private function syncTabs(Menu $menu, array $tabs): void
    {
        $retainedIds = [];

        foreach (array_values($tabs) as $index => $tabData) {
            $tab = $menu->tabs()->updateOrCreate(
                ['key' => $tabData['key']],
                [
                    'label' => $tabData['label'],
                    'icon' => $tabData['icon'] ?? null,
                    'sort_order' => $tabData['sort_order'] ?? $index,
                    'is_active' => $tabData['is_active'] ?? true,
                    ...collect(MenuPermissions::CAPABILITIES)
                        ->mapWithKeys(fn (string $capability) => [
                            $capability => (bool) ($tabData[$capability] ?? in_array($capability, [
                                'supports_view',
                                'supports_add',
                                'supports_edit',
                                'supports_delete',
                            ], true)),
                        ])
                        ->all(),
                ],
            );
            $retainedIds[] = $tab->id;
        }

        $menu->tabs()->whereNotIn('id', $retainedIds)->delete();
    }

}
