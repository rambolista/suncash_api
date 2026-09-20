<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Country;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerDevice;
use App\Models\Mysuncash\CustomerTransactionHistory;
use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\IslandCity;
use App\Models\Mysuncash\PrepaidVisaCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management" (legacy `tools/customer_management` +
 * `tools_model::search_customers()`/`get_customerinfo()`/
 * `edit_customer_profile()`). A support-facing customer lookup + profile
 * editor — a different screen from "Customers > Archive" (which already
 * ports the transaction-history + archive workflow; reused here rather
 * than duplicated) and from "Customers > KYC Upgrade" (tier approval,
 * unrelated).
 *
 * Scope cuts from legacy, each a deliberate simplification rather than a
 * silent drop:
 * - Mobile number is read-only here. Legacy's edit path, when the mobile
 *   changes, re-encrypts the customer's PIN under a new key derived from
 *   the new mobile and pushes a member-update to an external ALIV
 *   list-app API — too deep/risky a side-effect chain to port blind.
 * - "Reset Pin" (see `ResetPinService`) is gated behind a disabled-by-
 *   default config flag rather than legacy's hardcoded live GET straight
 *   to `https://prod.mysuncash.com` regardless of environment.
 * - Linked cards/banks (`CustomerLinkedAccountsService`), scanned ID's
 *   (also `CustomerLinkedAccountsService`), push notifications
 *   (`PushNotificationService`), and the Christmas-promo toggle
 *   (`CustomerPromoService`) live in their own small services — this class
 *   only covers the core profile fields and the KYC-tier/average-
 *   transaction figures legacy computes inline in `get_customerinfo()`.
 */
class CustomerManagementService
{
    private const SHORTCODE_PATTERN = '/^[a-zA-Z0-9]+$/';

    /** @throws ValidationException */
    public function search(array $filters): array
    {
        $hasFilter = collect($filters)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty();
        if (! $hasFilter) {
            throw ValidationException::withMessages(['query' => ['Please provide at least one search field.']]);
        }

        $query = DB::connection('mysuncash')->table('customers as ct')
            ->leftJoin('ezkard_accounts as a', 'a.id', '=', 'ct.ezkard_account_id')
            ->leftJoin('clients as c', 'a.client_id', '=', 'c.id')
            ->whereRaw("(ct.mobile NOT LIKE '%\\_%' OR ct.mobile IS NULL)"); // excludes archived customers (mobile suffixed "_<id>")

        if (filled($filters['first_name'] ?? null)) {
            $query->where('ct.first_name', 'like', '%'.$filters['first_name'].'%');
        }
        if (filled($filters['last_name'] ?? null)) {
            $query->where('ct.last_name', 'like', '%'.$filters['last_name'].'%');
        }
        if (filled($filters['mobile_number'] ?? null)) {
            $query->where('ct.mobile', $filters['mobile_number']);
        }
        if (filled($filters['card_number'] ?? null)) {
            $query->where('a.card_number', $filters['card_number']);
        }
        if (filled($filters['email'] ?? null)) {
            $query->where('ct.email', $filters['email']);
        }
        if (filled($filters['bank_topup'] ?? null)) {
            $query->where('ct.suncash_bank_account', $filters['bank_topup']);
        }

        return $query->orderByDesc('ct.id')
            ->limit(200)
            ->get(['ct.id', 'ct.first_name', 'ct.last_name', 'ct.mobile as mobile_number', 'a.card_number', 'c.merchant_name as merchant'])
            ->toArray();
    }

    private function findOrFail(int $id): Customer
    {
        $customer = Customer::with(['ezkardAccount.merchant', 'islandRecord', 'cityRecord', 'occupationRecord', 'employmentPositionLevelRecord'])->find($id);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        return $customer;
    }

    /** Legacy `kyc_status` derivation — simplified: this DB has no `kyc_date` column to drive the "Expired" branch legacy also had. */
    private function kycStatus(Customer $customer): string
    {
        if ($customer->status === 'I') {
            return 'Deactivated';
        }

        return match ($customer->customer_access) {
            Customer::ACCESS_QUICKSTART => 'Quick Start',
            Customer::ACCESS_FULL => 'Verified',
            Customer::ACCESS_PENDING => 'Pending',
            Customer::ACCESS_REJECTED => 'Rejected',
            default => ucfirst((string) $customer->customer_access),
        };
    }

