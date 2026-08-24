<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Return the authenticated user.
     *
     * GET /api/auth/user
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Customer) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'account_number' => $user->account_number,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'middle_name' => $user->middle_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'mobile_number' => $user->mobile_number,
                    'address' => $user->address,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status ?? 'active',
                    'theme_preference' => $user->theme_preference,
                    'accessible_menu_ids' => [],
                    'menu_permissions' => [],
                ],
            ]);
        }

        $user->loadMissing('roles');

        return response()->json([
            'user' => [
                ...$user->loadMissing('roles:id,name')->toArray(),
                'accessible_menu_ids' => $this->getAccessibleMenuIds($user),
                'menu_permissions' => $this->buildMenuPermissionsPayload($user),
            ],
        ]);
    }
}
