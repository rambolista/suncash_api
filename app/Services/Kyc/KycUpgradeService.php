<?php

namespace App\Services\Kyc;

use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\SubAccountSetting;
use App\Models\Mysuncash\TransactionLimit;
use App\Models\Mysuncash\WebLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "KYC Upgrade" (legacy `Tools::clien_list()` / `tools_model.php`'s
 * client/customer-access methods) — the review queue for customers
 * requesting an upgrade from the default "quickstart" tier to "full"
 * (Tier 2, a higher daily transaction limit). Unlike most other reviewed
 * queues ported this session, there is no dedicated request table: the
 * whole workflow lives on one column, `customers.customer_access`
 * (quickstart/pending/full/rejected), so a customer can only ever have one
 * state at a time.
 *
 * Deliberately NOT ported: the SMS notification sent on approve/reject
 * (Infobip gateway, not configured in this codebase — same reasoning as
 * every other SMS-notifying feature this session) and the two dead-weight
 * payloads legacy's `view_client()` fetches but never renders (`cd`/
 * `wu_uploaded_request` docs, and linked-card counts).
 *
 * Deliberately FIXED vs legacy: approve()/reject() are guarded against the
 * customer's current `customer_access` (legacy allows either action on any
 * customer regardless of current state, relying only on the buttons being
 * client-side hidden for non-pending rows).
 */
class KycUpgradeService
{
    private const ID_TYPES = [
        'gid' => 'Government ID',
        'dl' => "Driver's License",
        'pid' => 'Postal ID',
        'eid' => 'Employee ID',
        'p' => 'Passport',
        'nib' => 'NIB Smart Card',
        'voters' => "Voter's Card",
    ];

    private const SECONDARY_ID_TYPES = [
        'p' => 'Passport',
        'vc' => "Voter's Card",
        'nib' => 'NIB Card',
        'dl' => 'Drivers License',
        'vp' => 'Valid Permit',
        'is' => 'Imigration Stamp',
        'cs' => 'Cruise Ship ID',
        'rp' => 'Residence Permit',
        'sp' => 'Spousal Permit',
        'con' => 'Certification of Nat',
    ];

    public const REJECT_REASONS = [
        'ID Expired',
        'ID Blurry',
        'Incorrect ID',
        'Address Incorrect',
        'Missing Information',
        'Update App - Re-upload ID',
        'Incorrect Birth Date',
        'Missing Selfie with ID',
        'Document does not match info inputted',
    ];