    /** Legacy `ifInPepList()` — name match against the shared `blocked_list` PEP entries. */
    private function isPep(Customer $customer): bool
    {
        $name = trim((string) $customer->first_name.' '.(string) $customer->last_name);
        if ($name === '') {
            return false;
        }

        return DB::connection('mysuncash')->table('blocked_list')
            ->where('type', 'PEP')
            ->where('status', 'ACTIVE')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /** @throws ValidationException */
    public function detail(int $id): array
    {
        $customer = $this->findOrFail($id);
        $ezkard = $customer->ezkardAccount;

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'gender' => $customer->gender,
            'country' => $customer->country,
            'birthday' => $customer->birthday,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'zip' => $customer->zip,
            'island' => $customer->island,
            'island_name' => $customer->islandRecord?->name,
            'city' => $customer->city,
            'city_name' => $customer->cityRecord?->city_name,
            'customer_tag' => $customer->customer_tag,
            'risk_rating' => $customer->risk_rating,
            'occupation' => $customer->occupation,
            'occupation_name' => $customer->occupationRecord?->description,
            'employment_position_level' => $customer->employment_position_level,
            'employment_position_level_name' => $customer->employmentPositionLevelRecord?->description,
            'suncash_bank_account' => $customer->suncash_bank_account,
            'sms_notification' => (bool) $customer->sms_notification,
            'email_notification' => (bool) $customer->email_notification,
            'is_locked' => (bool) $customer->is_locked,
            'is_card_beta_user' => (bool) $customer->is_card_beta_user,
            'status' => $customer->status,
            'customer_access' => $customer->customer_access,
            'kyc_status' => $this->kycStatus($customer),
            'is_pep' => $this->isPep($customer),
            'created_date' => $customer->create_on,
            'card_number' => $ezkard?->card_number,
            'card_balance' => (float) ($ezkard?->card_balance ?? 0),
            'card_status_id' => $ezkard?->card_status_id,
            'merchant' => $ezkard?->merchant?->merchant_name,
            'locked_by' => $customer->locked_by,
            'locked_date' => $customer->locked_date,
            'locked_reason' => $customer->locked_reason,
            'locked_note' => $customer->locked_note,
            'restricted_by' => $customer->restricted_by,
            'restricted_date' => $customer->restricted_date,
            'restricted_reason' => $customer->restricted_reason,
            'restricted_note' => $customer->restricted_note,
            'ios_version' => $customer->ios_vr,
            'android_version' => $customer->android_vr,
            'prepaid_card_number' => PrepaidVisaCard::where('customer_id', $id)->value('card_number'),
            'avg_monthly_debit' => $this->averageMonthlyAmount($id, 'DEBIT'),
            'avg_monthly_credit' => $this->averageMonthlyAmount($id, 'CREDIT'),
            'authorized_devices' => CustomerDevice::where('customer_id', $id)->get(['id', 'uuid', 'model', 'timestamp'])->all(),
        ];
    }

    /**
     * Legacy `getCustomerAverageTransactions()` — average transaction
     * amount over the last calendar month. Legacy itself only ever
     * computes this monthly figure (no separate weekly query exists
     * anywhere in that codebase, despite the admin UI label saying
     * "Weekly/Monthly"), and legacy also swaps the credit/debit labels
     * when assigning the two results — replicated correctly here instead.
     */
    private function averageMonthlyAmount(int $customerId, string $orientation): float
    {
        return (float) (CustomerTransactionHistory::where('customer_id', $customerId)
            ->where('finance_orientation', $orientation)
            ->where('created_date', '>', now()->subMonth())
            ->where('created_date', '<=', now())
            ->avg('amount') ?? 0);
    }

    public function countries(): array
    {
        return Country::where('status', 1)->orderBy('name')->get(['country_id as id', 'name'])->all();
    }

    public function islands(): array
    {
        return Island::orderBy('name')->get(['id', 'name'])->all();
    }

    public function citiesByIsland(int $islandId): array
    {
        return IslandCity::where('island_id', $islandId)->orderBy('city_name')->get(['city_id as id', 'city_name as name'])->all();
    }

