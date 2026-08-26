<?php

namespace App\Services\MerchantType;

use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\CustomerOtherFile;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantGiftcard;
use App\Models\Mysuncash\WidgetSetup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The 7 net-new per-row settings buttons on Business Management's Approved
 * tab (legacy `merchant_online_signup.php`'s Card Hold Settings / Suncash
 * Transaction Fee / Authorized Auth / GC Fee / Voucher Setting / credit-debit
 * card review) — all scoped to merchant_type_id = 1, matching legacy's own
 * scoping of these buttons to that queue.
 *
 * Password reset / Services Permission / Smartpay (Ezpay) Access are the
 * standard merchant toolbar and already live in MerchantOperationsService —
 * reused as-is rather than duplicated here.
 */
class BusinessMerchantActionsService
{
    public const BUILT_IN_VOUCHER_TYPES = ['s' => 'SunCash Voucher', 'u' => 'Unibucks Voucher'];

    /**
     * @throws ValidationException
     */
    private function findBusinessOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::where('merchant_type_id', Merchant::MERCHANT_TYPE_BUSINESS)->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Business not found.']]);
        }

        return $merchant;
    }

    private function requireNumeric(array $data, string $field, string $label, float $min = 0, ?float $max = null): float
    {
        $value = $data[$field] ?? null;

        if ($value === null || trim((string) $value) === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([$field => ["{$label} is required and must be numeric."]]);
        }

        $number = (float) $value;

        if ($number < $min || ($max !== null && $number > $max)) {
            $range = $max !== null ? "between {$min} and {$max}" : "at least {$min}";
            throw ValidationException::withMessages([$field => ["{$label} must be {$range}."]]);
        }

        return $number;
    }

    // ── Card Hold Settings ──────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function updateCardHoldDays(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $days = $this->requireNumeric($data, 'card_hold_days', 'Card hold days');

        $merchant->update([
            'card_hold_days' => (int) $days,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return ['card_hold_days' => (int) $merchant->card_hold_days];
    }

    // ── Suncash Transaction Fee ──────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function updateTransactionFee(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $fee = $this->requireNumeric($data, 'suncash_transaction_fee', 'Suncash transaction fee', 0, 100);

        $merchant->update([
            'suncash_transaction_fee' => $fee,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return ['suncash_transaction_fee' => (float) $merchant->suncash_transaction_fee];
    }

    // ── Authorized Auth ──────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function updateAuthorizedAuth(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $limit = $this->requireNumeric($data, 'reauth_amount_limit', 'Reauthorization amount limit');
        $holdDays = $this->requireNumeric($data, 'reauth_card_hold_days', 'Reauthorization card hold days');

        $merchant->update([
            'reauth_amount_limit' => $limit,
            'reauth_card_hold_days' => (int) $holdDays,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return [
            'reauth_amount_limit' => (float) $merchant->reauth_amount_limit,
            'reauth_card_hold_days' => (int) $merchant->reauth_card_hold_days,
        ];
    }

    // ── GC Fee ───────────────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function updateGcFee(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $fee = $this->requireNumeric($data, 'gc_fee', 'GC fee', 0, 100);

        $merchant->update([
            'gc_fee' => $fee,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return ['gc_fee' => (float) $merchant->gc_fee];
    }

    // ── Voucher Setting ──────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function getVoucherSettings(int $merchantId): array
    {
        $this->findBusinessOrFail($merchantId);

        $widgetSetup = WidgetSetup::where('client_id', $merchantId)->first();
        $selected = $widgetSetup ? array_filter(explode(',', (string) $widgetSetup->voucher_product)) : [];

        $giftcards = MerchantGiftcard::where('status', 'ACTIVE')
            ->orderBy('product_name')
            ->get(['id', 'product_name'])
            ->map(fn ($gc) => ['value' => (string) $gc->id, 'label' => $gc->product_name])
            ->all();

        $options = array_merge(
            array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys(self::BUILT_IN_VOUCHER_TYPES), self::BUILT_IN_VOUCHER_TYPES),
            $giftcards,
        );

        return [
            'options' => $options,
            // SunCash + Unibucks vouchers can never be deselected — mirrors legacy's forced union.
            'selected' => array_values(array_unique(array_merge(array_keys(self::BUILT_IN_VOUCHER_TYPES), $selected))),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function updateVoucherSettings(int $merchantId, array $data, string $actorId): array
    {
        $this->findBusinessOrFail($merchantId);

        $types = array_values(array_unique(array_merge(
            array_keys(self::BUILT_IN_VOUCHER_TYPES),
            array_map('strval', $data['voucher_types'] ?? []),
        )));

        WidgetSetup::updateOrCreate(
            ['client_id' => $merchantId],
            ['voucher_product' => implode(',', $types)],
        );

        return $this->getVoucherSettings($merchantId);
    }

    // ── Credit/Debit Card review ─────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function listLinkedCards(int $merchantId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);

        return CustomerCreditCard::where('merchant_key', $merchant->merchant_key)
            ->orderByDesc('id')
            ->get()
            ->map(function (CustomerCreditCard $card) {
                $status = 'PENDING';
                if ((int) $card->is_verified === 1 && (int) $card->is_rejected === 0 && (int) $card->is_unlinked === 0) {
                    $status = 'VERIFIED';
                } elseif ((int) $card->status === 1 && (int) $card->is_rejected === 1) {
                    $status = 'REJECTED';
                }

                return [
                    'id' => $card->id,
                    'cardholder_name' => $card->cardholder_name,
                    'card_type' => $card->card_type,
                    'card_last_four_digits' => $card->card_last_four_digits,
                    'source' => $card->source,
                    'merchant_customer_id' => $card->merchant_customer_id,
                    'rejected_reason' => $card->rejected_reason,
                    'timestamp' => $card->timestamp,
                    'status' => $status,
                    'scanned_selfie' => CustomerOtherFile::where('reference', $card->id)->where('file_description', 'selfie with card')->orderByDesc('id')->value('file_url'),
                    'scanned_card' => CustomerOtherFile::where('reference', $card->id)->where('file_description', 'card')->orderByDesc('id')->value('file_url'),
                ];
            })
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findLinkedCardOrFail(Merchant $merchant, int $cardId): CustomerCreditCard
    {
        $card = CustomerCreditCard::where('merchant_key', $merchant->merchant_key)->find($cardId);

        if (! $card) {
            throw ValidationException::withMessages(['card_id' => ['Card not found.']]);
        }

        return $card;
    }

    /**
     * @throws ValidationException
     */
    public function approveCard(int $merchantId, int $cardId, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $card = $this->findLinkedCardOrFail($merchant, $cardId);

        if ((int) $card->is_verified === 1) {
            throw ValidationException::withMessages(['card_action' => ['This card is already approved.']]);
        }
        if ((int) $card->status === 1 && (int) $card->is_rejected === 1) {
            throw ValidationException::withMessages(['card_action' => ['This card has already been rejected.']]);
        }

        $card->update([
            'is_verified' => 1,
            'is_pending' => 0,
            'is_manual_approved' => 1,
            'updated_by' => $actorId,
        ]);

        return ['status' => 'VERIFIED'];
    }

    /**
     * @throws ValidationException
     */
    public function rejectCard(int $merchantId, int $cardId, string $reason, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $card = $this->findLinkedCardOrFail($merchant, $cardId);

        if ((int) $card->status === 1 || (int) $card->is_rejected === 1) {
            throw ValidationException::withMessages(['card_action' => ['This card has already been rejected.']]);
        }

        $verify = $this->callCenposApi(config('services.cenpos.verifyingpost_url'), [
            'merchant' => config('services.cenpos.merchant_id'),
            'secretkey' => config('services.cenpos.merchant_secretkey'),
            'tokenid' => $card->token_id,
        ]);

        if (($verify['Result'] ?? null) != 0) {
            throw ValidationException::withMessages(['card_action' => ['CenPOS error: '.($verify['Message'] ?? 'unable to verify card token.')]]);
        }

        $delete = $this->callCenposApi(config('services.cenpos.deletetoken_url'), [
            'verifyingpost' => $verify['Data'] ?? null,
            'tokenid' => $card->token_id,
        ]);

        if (($delete['Result'] ?? null) != 0) {
            Log::warning('CenPOS card token delete failed', ['card_id' => $card->id, 'response' => $delete]);
            throw ValidationException::withMessages(['card_action' => ['CenPOS error: '.($delete['Message'] ?? 'unable to delete card token.')]]);
        }

        $card->update([
            'status' => 1,
            'is_rejected' => 1,
            'rejected_reason' => $reason,
            'is_pending' => 0,
            'is_unlinked' => 1,
            'updated_by' => $actorId,
        ]);

        return ['status' => 'REJECTED'];
    }

    private function callCenposApi(?string $url, array $data): array
    {
        if (! $url) {
            throw ValidationException::withMessages(['card_action' => ['CenPOS is not configured on this environment.']]);
        }

        $response = Http::withOptions(['verify' => (bool) config('services.cenpos.verify_ssl', true)])
            ->asForm()
            ->post($url, $data);

        return (array) $response->json();
    }
}
