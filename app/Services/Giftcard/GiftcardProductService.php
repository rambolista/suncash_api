<?php

namespace App\Services\Giftcard;

use App\Models\ActivityLog;
use App\Models\Mysuncash\GiftcardProductType;
use App\Models\Mysuncash\MerchantGiftcard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Giftcards > Products" — legacy `Administrator::giftcards_list()` /
 * `clients_model`'s gc* methods. A merchant submits a gift-card product
 * (elsewhere, outside this codebase — the legacy admin panel has no create
 * form for this, only review/activate/deactivate) and this admin queue
 * reviews it.
 *
 * Deliberately NOT ported from legacy: the external "Giftcard" vendor API
 * calls (`Admin/ActivateProduct`, `Admin/DisableProduct`, etc. — a
 * third-party platform whose host/credentials aren't configured in this
 * codebase) and the approval-notification emails (one recipient was a
 * hardcoded personal Gmail address in the legacy code — clearly a dev
 * artifact, not a real business requirement). This service manages
 * `merchant_giftcards` / `giftcard_producttypes` as the sole source of
 * truth, which — since the legacy local DB update happens unconditionally
 * before the external call and isn't rolled back if that call fails — is
 * already the practical behavior today.
 *
 * Both tables lack any user_id_create/user_id_modify column, so unlike
 * every other feature ported this session there's no actor to record here.
 */
class GiftcardProductService
{
    private const STATUS_KEYS = [
        'pending' => MerchantGiftcard::STATUS_PENDING,
        'active' => MerchantGiftcard::STATUS_ACTIVE,
        'disabled' => MerchantGiftcard::STATUS_DISABLED,
    ];

    private function pendingTypesCount(int $productId): int
    {
        return GiftcardProductType::where('product_id', $productId)
            ->where('status', GiftcardProductType::STATUS_PENDING)
            ->count();
    }

    private function present(MerchantGiftcard $product): array
    {
        return [
            'id' => $product->id,
            'created_at' => $product->create_date,
            'merchant_name' => $product->merchant?->dba_name,
            'logo_url' => $product->logo_url,
            'product_name' => $product->product_name,
            'card_type' => $product->card_type,
            'denomination_type' => $product->denomination_type,
            'expiry_in_months' => $product->expiry_in_months,
            'reloadable' => strtolower((string) $product->reloadable) === 'true',
            'multi_redemption' => strtolower((string) $product->multi_redemption) === 'true',
            'inactivity_trigger_months' => $product->inactivity_trigger_months,
            'status' => $product->status,
            'pending_types_count' => $this->pendingTypesCount($product->id),
        ];
    }

    private function presentType(GiftcardProductType $type): array
    {
        return [
            'id' => $type->id,
            'created_at' => $type->create_date,
            'merchant_name' => $type->merchant?->dba_name,
            'name' => $type->name,
            'min_amount' => $type->min_amount,
            'max_amount' => $type->max_amount,
            'amount' => (float) $type->amount,
            'status' => $type->status,
        ];
    }

    public function list(): array
    {
        $result = [];
        foreach (self::STATUS_KEYS as $key => $status) {
            $result[$key] = MerchantGiftcard::with('merchant')
                ->where('status', $status)
                ->orderByDesc('create_date')
                ->get()
                ->map(fn (MerchantGiftcard $product) => $this->present($product))
                ->all();
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): MerchantGiftcard
    {
        $product = MerchantGiftcard::with('merchant')->find($id);
        if (! $product) {
            throw ValidationException::withMessages(['id' => ['Product not found.']]);
        }

        return $product;
    }

    /**
     * @throws ValidationException
     */
    public function getProductTypes(int $productId): array
    {
        $product = $this->findOrFail($productId);

        $result = ['product' => $this->present($product)];
        foreach (self::STATUS_KEYS as $key => $status) {
            $result[$key] = GiftcardProductType::with('merchant')
                ->where('product_id', $productId)
                ->where('status', $status)
                ->orderByDesc('create_date')
                ->get()
                ->map(fn (GiftcardProductType $type) => $this->presentType($type))
                ->all();
        }

        return $result;
    }

    /**
     * Sets the product's status and cascades the same status to every one
     * of its product-type rows — matching legacy's real (non-vendor-API)
     * local DB behavior: approving/disabling a product does so as a whole,
     * not variant-by-variant.
     *
     * @throws ValidationException
     */
    private function setStatus(int $id, string $status, Request $request): array
    {
        $product = $this->findOrFail($id);

        if ($product->status === $status) {
            $label = strtolower($status);
            throw ValidationException::withMessages(['status' => ["This product is already {$label}."]]);
        }

        $before = $product->getAttributes();
        $product->status = $status;
        $product->save();

        GiftcardProductType::where('product_id', $product->id)->update(['status' => $status]);

        ActivityLog::recordUpdated($request->user(), 'Giftcard Products', $product, $before, ['status'], $request);

        return ['id' => $product->id, 'status' => $status];
    }

    /**
     * @throws ValidationException
     */
    public function activate(int $id, Request $request): array
    {
        return $this->setStatus($id, MerchantGiftcard::STATUS_ACTIVE, $request);
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(int $id, Request $request): array
    {
        return $this->setStatus($id, MerchantGiftcard::STATUS_DISABLED, $request);
    }
}
