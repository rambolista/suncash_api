<?php

namespace App\Services\Promotions;

use App\Models\Mysuncash\PromoItem;
use App\Models\Mysuncash\SuncashPromoSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Physical-prize inventory CRUD for the active promo — mirrors legacy admin's Settings > Physical Items Settings tab. */
class PromoItemService
{
    private const DRAW_TYPES = ['weekly_draw', 'grand_draw', 'instant_prize', 'wu_draw', 'ps5_draw'];

    // Legacy forces these draw types to a single unit — the whole "prize" is one indivisible item.
    private const SINGLE_UNIT_DRAW_TYPES = ['weekly_draw', 'wu_draw'];

    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    public function list(): array
    {
        return PromoItem::with(['branch', 'merchant'])
            ->where('event_description', $this->activePromoType())
            ->where('status', PromoItem::STATUS_ACTIVE)
            ->where('is_process', -1)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PromoItem $item) => [
                'id' => $item->id,
                'branch_id' => $item->branch_id,
                'merchant_id' => $item->merchant_id,
                'branch_name' => $item->branch?->description,
                'merchant_name' => $item->merchant?->merchant_name,
                'image_url' => $item->image_url,
                'item_description' => $item->item_description,
                'quantity' => $item->quantity,
                'remaining_quantity' => $item->remaining_quantity,
                'is_instant_reusable' => (bool) $item->is_instant_reusable,
                'status' => $item->status,
                'draw_type' => $item->draw_type,
                'draw_date' => $item->draw_date,
            ])
            ->all();
    }

    private function validate(array $data, bool $requireImage, ?UploadedFile $image): void
    {
        $errors = [];

        if (! filled($data['merchant_id'] ?? null)) {
            $errors['merchant_id'] = ['Select a merchant.'];
        }
        if (! filled($data['branch_id'] ?? null)) {
            $errors['branch_id'] = ['Select a branch.'];
        }
        if (! filled($data['item_description'] ?? null)) {
            $errors['item_description'] = ['Item description is required.'];
        }
        $quantity = $data['quantity'] ?? null;
        if (! is_numeric($quantity) || (int) $quantity < 1) {
            $errors['quantity'] = ['Enter a valid quantity.'];
        }
        if (! in_array($data['draw_type'] ?? null, self::DRAW_TYPES, true)) {
            $errors['draw_type'] = ['Select a valid draw type.'];
        }
        if ($requireImage && ! $image) {
            $errors['image'] = ['An item image is required.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function storeImage(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        $path = $file->store('promo-items', 'public');

        return Storage::disk('public')->url($path);
    }

    /** Legacy's grand-draw prize cap and same-date-uniqueness config, from suncash_promo_settings. Falls back to legacy's own defaults when the promo has no config row. */
    private function grandDrawConfig(): array
    {
        $row = SuncashPromoSetting::where('code', $this->activePromoType())->first();

        return [
            'total' => $row ? (int) $row->grand_winner_count : 1,
            'separate_date' => $row ? ((string) $row->is_gd_separate_date === '1') : true,
        ];
    }

    private function grandDrawExistingQuantity(): int
    {
        return (int) PromoItem::where('draw_type', 'grand_draw')
            ->where('event_description', $this->activePromoType())
            ->whereIn('status', [PromoItem::STATUS_ACTIVE, PromoItem::STATUS_USED])
            ->sum('quantity');
    }

    private function grandDrawDateTaken(?string $drawDate, ?int $excludeId): bool
    {
        if (! $drawDate) {
            return false;
        }

        return PromoItem::where('draw_type', 'grand_draw')
            ->where('event_description', $this->activePromoType())
            ->whereIn('status', [PromoItem::STATUS_ACTIVE, PromoItem::STATUS_USED])
            ->whereDate('draw_date', date('Y-m-d', strtotime($drawDate)))
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();
    }

    /**
     * Legacy forces quantity to 1 for weekly/AC-prize draws (each is a single
     * indivisible prize), and caps grand-draw quantity/dates against
     * suncash_promo_settings.grand_winner_count + is_gd_separate_date.
     *
     * @throws ValidationException
     */
    private function normalizeQuantityForDrawType(string $drawType, int $quantity, ?string $drawDate, ?int $excludeItemId): int
    {
        if (in_array($drawType, self::SINGLE_UNIT_DRAW_TYPES, true)) {
            return 1;
        }

        if ($drawType !== 'grand_draw') {
            return $quantity;
        }

        $config = $this->grandDrawConfig();

        if ($config['separate_date'] && $this->grandDrawDateTaken($drawDate, $excludeItemId)) {
            throw ValidationException::withMessages(['draw_date' => ['You can only assign one item to a specific date.']]);
        }

        $existing = $this->grandDrawExistingQuantity();
        if ($existing >= $config['total']) {
            throw ValidationException::withMessages(['quantity' => ["You can only set {$existing} prize(s) for the grand draw."]]);
        }
        if ($quantity > $config['total']) {
            throw ValidationException::withMessages(['quantity' => ["You can only set {$config['total']} for grand draw."]]);
        }

        return $quantity;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, ?UploadedFile $image): PromoItem
    {
        $this->validate($data, requireImage: true, image: $image);

        $drawType = $data['draw_type'];
        $quantity = $this->normalizeQuantityForDrawType($drawType, (int) $data['quantity'], $data['draw_date'] ?? null, null);

        return PromoItem::create([
            'branch_id' => (int) $data['branch_id'],
            'merchant_id' => (int) $data['merchant_id'],
            'image_url' => $this->storeImage($image),
            'item_description' => $data['item_description'],
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'created_date' => now(),
            'event_description' => $this->activePromoType(),
            'status' => PromoItem::STATUS_ACTIVE,
            'is_process' => -1,
            'draw_type' => $drawType,
            'draw_date' => $data['draw_date'] ?? null,
            'other_service' => 'all',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, ?UploadedFile $image): PromoItem
    {
        $item = PromoItem::where('event_description', $this->activePromoType())->find($id);
        if (! $item) {
            throw ValidationException::withMessages(['id' => ['Promo item not found.']]);
        }

        $this->validate($data, requireImage: false, image: $image);

        $drawType = $data['draw_type'];
        $requestedQuantity = (int) $data['quantity'];

        // Legacy only re-checks the grand-draw cap/date-uniqueness when an item is transitioning INTO
        // grand_draw — an item that was already grand_draw and stays grand_draw isn't re-validated on edit.
        $stayingGrandDraw = $drawType === 'grand_draw' && $item->draw_type === 'grand_draw';
        $quantity = $stayingGrandDraw
            ? $requestedQuantity
            : $this->normalizeQuantityForDrawType($drawType, $requestedQuantity, $data['draw_date'] ?? null, $id);

        $updates = [
            'branch_id' => (int) $data['branch_id'],
            'merchant_id' => (int) $data['merchant_id'],
            'item_description' => $data['item_description'],
            'quantity' => $quantity,
            // Legacy always resyncs remaining_quantity to the (possibly edited) quantity on update.
            'remaining_quantity' => $quantity,
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'updated_date' => now(),
            'draw_type' => $drawType,
            'draw_date' => $data['draw_date'] ?? null,
        ];

        if ($image) {
            $updates['image_url'] = $this->storeImage($image);
        }

        $item->update($updates);

        return $item->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id): void
    {
        $item = PromoItem::where('event_description', $this->activePromoType())->find($id);
        if (! $item) {
            throw ValidationException::withMessages(['id' => ['Promo item not found.']]);
        }

        $item->update(['status' => PromoItem::STATUS_DELETED, 'updated_date' => now()]);
    }
}