    /** @throws ValidationException */
    public function update(int $id, array $data, User $actor): array
    {
        $customer = $this->findOrFail($id);

        $shortcode = trim((string) ($data['customer_tag'] ?? ''));
        if ($shortcode !== '' && ! preg_match(self::SHORTCODE_PATTERN, $shortcode)) {
            throw ValidationException::withMessages(['customer_tag' => ['Shortcode may only contain letters and numbers.']]);
        }
        if ($shortcode !== '') {
            $clash = Customer::where('id', '!=', $id)->where('customer_tag', $shortcode)->exists()
                || DB::connection('mysuncash')->table('clients')->where('suntag_shortcode', $shortcode)->exists();
            if ($clash) {
                throw ValidationException::withMessages(['customer_tag' => ['This shortcode is already in use.']]);
            }
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && Customer::where('id', '!=', $id)->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['This email is already in use.']]);
        }

        $before = $customer->getAttributes();

        $customer->fill([
            'first_name' => $data['first_name'] ?? $customer->first_name,
            'middle_name' => $data['middle_name'] ?? $customer->middle_name,
            'last_name' => $data['last_name'] ?? $customer->last_name,
            'email' => $email !== '' ? $email : $customer->email,
            'gender' => $data['gender'] ?? $customer->gender,
            'country' => $data['country'] ?? $customer->country,
            'birthday' => $data['birthday'] ?? $customer->birthday,
            'address1' => $data['address1'] ?? $customer->address1,
            'address2' => $data['address2'] ?? $customer->address2,
            'zip' => $data['zip'] ?? $customer->zip,
            'island' => $data['island'] ?? $customer->island,
            'city' => $data['city'] ?? $customer->city,
            'customer_tag' => $shortcode !== '' ? $shortcode : $customer->customer_tag,
            'risk_rating' => $data['risk_rating'] ?? $customer->risk_rating,
            'occupation' => $data['occupation'] ?? $customer->occupation,
            'employment_position_level' => $data['employment_position_level'] ?? $customer->employment_position_level,
            'sms_notification' => array_key_exists('sms_notification', $data) ? (int) $data['sms_notification'] : $customer->sms_notification,
            'email_notification' => array_key_exists('email_notification', $data) ? (int) $data['email_notification'] : $customer->email_notification,
            'is_locked' => array_key_exists('is_locked', $data) ? (int) $data['is_locked'] : $customer->is_locked,
            'is_card_beta_user' => array_key_exists('is_card_beta_user', $data) ? (int) $data['is_card_beta_user'] : $customer->is_card_beta_user,
            'updated_by' => $actor->name ?? $actor->email,
            'updated_on' => now(),
        ]);
        $customer->save();

        if (array_key_exists('is_pep', $data)) {
            $this->setPep($customer, (bool) $data['is_pep'], $actor);
        }

        ActivityLog::recordUpdated($actor, 'Tools - Customer Management', $customer, $before, [
            'first_name', 'middle_name', 'last_name', 'email', 'gender', 'country', 'birthday',
            'address1', 'address2', 'zip', 'island', 'city', 'customer_tag', 'risk_rating',
            'occupation', 'employment_position_level', 'sms_notification', 'email_notification',
            'is_locked', 'is_card_beta_user',
        ]);

        return $this->detail($id);
    }

    /** Legacy: PEP "Yes" upserts an ACTIVE `blocked_list` row; "No" soft-deletes the existing one. */
    private function setPep(Customer $customer, bool $isPep, User $actor): void
    {
        $name = trim((string) $customer->first_name.' '.(string) $customer->last_name);
        if ($name === '') {
            return;
        }

        $table = DB::connection('mysuncash')->table('blocked_list');
        $existing = $table->where('type', 'PEP')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        $actorName = $actor->name ?? $actor->email;

        if ($isPep) {
            if ($existing) {
                $table->where('id', $existing->id)->update(['status' => 'ACTIVE', 'updated_by' => $actorName, 'updated_at' => now()]);
            } else {
                $table->insert(['name' => $name, 'type' => 'PEP', 'type_desc' => 'PEP List', 'status' => 'ACTIVE', 'created_by' => $actorName, 'created_at' => now()]);
            }
        } elseif ($existing) {
            $table->where('id', $existing->id)->update(['status' => 'DELETED', 'updated_by' => $actorName, 'updated_at' => now()]);
        }
    }

    public function notes(int $id): array
    {
        return DB::connection('mysuncash')->table('customer_notes')
            ->where('customer_id', $id)
            ->orderByDesc('id')
            ->get(['id', 'title', 'note', 'status', 'create_date', 'update_date'])
            ->toArray();
    }

    /** @throws ValidationException */
    public function addNote(int $id, string $title, string $note, User $actor): array
    {
        $this->findOrFail($id);

        $noteId = DB::connection('mysuncash')->table('customer_notes')->insertGetId([
            'customer_id' => $id,
            'title' => $title,
            'note' => $note,
            'status' => '1', // `customer_notes.status` is `enum('0','1')` — 1 = active
            'create_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'note_added', "Added a note to customer {$id}");

        return (array) DB::connection('mysuncash')->table('customer_notes')->find($noteId);
    }
}
