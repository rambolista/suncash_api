<?php

namespace App\Services\Promotions;

use App\Models\Mysuncash\CashPromoSetting;
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
            ->where('status', '!=', CashPromoSetting::STATUS_DELETED)
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

        $quantity = $data['quantity'] ?? null;
        if (! is_numeric($quantity) || (int) $quantity < 1) {
            $errors['quantity'] = ['Enter a valid quantity.'];
        }

        if (! filled($data['description'] ?? null)) {
            $errors['description'] = ['Description is required.'];
        }

        if (! in_array($data['draw_type'] ?? null, ['weekly_draw', 'instant_prize'], true)) {
            $errors['draw_type'] = ['Select a valid draw type.'];
        }

        $targetGroupType = $data['target_group_type'] ?? 'all';
        if (! in_array($targetGroupType, self::TARGET_TYPES, true)) {
            $errors['target_group_type'] = ['Select a valid target group.'];
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

    private function encodeTargetGroup(array $data): string
    {
        $type = $data['target_group_type'] ?? 'all';

        return match ($type) {
            'island', 'multiple' => implode(',', array_map('strval', $data['target_group_islands'] ?? [])),
            'percentage' => implode(',', array_map(
                fn ($row) => $row['island_id'].'-'.$row['percentage'],
                $data['target_group_allocations'] ?? []
            )),
            default => '',
        };
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): CashPromoSetting
    {
        $this->validate($data);

        return CashPromoSetting::create([
            'price' => (float) $data['price'],
            'quantity' => (int) $data['quantity'],
            'remaining_quantity' => (int) $data['quantity'],
            'description' => $data['description'],
            'promo_type' => $this->activePromoType(),
            'created_date' => now(),
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'status' => CashPromoSetting::STATUS_ACTIVE,
            'target_group_type' => $data['target_group_type'] ?? 'all',
            'target_group' => $this->encodeTargetGroup($data),
            'draw_type' => $data['draw_type'],
            'draw_date' => $data['draw_date'] ?? null,
            'other_service' => 'all',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data): CashPromoSetting
    {
        $setting = CashPromoSetting::where('promo_type', $this->activePromoType())->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['Cash promo setting not found.']]);
        }

        $this->validate($data);

        $setting->update([
            'price' => (float) $data['price'],
            'quantity' => (int) $data['quantity'],
            'description' => $data['description'],
            'updated_date' => now(),
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'target_group_type' => $data['target_group_type'] ?? 'all',
            'target_group' => $this->encodeTargetGroup($data),
            'draw_type' => $data['draw_type'],
            'draw_date' => $data['draw_date'] ?? null,
        ]);

        return $setting->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id): void
    {
        $setting = CashPromoSetting::where('promo_type', $this->activePromoType())->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['Cash promo setting not found.']]);
        }

        $setting->update(['status' => CashPromoSetting::STATUS_DELETED, 'updated_date' => now()]);
    }
}
