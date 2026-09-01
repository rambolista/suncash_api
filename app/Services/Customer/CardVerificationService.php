<?php

namespace App\Services\Customer;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CardBlacklist;
use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\CustomerOtherFile;
use App\Models\Mysuncash\WebLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Legacy's "Customers > Card Verification" (`Tools::card_verification()` /
 * `tools_model.php`'s `*_customer_creditcard()` methods) — the review queue
 * for customer credit/debit cards linked in the customer app, with 4 tabs
 * matching legacy's `get_card_list($status)`: pending, approved, rejected,
 * blacklisted.
 *
 * `customer_creditcard.customer_id` has no FK constraint; legacy's list
 * query INNER JOINs to `customers`, silently dropping orphaned cards — this
 * mirrors that the same way Customer Documents' orphan handling does.
 *
 * Legacy's list query is currently broken against the live schema: it
 * selects `cc.updated_date`, a column that does not exist on
 * `customer_creditcard` (only `updated_by` does — no timestamp), so the
 * page throws a SQL error whenever it loads. Its approve/reject actions are
 * ALSO broken for the same reason (their UPDATE statements write to the
 * same missing column); only blacklist's UPDATE omits it and actually
 * works. This service fixes the query to make the feature functional again
 * but, since there is no real "date approved/rejected" data source, does
 * NOT fabricate one — only "Updated By" is shown for those tabs, matching
 * what legacy's query could ACTUALLY produce once the broken column
 * reference is dropped.
 *
 * Deliberately NOT ported: the SMS/push notification sent on every action
 * (Infobip/Firebase, not configured in this codebase — same reasoning as
 * every other notification-sending feature ported this session).
 */
class CardVerificationService
{
    public const REJECT_REASONS = [
        'ID Blurry or not readable.',
        'Government identification required.',
        'Name on card and account mismatch',
        'Incorrect card details or photo.',
        'ID Expired',
        'Incorrect ID',
        'Missing Information',
        'Update App - Re-Upload ID',
        'Non Bahamian accounts are not accepted at this time',
        'Selfie holding card not sent',
        'Card blurry or not readable',
        'Close up of card itself required',
        'Show full name and last 4 digits on card',
        'Show entire face and card in selfie',
        'Selfie showing the entire card not sent',
        'Show entire card including full name and last 4 digit on card',
    ];

    public const BLACKLIST_REASONS = [
        'Invalid or Suspicious Card',
    ];

    private function resolveImage(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        if (str_starts_with($value, 'http') || str_starts_with($value, 'data:image/')) {
            return $value;
        }

        return 'data:image/jpeg;base64,'.$value;
    }

    private function formatMobile(?string $mobile): ?string
    {
        if (! filled($mobile)) {
            return $mobile;
        }

        return preg_replace('/^(\d{1})(\d{3})(\d{3})(\d{4})$/', '$1 ($2) $3-$4', $mobile) ?? $mobile;
    }

    private function present(CustomerCreditCard $card, string $status): array
    {
        $updatedByLabel = null;
        if ($card->source === 'suncash') {
            $updatedByLabel = $card->updatedByUser?->user_name;
        } elseif ($card->source === '3rdparty') {
            $updatedByLabel = '3rdparty';
        }

        return [
            'id' => $card->id,
            'customer_id' => $card->customer_id,
            'created_at' => $card->timestamp,
            'cardholder_name' => $card->cardholder_name,
            'mobile' => $this->formatMobile($card->customer?->mobile),
            'card_last_four_digits' => $card->card_last_four_digits,
            'card_type' => $card->card_type,
            'status' => $status,
            'updated_by_user' => $updatedByLabel,
            'rejected_reason' => $card->rejected_reason,
        ];
    }

    private function rowsForStatus(string $status)
    {
        $query = CustomerCreditCard::with(['customer', 'updatedByUser'])
            ->whereHas('customer');

        match ($status) {
            'pending' => $query->where('is_pending', 1)->where('is_verified', 0)->where('status', 0)
                ->where('is_blacklisted', '!=', 1)->where('is_rejected', '!=', 1),
            'approved' => $query->where('status', 0)->where('is_verified', 1)->where('is_blacklisted', '!=', 1),
            'rejected' => $query->where('is_rejected', 1)->where('is_blacklisted', '!=', 1)->where('status', 0),
            'blacklisted' => $query->where('is_blacklisted', 1),
        };

        return $query->orderByDesc('timestamp')->get();
    }

