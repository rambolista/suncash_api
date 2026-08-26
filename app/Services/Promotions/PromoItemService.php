<?php

namespace App\Services\Promotions;

use App\Models\Mysuncash\PromoItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Physical-prize inventory CRUD for the active promo — mirrors legacy admin's Settings > Physical Items Settings tab. */
class PromoItemService
{
    private const DRAW_TYPES = ['weekly_draw', 'grand_draw', 'instant_prize', 'wu_draw', 'ps5_draw'];

    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    public function list(): array
    {
        return PromoItem::with(['branch', 'merchant'])
            ->where('event_description', $this->activePromoType())
            ->where('status', '!=', PromoItem::STATUS_DELETED)
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

    private function validate(array $data): void
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

    /**
     * @throws ValidationException
     */
    public function create(array $data, ?UploadedFile $image): PromoItem
    {
        $this->validate($data);

        return PromoItem::create([
            'branch_id' => (int) $data['branch_id'],
            'merchant_id' => (int) $data['merchant_id'],
            'image_url' => $this->storeImage($image),
            'item_description' => $data['item_description'],
            'quantity' => (int) $data['quantity'],
            'remaining_quantity' => (int) $data['quantity'],
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'created_date' => now(),
            'event_description' => $this->activePromoType(),
            'status' => PromoItem::STATUS_ACTIVE,
            'draw_type' => $data['draw_type'],
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

        $this->validate($data);

        $updates = [
            'branch_id' => (int) $data['branch_id'],
            'merchant_id' => (int) $data['merchant_id'],
            'item_description' => $data['item_description'],
            'quantity' => (int) $data['quantity'],
            'is_instant_reusable' => ! empty($data['is_instant_reusable']) ? 1 : 0,
            'updated_date' => now(),
            'draw_type' => $data['draw_type'],
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
