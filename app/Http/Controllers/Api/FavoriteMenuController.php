<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteMenuController extends Controller
{
    /**
     * GET /api/favorites — ids of the current user's favorited menus.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->favoriteMenus()->pluck('menus.id')
        );
    }

    /**
     * POST /api/favorites/{menu}/toggle — no permission gate: a user can only
     * ever land on this route for a menu they can already reach/view.
     */
    public function toggle(Request $request, Menu $menu): JsonResponse
    {
        $user = $request->user();
        $isFavorite = $user->favoriteMenus()->where('menus.id', $menu->id)->exists();

        $isFavorite
            ? $user->favoriteMenus()->detach($menu->id)
            : $user->favoriteMenus()->attach($menu->id);

        return response()->json(['is_favorite' => ! $isFavorite]);
    }
}