    public function list(): array
    {
        $result = [];
        foreach (['pending', 'approved', 'rejected', 'blacklisted'] as $status) {
            $result[$status] = $this->rowsForStatus($status)
                ->map(fn (CustomerCreditCard $card) => $this->present($card, $status))
                ->all();
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $ccid): CustomerCreditCard
    {
        $card = CustomerCreditCard::with(['customer.islandRecord', 'customer.cityRecord', 'customer.secondaryId'])->find($ccid);
        if (! $card || ! $card->customer) {
            throw ValidationException::withMessages(['id' => ['Card not found.']]);
        }

        return $card;
    }

    private function statusLabel(CustomerCreditCard $card): string
    {
        return match (true) {
            (int) $card->is_blacklisted === 1 => 'blacklisted',
            (int) $card->is_rejected === 1 => 'rejected',
            (int) $card->is_verified === 1 => 'approved',
            default => 'pending',
        };
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $ccid): array
    {
        $card = $this->findOrFail($ccid);
        $customer = $card->customer;

        $linkedCounts = DB::connection('mysuncash')->table('customer_creditcard')
            ->selectRaw("
                SUM(CASE WHEN is_rejected = 0 AND is_verified = 1 AND is_blacklisted != 1 THEN 1 ELSE 0 END) AS linked_card_count,
                SUM(CASE WHEN is_rejected = 0 AND is_verified = 1 AND is_blacklisted != 1 AND `timestamp` >= NOW() - INTERVAL 1 MONTH THEN 1 ELSE 0 END) AS linked_card_count_last_month
            ")
            ->where('customer_id', $customer->id)
            ->first();

        $otherFiles = CustomerOtherFile::where('customer_id', $customer->id)
            ->where('reference', (string) $ccid)
            ->get()
            ->map(fn (CustomerOtherFile $file) => [
                'id' => $file->id,
                'label' => $file->file_description ?: null,
                'file_url' => $this->resolveImage($file->file_url),
            ])
            ->filter(fn ($file) => $file['file_url'] !== null)
            ->values()
            ->all();

        $secondary = null;
        if ($customer->secondaryId) {
            $secondary = [
                'id_card_type' => $customer->secondaryId->id_card_type,
                'id_card_num' => $customer->secondaryId->id_card_num,
                'id_card_expiry' => $customer->secondaryId->id_card_expiry,
                'scanned_id_url' => $this->resolveImage($customer->secondaryId->scanned_id),
            ];
        }

        return [
            'id' => $card->id,
            'customer_id' => $customer->id,
            'status' => $this->statusLabel($card),
            'card' => [
                'cardholder_name' => $card->cardholder_name,
                'card_type' => $card->card_type,
                'card_last_four_digits' => $card->card_last_four_digits,
                'created_at' => $card->timestamp,
            ],
            'rejected_reason' => $card->rejected_reason,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'mobile' => $this->formatMobile($customer->mobile),
            'email' => $customer->email,
            'gender' => $customer->gender,
            'birthday' => $customer->birthday,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->cityRecord?->city_name,
            'island' => $customer->islandRecord?->name,
            'country' => $customer->country,
            'profile_pic_url' => $this->resolveImage($customer->image_url),
            'scanned_id_url' => $this->resolveImage($customer->scanned_id),
            'linked_card_count' => (int) ($linkedCounts->linked_card_count ?? 0),
            'linked_card_count_last_month' => (int) ($linkedCounts->linked_card_count_last_month ?? 0),
            'secondary_id' => $secondary,
            'other_files' => $otherFiles,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $ccid, string $actorId): array
    {
        $card = $this->findOrFail($ccid);
        if ($this->statusLabel($card) !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only pending cards can be approved.']]);
        }

        $card->is_pending = 0;
        $card->is_verified = 1;
        $card->updated_by = $actorId;
        $card->save();

        ActivityLog::recordAction(User::find($actorId), 'Card Verification', 'approved', "Approved card verification for {$card->cardholder_name} (card ending {$card->card_last_four_digits})", $card, null);

        return ['id' => $card->id, 'status' => 'approved'];
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $ccid, string $reason, string $actorId): array
    {
        $card = $this->findOrFail($ccid);
        if ($this->statusLabel($card) !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only pending cards can be rejected.']]);
        }
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => ['A rejection reason is required.']]);
        }

        $card->is_pending = 0;
        $card->is_rejected = 1;
        $card->rejected_reason = $reason;
        $card->updated_by = $actorId;
        $card->save();

        ActivityLog::recordAction(User::find($actorId), 'Card Verification', 'rejected', "Rejected card verification for {$card->cardholder_name} (card ending {$card->card_last_four_digits}): {$reason}", $card, null);

        return ['id' => $card->id, 'status' => 'rejected'];
    }

    /**
     * @throws ValidationException
     */
    public function blacklist(int $ccid, string $reason, string $actorId, string $actorIp): array
    {
        $card = $this->findOrFail($ccid);
        if ($this->statusLabel($card) !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only pending cards can be blacklisted.']]);
        }
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => ['A blacklist reason is required.']]);
        }

        $customer = $card->customer;

        DB::connection('mysuncash')->transaction(function () use ($card, $customer, $reason, $actorId, $actorIp) {
            $card->is_pending = 0;
            $card->is_blacklisted = 1;
            $card->rejected_reason = $reason;
            $card->updated_by = $actorId;
            $card->save();

            CardBlacklist::create([
                'name' => $card->cardholder_name,
                'last_4_digit_number' => $card->card_last_four_digits,
                'card_type' => $card->card_type,
                'expiry_date' => $card->expiration,
                'validation_type' => 'all',
                'updated_by' => $actorId,
            ]);

            WebLog::create([
                'customer_id' => $customer->id,
                'updated_by' => $actorId,
                'log_type' => 'ADD_CARD_BLACKLISTED',
                'data' => json_encode(['name' => $card->cardholder_name, 'last_4_digit_number' => $card->card_last_four_digits, 'reason' => $reason]),
                'user_ip_address' => $actorIp,
                'web_channel' => 'admin',
            ]);
        });

        ActivityLog::recordAction(User::find($actorId), 'Card Verification', 'blacklisted', "Blacklisted card for {$card->cardholder_name} (card ending {$card->card_last_four_digits}): {$reason}", $card, null);

        return ['id' => $card->id, 'status' => 'blacklisted'];
    }
}
