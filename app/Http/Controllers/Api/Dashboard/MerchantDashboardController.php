<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantDashboardController extends Controller
{
    private const MENU_URL = '/dashboard/merchants';

    private const PERIODS = ['all', 'week', 'month', 'year'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->userHasPermission($request->user(), self::MENU_URL, 'can_view'), 403, 'Forbidden.');

        $validated = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
        ]);
        $period = $validated['period'] ?? 'all';
        $periodStart = match ($period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => null,
        };

        $scoped = fn () => $periodStart
            ? Merchant::query()->where('creation_date', '>=', $periodStart)
            : Merchant::query();

        // client_status_id has 4 live values: 0=active, 1=inactive (schema
        // default, unused by any admin action), 2=deactivated via the admin's
        // own deactivate action, and -1=self-registered/never activated by an
        // admin. Only bucketing 0 and 1 left -1 and 2 (54% of all merchants)
        // out of both counts, so the two never summed to the total. Since
        // none of 1/2/-1 can transact, "inactive" is simply "not active".
        $active = (clone $scoped())->where('client_status_id', 0)->count();
        $inactive = (clone $scoped())->where('client_status_id', '!=', 0)->count();
        $approved = (clone $scoped())->where('registration_status', 'A')->count();
        $pending = (clone $scoped())->where(function ($query) {
            $query->whereNull('registration_status')->orWhere('registration_status', '!=', 'A');
        })->count();

        $entityTypeCounts = [];
        foreach (Merchant::ENTITY_TYPES as $id => $label) {
            $entityTypeCounts[$id] = (clone $scoped())->where('reseller_type', (string) $id)->count();
        }

        return response()->json([
            'period' => $period,
            'totals' => [
                'merchants' => (clone $scoped())->count(),
                'active' => $active,
                'inactive' => $inactive,
                'approved' => $approved,
                'pending' => $pending,
            ],
            'status_totals' => [
                'account_status' => ['active' => $active, 'inactive' => $inactive],
                'registration_status' => ['approved' => $approved, 'pending' => $pending],
                'entity_type' => $entityTypeCounts,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
