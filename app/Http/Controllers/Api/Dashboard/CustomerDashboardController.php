<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\CustomerSettlement;
use App\Models\Mysuncash\WuUploadedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The "Customers" panel of the same unified Dashboard view as
 * MerchantDashboardController — gated by the single "Dashboard" menu
 * (/dashboard/merchants) rather than a menu entry of its own, since it's a
 * second tab on that page, not a separate screen.
 */
class CustomerDashboardController extends Controller
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
            ? Customer::query()->where('create_on', '>=', $periodStart)
            : Customer::query();

        // One legacy row has a corrupted status value (a literal "A\r\n"), so
        // bucketing "active" via a prefix match on 'A' rather than a strict
        // equality keeps active + inactive summing to the grand total.
        $active = (clone $scoped())->where('status', 'like', 'A%')->count();
        $inactive = (clone $scoped())->where('status', 'not like', 'A%')->count();
        $verified = (clone $scoped())->where('customer_access', Customer::ACCESS_FULL)->count();
        $pending = (clone $scoped())->where('customer_access', Customer::ACCESS_PENDING)->count();

        $verificationCounts = [];
        foreach ([Customer::ACCESS_QUICKSTART, Customer::ACCESS_PENDING, Customer::ACCESS_FULL, Customer::ACCESS_REJECTED] as $access) {
            $verificationCounts[$access] = (clone $scoped())->where('customer_access', $access)->count();
        }

        $isNew = (clone $scoped())->where('is_new', 1)->count();
        $existing = (clone $scoped())->where('is_new', '!=', 1)->count();

        return response()->json([
            'period' => $period,
            'totals' => [
                'customers' => (clone $scoped())->count(),
                'active' => $active,
                'inactive' => $inactive,
                'verified' => $verified,
                'pending' => $pending,
            ],
            'status_totals' => [
                'account_status' => ['active' => $active, 'inactive' => $inactive],
                'verification_status' => $verificationCounts,
                'customer_type' => ['new' => $isNew, 'existing' => $existing],
            ],
            // Live queue sizes for the other Customers menus — not scoped to
            // the period filter above, since none of these screens have a
            // date filter of their own; they're current queue snapshots, not
            // a registrations-over-time metric.
            'menus' => $this->menuActivity($verificationCounts),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function menuActivity(array $verificationCounts): array
    {
        $cardVerification = fn () => CustomerCreditCard::whereHas('customer');
        $settlements = fn () => CustomerSettlement::where('withdrawal_type', '!=', '');
        $bankLoads = fn () => CustomerSettlement::where('withdrawal_type', '')->where('transaction_type', 'LOAD');

        return [
            'kyc_upgrade' => [
                'pending' => $verificationCounts[Customer::ACCESS_PENDING] ?? 0,
                'approved' => $verificationCounts[Customer::ACCESS_FULL] ?? 0,
                'rejected' => $verificationCounts[Customer::ACCESS_REJECTED] ?? 0,
            ],
            'documents' => [
                'total' => WuUploadedRequest::whereHas('customer')->count(),
            ],
            'card_verification' => [
                'pending' => (clone $cardVerification())->where('is_pending', 1)->where('is_verified', 0)->where('status', 0)
                    ->where('is_blacklisted', '!=', 1)->where('is_rejected', '!=', 1)->count(),
                'approved' => (clone $cardVerification())->where('status', 0)->where('is_verified', 1)->where('is_blacklisted', '!=', 1)->count(),
                'rejected' => (clone $cardVerification())->where('is_rejected', 1)->where('is_blacklisted', '!=', 1)->where('status', 0)->count(),
                'blacklisted' => (clone $cardVerification())->where('is_blacklisted', 1)->count(),
            ],
            'settlements' => [
                'pending' => (clone $settlements())->where('status', CustomerSettlement::STATUS_PENDING)->count(),
                'approved' => (clone $settlements())->where('status', CustomerSettlement::STATUS_PROCESSED)->count(),
                'rejected' => (clone $settlements())->where('status', CustomerSettlement::STATUS_REJECTED)->count(),
            ],
            'bank_loads' => [
                'pending' => (clone $bankLoads())->where('status', CustomerSettlement::STATUS_PENDING)->count(),
                'approved' => (clone $bankLoads())->where('status', CustomerSettlement::STATUS_PROCESSED)->count(),
                'rejected' => (clone $bankLoads())->where('status', CustomerSettlement::STATUS_REJECTED)->count(),
            ],
        ];
    }
}
