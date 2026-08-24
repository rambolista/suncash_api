<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MenuPermissions;

abstract class Controller
{
    protected function buildMenuPermissionsPayload(object $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $user->loadMissing('roles');

        $permissions = $user->roles->flatMap(function ($role) {
            $tabPermissions = $role->menuTabPermissions()->get()->keyBy('id');

            return $role->menuPermissions()->with('tabs')->get()->map(function ($menu) use ($tabPermissions) {
                $payload = [
                    'menu_id' => $menu->id,
                    'slug' => $menu->slug,
                    'label' => $menu->label,
                    'url' => $menu->url,
                    'is_title' => (bool) $menu->is_title,
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
                            ];
                            $pivot = $tabPermissions->get($tab->id)?->pivot;

                            foreach (MenuPermissions::ACTIONS as $action) {
                                $payload[$action] = $tab->supportsAction($action)
                                    && (bool) ($pivot->{$action} ?? false);
                            }

                            return $payload;
                        })
                        ->values()
                        ->all(),
                ];

                foreach (MenuPermissions::ACTIONS as $action) {
                    $payload[$action] = $menu->supportsAction($action)
                        && (bool) ($menu->pivot->{$action} ?? false);
                }

                return $payload;
            });
        });

        $merged = [];
        foreach ($permissions as $permission) {
            $menuId = $permission['menu_id'];
            if (! array_key_exists($menuId, $merged)) {
                $merged[$menuId] = $permission;
                continue;
            }

            foreach (MenuPermissions::ACTIONS as $action) {
                $merged[$menuId][$action] = $merged[$menuId][$action] || $permission[$action];
            }

            $tabs = collect([...$merged[$menuId]['tabs'], ...$permission['tabs']])
                ->groupBy('tab_id')
                ->map(function ($entries) {
                    $first = $entries->first();
                    foreach (MenuPermissions::ACTIONS as $action) {
                        $first[$action] = $entries->contains(fn ($entry) => $entry[$action]);
                    }

                    return $first;
                })
                ->sortBy('sort_order')
                ->values()
                ->all();
            $merged[$menuId]['tabs'] = $tabs;
        }

        return array_values($merged);
    }

    protected function getAccessibleMenuIds(object $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return collect($this->buildMenuPermissionsPayload($user))
            ->filter(fn ($menu) => ! empty($menu['can_view']))
            ->pluck('menu_id')
            ->unique()
            ->values()
            ->all();
    }

    protected function userHasPermission(object $user, string $routePath, string $action): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $normalizedRoutePath = $this->normalizeRoute($routePath);

        $permission = collect($this->buildMenuPermissionsPayload($user))->first(function ($menu) use ($normalizedRoutePath) {
            $menuUrl = $this->normalizeRoute($menu['url'] ?? '');
            $menuSlug = $this->normalizeRoute($menu['slug'] ?? '');
            $segments = explode('/', trim($normalizedRoutePath, '/'));
            $lastSegment = $this->normalizeRoute($normalizedRoutePath === '/' ? '' : ($segments[count($segments) - 1] ?? ''));

            return $menuUrl === $normalizedRoutePath
                || $menuSlug === $normalizedRoutePath
                || $menuSlug === $lastSegment
                || $menuUrl === $lastSegment;
        });

        return (bool) ($permission[$action] ?? false);
    }

    protected function normalizeRoute(?string $routePath): string
    {
        if ($routePath === null) {
            return '/';
        }

        $trimmed = trim((string) $routePath);
        if ($trimmed === '') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
