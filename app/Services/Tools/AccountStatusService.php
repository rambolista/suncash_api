<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > Lock/Restrict/Restore Account" (legacy
 * `tools::update_customer_account_status()` +
 * `tools_model::update_customer_account()`/`customer_account_histories()`,
 * from `mysuncash-stage` commit `47d840e38` "Initial Commit - Account
 * Management", added to legacy after this app's first Customer Management
 * port). A case-managed status change (reason + free-text note + a case
 * reference), permanently logged to `customer_account_status_histories`.
 *
 * Net status-column effect per `change_type` (worked out from legacy's own
 * convoluted "build then unset then override" array logic, replicated here
 * as the plain end result rather than the intermediate steps):
 * - `locked`: `customers.status = 'L'`, `is_locked = 1`, stamps `locked_*`.
 * - `restricted`: `customers.status = 'R'`, `is_locked = 0`, stamps `restricted_*`.
 * - `restore`: `customers.status = 'A'`, `is_locked = 0`, no `locked_*`/`restricted_*` stamp (legacy leaves those columns as-is).
 */
class AccountStatusService
{
    private const CHANGE_TYPES = ['locked', 'restricted', 'restore'];

    private const STATUS_BY_TYPE = ['locked' => 'L', 'restricted' => 'R', 'restore' => 'A'];

    public function lockReasons(): array
    {
        return $this->reasons('locked');
    }

    public function restrictionReasons(): array
    {
        return $this->reasons('restricted');
    }

    public function restoreReasons(): array
    {
        return $this->reasons('restoration');
    }

    private function reasons(string $type): array
    {
        return DB::connection('mysuncash')->table('account_status_reasons')
            ->where('type', $type)
            ->orderBy('id')
            ->get(['id', 'reason'])
            ->all();
    }

    /** Legacy `customer_account_histories()` — note: legacy's own version builds this SQL via raw string concatenation; parameterized here instead. */
    public function history(int $customerId, string $startDate, string $endDate, ?string $status = null): array
    {
        $query = DB::connection('mysuncash')->table('customer_account_status_histories')
            ->where('customer_identifier', $customerId)
            ->whereBetween('created_date', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);

        if (filled($status)) {
            $query->where('to_status', $status);
        }

        return $query->orderByDesc('id')->get()->all();
    }

    /** @throws ValidationException */
    public function updateStatus(int $customerId, string $currentStatus, string $newStatus, int $reasonId, string $reasonLabel, string $note, string $changeType, string $reference, User $actor): array
    {
        if (! in_array($changeType, self::CHANGE_TYPES, true)) {
            throw ValidationException::withMessages(['change_type' => ['Change type is required.']]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => ['Note is required.']]);
        }
        if (trim($reference) === '') {
            throw ValidationException::withMessages(['reference' => ['Reference is required.']]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['Customer not found.']]);
        }

        $actorName = $actor->name ?? $actor->email;
        $upStatus = self::STATUS_BY_TYPE[$changeType];
        $isLocked = $changeType === 'locked' ? 1 : 0;

        $update = [
            'status' => $upStatus,
            'is_locked' => $isLocked,
            'updated_by' => $actorName,
            'updated_on' => now(),
        ];
        if ($changeType !== 'restore') {
            $update["{$changeType}_by"] = $actorName;
            $update["{$changeType}_date"] = now();
            $update["{$changeType}_reason"] = $reasonLabel;
            $update["{$changeType}_note"] = $note;
            $update["{$changeType}_reference"] = $reference;
        }

        $affected = Customer::where('id', $customerId)->update($update);
        if (! $affected) {
            throw ValidationException::withMessages(['customer_id' => ['Unable to update account status.']]);
        }

        DB::connection('mysuncash')->table('customer_account_status_histories')->insert([
            'customer_identifier' => (string) $customerId,
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'reason_id' => $reasonId,
            'reason_label' => $reasonLabel,
            'note' => $note,
            'reference' => $reference,
            'created_by' => $actorName,
            'created_date' => now(),
        ]);

        return [
            'status' => $upStatus,
            'is_locked' => (bool) $isLocked,
            'by' => $actorName,
            'date' => now()->toDateTimeString(),
            'reason' => $reasonLabel,
            'note' => $note,
        ];
    }
}
