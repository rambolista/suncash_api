<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\ClientBusinessCategory;
use App\Models\Mysuncash\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Billers Setup" — legacy `tools/business_billers`. Flags which
 * already-approved Merchant/Supplier/Biller accounts with a billpay
 * application show up as a "biller" elsewhere (Bills Payment, etc.).
 */
class BillersSetupService
{
    /** `clients.merchant_type_id` values legacy's business_billers query allows — Merchant::ENTITY_TYPES 1/2/3. */
    private const ELIGIBLE_MERCHANT_TYPES = [1, 2, 3];

    private function eligibleQuery()
    {
        return Merchant::query()
            ->where('client_status_id', 0)
            ->where('registration_status', 'A')
            ->whereIn('merchant_type_id', self::ELIGIBLE_MERCHANT_TYPES)
            ->whereHas('billpayApplication');
    }

    public function list(): array
    {
        $merchants = $this->eligibleQuery()
            ->with('billpayApplication:id,client_id,service_categories')
            ->orderBy('suntag_shortcode')
            ->get(['id', 'suntag_shortcode', 'dba_name', 'trade_name', 'profile_pic', 'is_biller']);

        // Legacy's grouping join (`cba.service_categories = cbc.id`) relies on MySQL's implicit
        // string-to-int coercion to read only the first id out of what can be a comma-separated
        // list — replicated here explicitly rather than trusting that coercion again.
        $firstCategoryId = fn (?string $raw) => (int) (explode(',', (string) $raw)[0] ?? 0) ?: null;

        $categoryIds = $merchants->pluck('billpayApplication.service_categories')
            ->map($firstCategoryId)
            ->filter()
            ->unique();

        $categoryNames = ClientBusinessCategory::whereIn('id', $categoryIds)->pluck('name', 'id');

        $groups = [];
        foreach ($merchants as $merchant) {
            $categoryId = $firstCategoryId($merchant->billpayApplication?->service_categories);
            $categoryName = $categoryId ? ($categoryNames->get($categoryId) ?: 'Uncategorized') : 'Uncategorized';

            $groups[$categoryName] ??= ['name' => $categoryName, 'billers' => []];
            $groups[$categoryName]['billers'][] = [
                'id' => $merchant->id,
                'suntag_shortcode' => $merchant->suntag_shortcode,
                'dba_name' => $merchant->trade_name ?: $merchant->dba_name,
                'profile_pic' => $merchant->profile_pic,
                'is_biller' => (bool) $merchant->is_biller,
            ];
        }

        // Alphabetical, with the catch-all group last rather than legacy's incidental NULL-sorts-first order.
        uksort($groups, fn ($a, $b) => $a === 'Uncategorized' ? 1 : ($b === 'Uncategorized' ? -1 : strcasecmp($a, $b)));

        return array_values($groups);
    }

    /**
     * @param  int[]  $selectedIds
     * @return array{selected: int, removed: int}
     */
    public function save(array $selectedIds, ?User $actor = null): array
    {
        $eligibleIds = $this->eligibleQuery()->pluck('id');

        $selected = $eligibleIds->intersect($selectedIds)->values();
        $unselected = $eligibleIds->diff($selected)->values();

        DB::connection('mysuncash')->transaction(function () use ($selected, $unselected) {
            if ($selected->isNotEmpty()) {
                Merchant::whereIn('id', $selected)->update(['is_biller' => 1]);
            }
            if ($unselected->isNotEmpty()) {
                Merchant::whereIn('id', $unselected)->update(['is_biller' => 0]);
            }
        });

        ActivityLog::recordAction($actor, 'Tools', 'updated', "Updated Billers Setup: {$selected->count()} marked as biller, {$unselected->count()} removed.");

        return ['selected' => $selected->count(), 'removed' => $unselected->count()];
    }
}