    public const COLUMNS = [
        ['key' => 'created_at', 'label' => 'Account Created'],
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'mobile', 'label' => 'Mobile'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'reason_reject', 'label' => 'Reason'],
        ['key' => 'updated_at', 'label' => 'Date Rejected'],
    ];

    /** Same base64-vs-URL resolution the legacy view's JS applies to every image field. */
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

    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'created_at' => $customer->create_on,
            'name' => trim($customer->first_name.' '.$customer->last_name),
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'status' => $customer->customer_access,
            'reason_reject' => $customer->reason_reject,
            'updated_at' => $customer->updated_on,
        ];
    }

    public function list(): array
    {
        $result = [];
        foreach (['pending' => Customer::ACCESS_PENDING, 'approved' => Customer::ACCESS_FULL, 'rejected' => Customer::ACCESS_REJECTED] as $key => $status) {
            $result[$key] = Customer::where('customer_access', $status)
                ->orderBy('create_on')
                ->get()
                ->map(fn (Customer $customer) => $this->present($customer))
                ->all();
        }

        return $result;
    }

    public function exportRows(?string $status = null): array
    {
        $query = Customer::whereIn('customer_access', [Customer::ACCESS_PENDING, Customer::ACCESS_FULL, Customer::ACCESS_REJECTED]);
        if ($status) {
            $query->where('customer_access', $status);
        }

        return $query->orderBy('create_on')
            ->get()
            ->map(fn (Customer $customer) => $this->present($customer))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): Customer
    {
        $customer = Customer::with(['ezkardAccount', 'secondaryId', 'occupationRecord', 'employmentPositionLevelRecord', 'islandRecord', 'cityRecord'])->find($id);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        return $customer;
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $customer = $this->findOrFail($id);

        $tierLimit = TransactionLimit::where('type', $customer->customer_access)->value('transaction_limit');

        $secondary = null;
        if ($customer->has_secondary_id && $customer->secondaryId) {
            $secondary = [
                'id_card_type' => $customer->secondaryId->id_card_type,
                'id_card_type_label' => self::SECONDARY_ID_TYPES[$customer->secondaryId->id_card_type] ?? $customer->secondaryId->id_card_type,
                'id_card_num' => $customer->secondaryId->id_card_num,
                'id_card_expiry' => $customer->secondaryId->id_card_expiry,
                'scanned_id_url' => $this->resolveImage($customer->secondaryId->scanned_id),
            ];
        }

        return [
            'id' => $customer->id,
            'status' => $customer->customer_access,
            'kyc_tier' => $customer->customer_access === Customer::ACCESS_FULL ? 'Tier 2' : 'Tier 1',
            'kyc_level' => $tierLimit !== null ? 'BSD '.number_format((float) $tierLimit, 2) : null,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'middle_name' => $customer->middle_name,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'gender' => $customer->gender,
            'birthday' => $customer->birthday,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->cityRecord?->city_name,
            'island' => $customer->islandRecord?->name,
            'country' => $customer->country,
            'occupation' => $customer->occupationRecord?->description,
            'employment_position_level' => $customer->employmentPositionLevelRecord?->description,
            'profile_pic_url' => $this->resolveImage($customer->image_url),
            'selfie_url' => $this->resolveImage($customer->selfie_url),
            'signature_url' => $this->resolveImage($customer->signature),
            'id_card_type' => $customer->id_card_type,
            'id_card_type_label' => self::ID_TYPES[$customer->id_card_type] ?? $customer->id_card_type,
            'id_card_num' => $customer->id_card_num,
            'id_card_expiry' => $customer->id_card_expiry,
            'scanned_id_url' => $this->resolveImage($customer->scanned_id),
            'has_secondary_id' => (bool) $customer->has_secondary_id,
            'secondary_id' => $secondary,
            'reason_reject' => $customer->reason_reject,
            'created_at' => $customer->create_on,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $id, string $actorId, string $actorIp): array
    {
        $customer = $this->findOrFail($id);

        if ($customer->customer_access !== Customer::ACCESS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Only pending customers can be approved.']]);
        }

        DB::connection('mysuncash')->transaction(function () use ($customer, $actorId, $actorIp) {
            $customer->customer_access = Customer::ACCESS_FULL;
            $customer->updated_by = $actorId;
            $customer->updated_on = now();
            $customer->save();

            if ((string) $customer->is_sub_account !== '0') {
                $customer->status = 'A';
                $customer->save();

                $fullLimit = TransactionLimit::where('type', Customer::ACCESS_FULL)->value('transaction_limit');
                SubAccountSetting::updateOrCreate(
                    ['customer_id' => $customer->id],
                    ['transaction_limit' => $fullLimit],
                );
            }

            WebLog::create([
                'customer_id' => $customer->id,
                'updated_by' => $actorId,
                'log_type' => 'UPDATE_KYC',
                'data' => 'APPROVED',
                'user_ip_address' => $actorIp,
            ]);
        });

        return ['id' => $customer->id, 'status' => Customer::ACCESS_FULL];
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $id, string $reason, string $actorId, string $actorIp): array
    {
        $customer = $this->findOrFail($id);

        if ($customer->customer_access !== Customer::ACCESS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Only pending customers can be rejected.']]);
        }
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => ['A rejection reason is required.']]);
        }

        DB::connection('mysuncash')->transaction(function () use ($customer, $reason, $actorId, $actorIp) {
            $customer->customer_access = Customer::ACCESS_REJECTED;
            $customer->reason_reject = $reason;
            $customer->updated_by = $actorId;
            $customer->updated_on = now();
            $customer->save();

            WebLog::create([
                'customer_id' => $customer->id,
                'updated_by' => $actorId,
                'log_type' => 'UPDATE_KYC',
                'data' => 'REJECTED',
                'user_ip_address' => $actorIp,
            ]);
        });

        return ['id' => $customer->id, 'status' => Customer::ACCESS_REJECTED];
    }
}
