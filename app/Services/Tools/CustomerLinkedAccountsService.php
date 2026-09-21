<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerBank;
use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\CustomerSecondaryId;
use App\Models\User;
use App\Services\Customer\Concerns\DecryptsPan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * Scanned-ID re-upload (`updateScannedIds()`) now uploads to the private
 * "s3" disk (bucket/region/credentials straight from legacy's own
 * prerun.php, `AWS_CUSTOMER_FILES_PATH` mirroring its
 * `CUSTOMER_FILES_CONTAINER`), storing a "{bucket}/{path}/{key}" marker
 * string in the same `scanned_id` column legacy uses — the same
 * bucket-name-substring check legacy's own `view_customer_files()` uses to
 * tell an S3 reference apart from a full external URL or one of the many
 * existing rows that still hold a raw base64 blob (`scannedIdUrl()` below
 * handles all three, so old rows keep rendering unchanged). Reads go
 * through a 20-minute presigned URL, matching legacy's `viewImageViaUrl()`
 * — these are ID documents, never public-read.
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
            'scanned_id' => $this->scannedIdUrl($customer->scanned_id),
            'secondary_id_card_type' => $secondary?->id_card_type,
            'secondary_id_card_num' => $secondary?->id_card_num,
            'secondary_id_card_expiry' => $secondary?->id_card_expiry,
            'secondary_scanned_id' => $this->scannedIdUrl($secondary?->scanned_id),
        ];
    }

    /** Legacy's own `preg_match(AWS_BUCKET_NAME, ...)` check — tells an S3 marker apart from a full URL or a raw base64 blob. */
    private function scannedIdUrl(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $marker = config('filesystems.disks.s3.bucket').'/'.config('filesystems.disks.s3.root').'/';
        if (! str_starts_with($value, $marker)) {
            return $value; // raw base64 blob — frontend already renders this branch as-is
        }

        return Storage::disk('s3')->temporaryUrl(substr($value, strlen($marker)), now()->addMinutes(20));
    }

    /** Uploads a base64 image (no data-URI prefix) to the private "s3" disk and returns the marker string stored in the DB column. */
    private function storeScannedIdImage(int $customerId, string $base64, string $prefix): string
    {
        $key = "{$customerId}/{$prefix}_".now()->timestamp.'_'.Str::random(8).'.jpg';
        Storage::disk('s3')->put($key, base64_decode($base64));

        return config('filesystems.disks.s3.bucket').'/'.config('filesystems.disks.s3.root').'/'.$key;
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
            $primary['scanned_id'] = $this->storeScannedIdImage($customerId, $data['scanned_id'], 'primary');
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
                $secondary['scanned_id'] = $this->storeScannedIdImage($customerId, $data['secondary_scanned_id'], 'secondary');
            }
            CustomerSecondaryId::where('customer_id', $customerId)->update(array_filter($secondary, fn ($v) => $v !== null));
        }

        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'scanned_ids_updated', "Updated scanned ID's for customer {$customerId}");

        return $this->scannedIds($customerId);
    }
}
