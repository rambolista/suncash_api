<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\RevShareDefinition;
use App\Models\Mysuncash\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Revenue Share Management" (legacy `tools/revshare_management` +
 * `tools_model::get_revshare_definition()`/`get_fee_info()`). Read-only —
 * see `RevShareDefinition` docblock for why (legacy's own Add/Edit/Delete
 * buttons never had a working implementation).
 */
class RevShareManagementService
{
    public const SHARE_TYPES = [
        ['id' => '0', 'name' => 'Fixed / Processing and Recoverable Costs'],
        ['id' => '1', 'name' => 'Percentage'],
    ];

    public function merchants(): array
    {
        $merchants = Merchant::where('registration_status', 'A')
            ->whereNotNull('merchant_name')->where('merchant_name', '!=', '')
            ->orderBy('merchant_name')
            ->get(['id', 'merchant_name'])
            ->map(fn (Merchant $m) => ['id' => (string) $m->id, 'name' => $m->merchant_name])
            ->all();

        return array_merge([['id' => '0', 'name' => 'All Merchants']], $merchants);
    }

    public function transactionTypes(): array
    {
        $types = TransactionType::orderBy('type')
            ->get(['id', 'type'])
            ->map(fn (TransactionType $t) => ['id' => (string) $t->id, 'name' => $t->type])
            ->all();

        return array_merge([['id' => '0', 'name' => 'All Transactions']], $types);
    }

    /**
     * Legacy `get_revshare_definition()` — tries an exact
     * (merchant, transaction-type) match first, then falls back through
     * broader defaults (merchant+all-types, all-merchants+type,
     * all-merchants+all-types) until one has rows. `is_inherited` marks a
     * result that came from a broader rule than what was actually
     * requested (legacy's `*` marker).
     *
     * @throws ValidationException
     */
    public function list(string $merchantId, string $transTypeId, string $shareType): array
    {
        if (! in_array($shareType, ['0', '1'], true)) {
            throw ValidationException::withMessages(['share_type' => ['Invalid share type selected.']]);
        }

        $attempts = [];
        foreach ([[$merchantId, $transTypeId], [$merchantId, '0'], ['0', $transTypeId], ['0', '0']] as $pair) {
            if (! in_array($pair, $attempts, true)) {
                $attempts[] = $pair;
            }
        }

        foreach ($attempts as [$mainClientId, $mainTransTypeId]) {
            $rows = RevShareDefinition::where('main_client_id', $mainClientId)
                ->where('main_transaction_type_id', $mainTransTypeId)
                ->where('share_type', $shareType)
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $isInherited = $mainClientId !== $merchantId || $mainTransTypeId !== $transTypeId;
            $shareClientIds = $rows->pluck('share_client_id')->filter()->unique();
            $merchantNames = Merchant::whereIn('id', $shareClientIds)->pluck('merchant_name', 'id');

            return $rows->map(fn (RevShareDefinition $row) => [
                'id' => $row->id,
                'merchant_name' => $merchantNames[$row->share_client_id] ?? null,
                'share_description' => $row->share_description,
                'share_type' => (string) $row->share_type,
                'share_value' => (float) $row->share_value,
                'is_inherited' => $isInherited,
            ])->all();
        }

        return [];
    }

    /** Legacy `get_fee_info()` — read-only "Related Settings" panel. */
    public function feeInfo(string $merchantId, string $transTypeId): array
    {
        $fee = DB::connection('mysuncash')->table('merchant_fees_and_commissions')
            ->where('client_record_id', $merchantId)
            ->where('main_transaction_type_id', $transTypeId)
            ->first();

        $merchant = DB::connection('mysuncash')->table('merchant_details')
            ->where('client_record_id', $merchantId)
            ->first();

        return [
            'transaction_fee' => $fee->transaction_fee ?? null,
            'commission_per_transaction' => $fee->commission_per_transaction ?? null,
            'revenue_share' => $merchant->revenue_share ?? null,
        ];
    }
}
