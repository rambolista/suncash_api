<?php

namespace App\Services\Customer;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Customers > Archive" — legacy `Tools::archive_customers()` and friends.
 * Legacy's own page is a large, mostly-copy-pasted customer-editing form
 * shared with other (unported) screens — profile editing, linked cards/
 * banks, notes, push notifications, balance adjustment, PIN reset, scanned
 * ID re-upload. Its own "View Details" handler for THIS specific screen
 * (`.btnViewDetailsArchiveCustomer`, confirmed by reading the exact jQuery
 * handler bound to that class) only ever populates the transaction-history
 * panel — none of the profile-editing widgets ever get shown or filled in
 * for this page, and the Archive button itself lives inside that
 * never-shown panel, reading customer id/mobile from fields that are never
 * populated here either. That makes legacy's own Archive button
 * unreachable through this specific screen's actual UI flow.
 *
 * This ports the INTENDED, working shape instead: a full, per-column-
 * searchable list of customers → a compact customer summary + transaction
 * history (with the date filter and Excel/PDF export legacy's page does
 * support) → a working Archive action wired to the correct customer from
 * the list row, behind a confirmation modal. Legacy's own separate
 * search-form step (submit criteria before any results show) isn't
 * replicated — the full list loads immediately and is filtered per column
 * client-side instead, which is faster when you don't know up front which
 * field to search by.
 *
 * "Archiving" a customer (`archive_customer()`) doesn't delete anything —
 * it frees up their mobile number for reuse by appending `_{id}` to
 * `customers.mobile`/`reward_identifier` and `ezkard_accounts.mobile_number`/
 * `card_number`, and sets `customers.status = 'I'`. The list excludes
 * customers already archived this way (mobile containing that `_id` marker)
 * — legacy's own search attempts this exclusion too (comment: "eliminate
 * archive customers") but its actual filter is `LIKE '%_%'`, an unescaped
 * wildcard that matches virtually every row and only claims to work; this
 * expresses the same intent as an escaped, literal match instead.
 */
class CustomerArchiveService
{
    public const COLUMNS = [
        ['key' => 'timestamp', 'label' => 'Date/Time'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'description', 'label' => 'Description'],
        ['key' => 'debit', 'label' => 'Debit'],
        ['key' => 'credit', 'label' => 'Credit'],
        ['key' => 'balance', 'label' => 'Balance'],
    ];

    private function presentListRow(Customer $customer): array
    {
        $ezkard = $customer->ezkardAccount;

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'mobile_number' => $customer->mobile,
            'card_number' => $ezkard?->card_number,
            'merchant' => $ezkard?->merchant?->merchant_name,
            'status' => $customer->status,
        ];
    }

    /**
     * Every non-archived customer, for the DataTable's own per-column
     * search to filter client-side — legacy's separate search-form step
     * (name/mobile/card/email/bank-topup, submitted before any results
     * show) is deliberately not replicated; loading the full list up front
     * and searching per column is faster for an admin who doesn't know
     * which field to search by.
     */
    public function list(): array
    {
        return Customer::with('ezkardAccount.merchant')
            ->where('mobile', 'NOT LIKE', '%\\_%')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(fn (Customer $customer) => $this->presentListRow($customer))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): Customer
    {
        $customer = Customer::with('ezkardAccount.merchant')->find($id);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        return $customer;
    }

    public function getDetail(int $id): array
    {
        $customer = $this->findOrFail($id);
        $ezkard = $customer->ezkardAccount;

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'status' => $customer->status,
            'risk_rating' => $customer->risk_rating,
            'card_number' => $ezkard?->card_number,
            'card_balance' => (float) ($ezkard?->card_balance ?? 0),
            'merchant' => $ezkard?->merchant?->merchant_name,
            'is_archived' => str_contains((string) $customer->mobile, '_'),
        ];
    }

    private function ledgerQuery(EzkardAccount $ezkard)
    {
        return EzkardTransaction::with('transactionType')
            ->where('ezkard_id', $ezkard->id)
            ->orderByDesc('timestamp');
    }

    private function presentTransaction(EzkardTransaction $transaction): array
    {
        $direction = $transaction->transactionType?->direction;
        $isCredit = $direction !== null && (int) $direction === 0;

        return [
            'timestamp' => $transaction->timestamp,
            'transaction_id' => $transaction->transaction_id,
            'description' => $transaction->description,
            'debit' => $isCredit ? 0.0 : (float) $transaction->amount,
            'credit' => $isCredit ? (float) $transaction->amount : 0.0,
            'balance' => (float) $transaction->running_balance,
        ];
    }

    /** The initial "View Details" ledger — latest 10, no date filter (legacy's get_cardholder_transactions()). */
    public function recentTransactions(int $id): array
    {
        $customer = $this->findOrFail($id);
        if (! $customer->ezkardAccount) {
            return [];
        }

        return $this->ledgerQuery($customer->ezkardAccount)
            ->limit(10)
            ->get()
            ->map(fn (EzkardTransaction $transaction) => $this->presentTransaction($transaction))
            ->all();
    }

    /** The date-filtered ledger — every matching row, no limit (legacy's filter_customer_transactions()). */
    public function transactionsInRange(int $id, string $from, string $to): array
    {
        $customer = $this->findOrFail($id);
        if (! $customer->ezkardAccount) {
            return [];
        }

        return $this->ledgerQuery($customer->ezkardAccount)
            ->where('timestamp', '>=', Carbon::parse($from)->startOfDay())
            ->where('timestamp', '<', Carbon::parse($to)->addDay()->startOfDay())
            ->get()
            ->map(fn (EzkardTransaction $transaction) => $this->presentTransaction($transaction))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function archive(int $id, string $actorId, string $actorIp): array
    {
        $customer = $this->findOrFail($id);

        if (str_contains((string) $customer->mobile, '_')) {
            throw ValidationException::withMessages(['id' => ['This customer has already been archived.']]);
        }

        $originalMobile = $customer->mobile;
        $archivedValue = $originalMobile.'_'.$customer->id;

        DB::connection('mysuncash')->transaction(function () use ($customer, $originalMobile, $archivedValue, $actorId, $actorIp) {
            $customer->update([
                'status' => 'I',
                'mobile' => $archivedValue,
                'reward_identifier' => $archivedValue,
            ]);

            EzkardAccount::where('mobile_number', $originalMobile)->update([
                'mobile_number' => $archivedValue,
                'card_number' => $archivedValue,
            ]);

            DB::connection('mysuncash')->table('customer_pre_registrations')
                ->where('mobile_number', $originalMobile)
                ->update(['mobile_number' => $archivedValue]);

            DB::connection('mysuncash')->table('web_logs')->insert([
                'customer_id' => $customer->id,
                'user_ip_address' => $actorIp,
                'user_id' => $actorId,
                'updated_by' => $actorId,
                'data' => $originalMobile,
                'log_type' => 'ARCHIVE_CUSTOMER',
                'cloudflare_ip_address' => $actorIp,
                'updated_date' => now(),
            ]);
        });

        $name = trim((string) $customer->first_name.' '.(string) $customer->last_name) ?: (string) $customer->id;
        ActivityLog::recordAction(User::find($actorId), 'Customer Archive', 'archived', "Archived customer {$name} (mobile {$originalMobile})", $customer, null);

        return ['message' => 'Customer status has been updated.'];
    }
}
