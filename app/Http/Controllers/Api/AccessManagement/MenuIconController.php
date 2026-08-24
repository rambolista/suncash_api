<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\MenuIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuIconController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $limit = (int) ($data['limit'] ?? 50);

        $icons = MenuIcon::query()
            ->when($search !== '', function ($query) use ($search) {
                $query
                    ->where('name', 'like', $search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name']);

        return response()->json($icons);
    }
}
