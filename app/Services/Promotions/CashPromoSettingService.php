<?php

namespace App\Services\Promotions;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CashPromoSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cash-prize inventory CRUD for the active promo — mirrors legacy admin's
 * Settings > Cash Promo Settings tab. Target-group formats (matching live
 * legacy data, `target_group_type` -> `target_group`):
 *   all         -> '' (empty)
 *   island      -> '<island_id>'
 *   multiple    -> '<id1>,<id2>,...'
 *   percentage  -> '<id1>-<pct1>,<id2>-<pct2>,...'
 */
class CashPromoSettingService
{
    private const TARGET_TYPES = ['all', 'island', 'multiple', 'percentage'];

    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    public function list(): array
    {
        return CashPromoSetting::where('promo_type', $this->activePromoType())
            ->where('status', CashPromoSetting::STATUS_ACTIVE)
            ->where('is_process', -1)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    private function validate(array $data): array
    {
        $errors = [];

        $price = $data['price'] ?? null;
        if (! is_numeric($price) || (float) $price <= 0) {
            $errors['price'] = ['Enter a valid prize amount.'];
        }

        $drawType = $data['draw_type'] ?? null;
        if (! in_array($drawType, ['weekly_draw', 'instant_prize'], true)) {
            $errors['draw_type'] = ['Select a valid draw type.'];
        }

        // Legacy only requires quantity for weekly draws — instant prizes may be an open/unlimited pool.
        $quantity = $data['quantity'] ?? null;
        if ($drawType !== 'instant_prize' && (! is_numeric($quantity) || (int) $quantity < 1)) {
            $errors['quantity'] = ['Enter a valid quantity.'];
        }

        if (! filled($data['description'] ?? null)) {
            $errors['description'] = ['Description is required.'];
        }

        $targetGroupType = $data['target_group_type'] ?? 'all';
        if (! in_array($targetGroupType, self::TARGET_TYPES, true)) {
            $errors['target_group_type'] = ['Select a valid target group.'];
        }

        // Legacy hides Multiple/Percentage targeting until a quantity > 1 is entered, since a split needs something to split.
        if (in_array($targetGroupType, ['multiple', 'percentage'], true) && (! is_numeric($quantity) || (int) $quantity <= 1)) {
            $errors['target_group'] = ['Enter a quantity greater than 1 before splitting across islands.'];
        }

        if ($targetGroupType === 'percentage') {
            $allocations = $data['target_group_allocations'] ?? [];
            if (! is_array($allocations) || count($allocations) === 0) {
                $errors['target_group'] = ['Add at least one island allocation.'];
            } else {
                $totalPercentage = array_sum(array_map(fn ($row) => (float) ($row['percentage'] ?? 0), $allocations));
                if ($totalPercentage > 100) {
                    $errors['target_group'] = ['Island percentages cannot exceed 100% in total.'];
                }
            }
        } elseif (in_array($targetGroupType, ['island', 'multiple'], true) && empty($data['target_group_islands'] ?? [])) {
            $errors['target_group'] = ['Select at least one island.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Legacy stores percentage-split allocations as per-island QUANTITY pairs
     * (not the literal percentage), plus a trailing `0-<remainder>` sentinel —
     * see wu_promo_items_management.php's btnAddCashPromo/btnUpdateCashPromo
     * handlers. Downstream prize-draw logic in the legacy stack expects this
     * exact format, so we must encode/decode the same way rather than storing
     * the raw percentage.
     */
    private function encodeTargetGroup(array $data): string
    {
        $type = $data['target_group_type'] ?? 'all';

        if (! in_array($type, ['island', 'multiple', 'percentage'], true)) {
            return '';
        }

        if ($type !== 'percentage') {
            return implode(',', array_map('strval', $data['target_group_islands'] ?? []));
        }

        $quantity = (int) ($data['quantity'] ?? 0);
        $allocations = $data['target_group_allocations'] ?? [];

        $pairs = [];
        $allocatedQuantity = 0;
        foreach ($allocations as $row) {
            $islandQuantity = (int) round(((float) ($row['percentage'] ?? 0)) / 100 * $quantity);
            $allocatedQuantity += $islandQuantity;
            $pairs[] = $row['island_id'].'-'.$islandQuantity;
        }
        $pairs[] = '0-'.($quantity - $allocatedQuantity);

        return implode(',', $pairs);
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, Request $request): CashPromoSetting
    {
        $this->validate($data);

        $quantity = (int) ($data['quantity'] ?? 0);

        $setting = CashPromoSetting::create([
            'price' => (float) $data['price'],
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'description' => $data['description'],
            'promo_type' => $this->activePromoType(),
            'created_date' => now(),
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'status' => CashPromoSetting::STATUS_ACTIVE,
            'is_process' => -1,
            'target_group_type' => $data['target_group_type'] ?? 'all',
            'target_group' => $this->encodeTargetGroup($data),
            'draw_type' => $data['draw_type'],
            'draw_date' => $data['draw_date'] ?? null,
            'other_service' => 'all',
        ]);

        ActivityLog::recordCreated($request->user(), 'Cash Promo Settings', $setting, ['price', 'quantity', 'description', 'target_group_type', 'draw_type', 'draw_date', 'status'], $request);

        return $setting;
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, Request $request): CashPromoSetting
    {
        $setting = CashPromoSetting::where('promo_type', $this->activePromoType())->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['Cash promo setting not found.']]);
        }

        $this->validate($data);

        $quantity = (int) ($data['quantity'] ?? 0);
        $before = $setting->getAttributes();

        $setting->update([
            'price' => (float) $data['price'],
            'quantity' => $quantity,
            // Legacy always resyncs remaining_quantity to the (possibly edited) quantity on update.
            'remaining_quantity' => $quantity,
            'description' => $data['description'],
            'updated_date' => now(),
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'target_group_type' => $data['target_group_type'] ?? 'all',
            'target_group' => $this->encodeTargetGroup($data),
            'draw_type' => $data['draw_type'],
            'draw_date' => $data['draw_date'] ?? null,
        ]);

        ActivityLog::recordUpdated($request->user(), 'Cash Promo Settings', $setting, $before, ['price', 'quantity', 'remaining_quantity', 'description', 'target_group_type', 'target_group', 'draw_type', 'draw_date'], $request);

        return $setting->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, Request $request): void
    {
        $setting = CashPromoSetting::where('promo_type', $this->activePromoType())->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['Cash promo setting not found.']]);
        }

        $before = $setting->getAttributes();
        $setting->update(['status' => CashPromoSetting::STATUS_DELETED, 'updated_date' => now()]);

        ActivityLog::recordUpdated($request->user(), 'Cash Promo Settings', $setting, $before, ['status'], $request, "Removed cash promo setting: {$setting->description}");
    }
}
