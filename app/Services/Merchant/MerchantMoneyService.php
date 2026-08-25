<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\AdminTransaction;
use App\Models\Mysuncash\AgentCommissionEmail;
use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merchant Prefund, Auto Replenish, and Agent Commission Settings — all three
 * are, in the legacy admin, just targeted updates against the `clients` row
 * (plus prefund's audit trail across admin_transactions/client_transactions/
 * merchant_ledger, and commission's notification email list).
 */
class MerchantMoneyService
{
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    // ── Prefund ──────────────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function adjustPrefund(int $merchantId, string $type, float $amount, string $description, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        if ($amount < 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be zero or greater.']]);
        }
        if (! filled($description)) {
            throw ValidationException::withMessages(['description' => ['Description is required.']]);
        }

        $isCredit = $type === 'credit';
        if (! $isCredit && (float) $merchant->client_prefund < $amount) {
            throw ValidationException::withMessages(['amount' => ['Insufficient client balance.']]);
        }

        $newBalance = DB::connection('mysuncash')->transaction(function () use ($merchant, $isCredit, $amount, $description, $actorId) {
            $isCredit ? $merchant->increment('client_prefund', $amount) : $merchant->decrement('client_prefund', $amount);
            $merchant->refresh();

            $now = now();
            $transTypeId = $isCredit ? 4 : 5;
            $note = $isCredit ? 'Prefund balance credited by admin' : 'Prefund balance debited by admin';

            AdminTransaction::create([
                'client_record_id' => $merchant->id,
                'trans_type_id' => $transTypeId,
                'amount' => $amount,
                'description' => $description,
                'timestamp' => $now,
                'admin_user_id' => $actorId,
            ]);

            ClientTransaction::create([
                'client_record_id' => $merchant->id,
                'user_type_id' => 1,
                'trans_type_id' => $transTypeId,
                'amount' => $amount,
                'description' => $note,
                'timestamp' => $now,
                'is_merchant' => 1,
                'merchant_id' => $merchant->id,
                'running_balance' => $merchant->client_prefund,
                'available_balance' => $merchant->client_prefund,
            ]);

            MerchantLedger::create([
                'merchant_id' => $merchant->id,
                'amount' => $amount,
                'description' => $description,
                'running_balance' => $merchant->client_prefund,
                'reference_no' => 'PF-' . $now->format('YmdHis') . '-' . $merchant->id,
                'trans_type' => $isCredit ? MerchantLedger::TYPE_CREDIT : MerchantLedger::TYPE_DEBIT,
                'created_by' => $actorId,
            ]);

            return $merchant->client_prefund;
        });

        return ['client_prefund' => (float) $newBalance];
    }

    // ── Auto replenish ───────────────────────────────────────────────────────

    public function getAutoReplenishSettings(int $merchantId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        return [
            'is_auto_replenish' => (bool) $merchant->is_auto_replenish,
            'amount' => (float) $merchant->auto_replenish_amount,
            'min_amount' => (float) $merchant->min_auto_replenish_amount,
            'remarks' => (string) $merchant->replenishment_remarks,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function updateAutoReplenishSettings(int $merchantId, array $data): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $enabled = (bool) ($data['is_auto_replenish'] ?? false);

        if (! $enabled) {
            $merchant->update([
                'is_auto_replenish' => 0,
                'auto_replenish_amount' => 0,
                'min_auto_replenish_amount' => 0,
                'replenishment_remarks' => '',
            ]);

            return $this->getAutoReplenishSettings($merchantId);
        }

        $amount = $data['amount'] ?? null;
        $minAmount = $data['min_amount'] ?? null;
        $remarks = trim((string) ($data['remarks'] ?? ''));

        $errors = [];
        if (! is_numeric($amount) || $amount < 0) {
            $errors['amount'] = ['Enter a valid amount.'];
        }
        if (! is_numeric($minAmount) || $minAmount < 0) {
            $errors['min_amount'] = ['Enter a valid minimum balance.'];
        }
        if (! filled($remarks)) {
            $errors['remarks'] = ['Remarks are required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $merchant->update([
            'is_auto_replenish' => 1,
            'auto_replenish_amount' => (float) $amount,
            'min_auto_replenish_amount' => (float) $minAmount,
            'replenishment_remarks' => $remarks,
        ]);

        return $this->getAutoReplenishSettings($merchantId);
    }

    // ── Agent commission settings ───────────────────────────────────────────

    public const COMMISSION_TYPES = [
        1 => 'Fixed',
        2 => 'Percentage',
        3 => 'Greater Amount',
        4 => 'Fixed + Percentage',
    ];

    public function getAgentCommissionSettings(int $merchantId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $typeId = (int) ($merchant->commi_type_id ?: 1);

        return [
            'commi_type_id' => $typeId,
            'commi_type_label' => self::COMMISSION_TYPES[$typeId] ?? self::COMMISSION_TYPES[4],
            'commi_fixed' => (float) $merchant->commi_fixed,
            'commi_percentage' => (float) $merchant->commi_percentage,
            'wu_commi_percentage' => (float) $merchant->wu_commi_percentage,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function updateAgentCommissionSettings(int $merchantId, array $data): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $typeId = (int) ($data['commi_type_id'] ?? $merchant->commi_type_id);
        $fixed = $data['commi_fixed'] ?? $merchant->commi_fixed;
        $percentage = $data['commi_percentage'] ?? $merchant->commi_percentage;
        $wuPercentage = $data['wu_commi_percentage'] ?? $merchant->wu_commi_percentage;

        $errors = [];
        if (! array_key_exists($typeId, self::COMMISSION_TYPES)) {
            $errors['commi_type_id'] = ['Select a valid commission type.'];
        }
        foreach (['commi_fixed' => $fixed, 'commi_percentage' => $percentage, 'wu_commi_percentage' => $wuPercentage] as $field => $value) {
            if (! is_numeric($value) || $value < 0) {
                $errors[$field] = ['Enter a valid non-negative amount.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $merchant->update([
            'commi_type_id' => $typeId,
            'commi_fixed' => (float) $fixed,
            'commi_percentage' => (float) $percentage,
            'wu_commi_percentage' => (float) $wuPercentage,
        ]);

        return $this->getAgentCommissionSettings($merchantId);
    }

    public function listAgentCommissionEmails(int $merchantId): array
    {
        return AgentCommissionEmail::where('client_record_id', $merchantId)
            ->orderBy('id')
            ->get(['id', 'email', 'status'])
            ->toArray();
    }

    /**
     * @throws ValidationException
     */
    public function addAgentCommissionEmail(int $merchantId, string $email): array
    {
        $this->findMerchantOrFail($merchantId);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Enter a valid e-mail address.']]);
        }

        $row = AgentCommissionEmail::create([
            'client_record_id' => $merchantId,
            'email' => $email,
            'status' => 'enabled',
        ]);

        return $row->only(['id', 'email', 'status']);
    }

    /**
     * @throws ValidationException
     */
    public function updateAgentCommissionEmail(int $merchantId, int $emailId, ?string $email, ?string $status): array
    {
        $row = AgentCommissionEmail::where('client_record_id', $merchantId)->where('id', $emailId)->first();
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['E-mail entry not found.']]);
        }

        $attributes = [];
        if ($email !== null) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email' => ['Enter a valid e-mail address.']]);
            }
            $attributes['email'] = $email;
        }
        if ($status !== null) {
            $attributes['status'] = $status;
        }

        $row->update($attributes);

        return $row->only(['id', 'email', 'status']);
    }

    /**
     * @throws ValidationException
     */
    public function deleteAgentCommissionEmail(int $merchantId, int $emailId): void
    {
        $deleted = AgentCommissionEmail::where('client_record_id', $merchantId)->where('id', $emailId)->delete();
        if (! $deleted) {
            throw ValidationException::withMessages(['id' => ['E-mail entry not found.']]);
        }
    }
}
