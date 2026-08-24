<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\CustomerMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerMenu::query()->orderBy('sort_order')->orderBy('id');

        if ($request->boolean('all')) {
            if (! $this->userHasPermission($request->user(), '/apps/access-management/customer-menus', 'can_view')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return response()->json($query->get());
        }

        return response()->json(
            $query->where('is_active', true)->where('show_in_customer_page', true)->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/access-management/customer-menus', 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'unique:customer_menus,slug'],
            'url' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:60'],
            'parent_id' => ['nullable', 'integer', 'exists:customer_menus,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_title' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_disabled' => ['nullable', 'boolean'],
            'is_special' => ['nullable', 'boolean'],
            'show_in_customer_page' => ['nullable', 'boolean'],
            'badge_text' => ['nullable', 'string', 'max:30'],
            'badge_class' => ['nullable', 'string', 'max:100'],
        ]);

        $menu = CustomerMenu::create($data);

        return response()->json($menu, 201);
    }

    public function update(Request $request, CustomerMenu $customerMenu): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/access-management/customer-menus', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'unique:customer_menus,slug,' . $customerMenu->id],
            'url' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:60'],
            'parent_id' => ['nullable', 'integer', 'exists:customer_menus,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_title' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_disabled' => ['nullable', 'boolean'],
            'is_special' => ['nullable', 'boolean'],
            'show_in_customer_page' => ['nullable', 'boolean'],
            'badge_text' => ['nullable', 'string', 'max:30'],
            'badge_class' => ['nullable', 'string', 'max:100'],
        ]);

        $customerMenu->update($data);

        return response()->json($customerMenu);
    }

    public function destroy(Request $request, CustomerMenu $customerMenu): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/access-management/customer-menus', 'can_delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $customerMenu->delete();

        return response()->json(['message' => 'Customer menu deleted.']);
    }
}
