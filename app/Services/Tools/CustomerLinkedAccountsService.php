<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerBank;
use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\CustomerSecondaryId;
use App\Models\User;
use App\Services\Customer\Concerns\DecryptsPan;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > View Linked Cards / View Linked Bank
 * Accounts / View Scanned ID's" (legacy `tools::view_customer_cards()`/
 * `delete_customer_card()`/`view_customer_bank()`/`view_scanned_ids()`).
 * All three are read-mostly reference panels a customer can have many of
 * (confirmed live: one customer had 18 linked cards, another 26 linked
 * bank accounts).
 *
 * Deliberately NOT ported:
 * - The selfie/card-scan images tied to a specific `customer_creditcard`
 *   row (`customer_other_files`) — a different, S3-backed evidence set
 *   from the primary/secondary ID scans below.
 * - "Link Customer to Card" — legacy's own admin UI has this button's
 *   click handler commented out (dead in legacy today), and the model
 *   method it would call has a real bug (reports success as failure) plus
 *   a PIN re-encryption step too risky to port blind.
 *
 * Scanned-ID re-upload IS ported (`updateScannedIds()`), just simplified:
 * legacy uploads to S3 and stores the resulting URL; this stores the
 * uploaded image as base64 directly in the same `scanned_id` column
 * instead, which the real data already does for plenty of existing rows
 * (`scanned_id` is inconsistently either a URL or a raw base64 blob even
 * in production) — no new file-storage infrastructure needed.
 */
class CustomerLinkedAccountsService
{
    use DecryptsPan;

    public function cards(int $customerId): array
    {
        return CustomerCreditCard::where('customer_id', $customerId)
            ->where('status', 0)
            ->orderByDesc('id')
            ->get(['id', 'cardholder_name', 'card_type', 'card_last_four_digits'])
            ->all();
    }

    /** @throws ValidationException */
    public function deleteCard(int $cardId, User $actor): void
    {
        $card = CustomerCreditCard::where('status', 0)->find($cardId);
        if (! $card) {
            throw ValidationException::withMessages(['id' => ['This linked card was not found.']]);
        }

        $card->update(['status' => 1, 'updated_by' => $actor->id]);
        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'linked_card_deleted', "Deleted linked card #{$cardId} for customer {$card->customer_id}");
    }

    public function bankAccounts(int $customerId): array
    {
        return CustomerBank::with(['bank', 'businessBillpayBank'])
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerBank $b) => [
                'id' => $b->id,
                'bank' => $b->bank?->name,
                'branch' => $b->businessBillpayBank?->branch_info,
                'account_type' => $b->account_type,
                'account_name' => $this->decryptPan($b->account_name, (string) $customerId),
                'account_number' => $this->decryptPan($b->account_number, (string) $customerId),
            ])->all();
    }

    /** @throws ValidationException */
    public function scannedIds(int $customerId): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        $secondary = $customer->has_secondary_id == 1 ? CustomerSecondaryId::where('customer_id', $customerId)->orderByDesc('id')->first() : null;

        return [
            'has_secondary_id' => (bool) $customer->has_secondary_id,
            'id_card_type' => $customer->id_card_type,
            'id_card_num' => $customer->id_card_num,
            'id_card_expiry' => $customer->id_card_expiry,
            'id_card_issue_date' => $customer->id_card_issue_date,
            'scanned_id' => filled($customer->scanned_id) ? $customer->scanned_id : null,
            'secondary_id_card_type' => $secondary?->id_card_type,
            'secondary_id_card_num' => $secondary?->id_card_num,
            'secondary_id_card_expiry' => $secondary?->id_card_expiry,
            'secondary_scanned_id' => $secondary && filled($secondary->scanned_id) ? $secondary->scanned_id : null,
        ];
    }

    /** Legacy `edit_scanned_id()`. @throws ValidationException */
    public function updateScannedIds(int $customerId, array $data, User $actor): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        $primary = [
            'id_card_type' => $data['id_card_type'] ?? $customer->id_card_type,
            'id_card_num' => $data['id_card_num'] ?? $customer->id_card_num,
            'id_card_expiry' => $data['id_card_expiry'] ?? $customer->id_card_expiry,
            'id_card_issue_date' => $data['id_card_issue_date'] ?? $customer->id_card_issue_date,
            'updated_by' => $actor->name ?? $actor->email,
            'updated_on' => now(),
        ];
        if (filled($data['scanned_id'] ?? null)) {
            $primary['scanned_id'] = $data['scanned_id'];
        }
        $customer->update($primary);

        // Legacy only ever updates an EXISTING secondary-id row — it never creates one from this form.
        if ($customer->has_secondary_id == 1) {
            $secondary = [
                'id_card_type' => $data['secondary_id_card_type'] ?? null,
                'id_card_num' => $data['secondary_id_card_num'] ?? null,
                'id_card_expiry' => $data['secondary_id_card_expiry'] ?? null,
            ];
            if (filled($data['secondary_scanned_id'] ?? null)) {
                $secondary['scanned_id'] = $data['secondary_scanned_id'];
            }
            CustomerSecondaryId::where('customer_id', $customerId)->update(array_filter($secondary, fn ($v) => $v !== null));
        }

        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'scanned_ids_updated', "Updated scanned ID's for customer {$customerId}");

        return $this->scannedIds($customerId);
    }
}
