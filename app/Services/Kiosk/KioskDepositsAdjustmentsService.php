<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Branch;
use App\Models\Mysuncash\KioskAdminTransactionType;
use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskTerminal;
use App\Models\Mysuncash\KioskTerminalTransaction;
use App\Models\User;
use App\Services\Merchant\MerchantMoneyService;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Deposits and Adjustments" (legacy `fastpay::debit_credit_tool_view`
 * / `get_kiosk_debit_credit_tool` / `adjust_kiosk_transaction` /
 * `kiosk_adjustment_filter` / `export_all_kiosk_depo_and_adjustments`, and
 * `kiosk_model::get_kiosk_adjustment_transactions` / `kiosk_adjustment_transaction`
 * / `get_kiosk_admin_trans_types` / `get_merchant_branch_list`).
 */
class KioskDepositsAdjustmentsService
{
    /** Mirrors legacy's fixed Deposit Location option set (kiosk_admin_transaction_types.action = deposit_location). */
    public const DEPOSIT_LOCATIONS_REQUIRING_AMOUNT_ONLY = ['Bank Deposit'];

    public function __construct(private readonly MerchantMoneyService $merchantMoney)
    {
    }

    // ── Lookups ──────────────────────────────────────────────────────────────

    private function presentKiosk(KioskTerminal $terminal): array
    {
        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'location' => $terminal->location,
            'island_name' => $terminal->islandRecord?->name,
            'branch_id' => $terminal->kiosk_branch_id,
            'branch_name' => $terminal->branch?->name,
        ];
    }

    public function listKiosks(?int $branchId = null, ?int $terminalId = null): array
    {
        return KioskTerminal::with(['branch', 'islandRecord'])
            ->where('status', KioskTerminal::STATUS_ACTIVE)
            ->when($branchId, fn ($query) => $query->where('kiosk_branch_id', $branchId))
            ->when($terminalId, fn ($query) => $query->where('id', $terminalId))
            ->orderBy('name')
            ->get()
            ->map(fn (KioskTerminal $terminal) => $this->presentKiosk($terminal))
            ->all();
    }

    public function listBranchesForFilter(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findTerminalOrFail(int $terminalId): KioskTerminal
    {
        $terminal = KioskTerminal::with(['branch.merchant', 'islandRecord'])
            ->where('status', KioskTerminal::STATUS_ACTIVE)
            ->find($terminalId);

        if (! $terminal) {
            throw ValidationException::withMessages(['id' => ['Kiosk terminal not found.']]);
        }

        return $terminal;
    }

    /**
     * @throws ValidationException
     */
    public function getTerminalContext(int $terminalId): array
    {
        $terminal = $this->findTerminalOrFail($terminalId);

        return [
            'terminal' => $this->presentKiosk($terminal),
            'merchant_id' => $terminal->branch?->client_id,
            'terminals' => $this->listKiosks(),
            'stores' => $this->listStores(),
            'transaction_types' => $this->getTransactionTypes(),
        ];
    }

    /** Legacy `get_merchant_branch_list()` with no args — every merchant's active physical branch, globally (not scoped to the kiosk's own merchant). */
    public function listStores(): array
    {
        return Branch::with('merchant')
            ->where('status', Branch::STATUS_ACTIVE)
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => trim(($branch->merchant?->legal_name ?: $branch->merchant?->dba_name ?: '').' - '.$branch->description),
            ])
            ->all();
    }

    /** Grouped by `action`: debit, credit, deposit_location, recycled_destination. */
    public function getTransactionTypes(): array
    {
        $rows = KioskAdminTransactionType::where('status', 'active')->orderBy('id')->get();

        return $rows->groupBy('action')
            ->map(fn ($group) => $group->map(fn (KioskAdminTransactionType $type) => [
                'id' => $type->id,
                'name' => $type->transaction_types,
            ])->values()->all())
            ->all();
    }

    // ── Transactions ─────────────────────────────────────────────────────────

    public function listTransactions(?int $terminalId, string $dateFrom, string $dateTo, ?string $transType = null): array
    {
        return KioskTerminalTransaction::query()
            ->select('kiosk_terminal_transactions.*', 'kiosk_terminal.name as kiosk_name', 'kiosk_terminal.location as kiosk_location')
            ->join('kiosk_terminal', 'kiosk_terminal.id', '=', 'kiosk_terminal_transactions.terminal_id')
            ->whereBetween('kiosk_terminal_transactions.create_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->when($terminalId, fn ($query) => $query->where('kiosk_terminal_transactions.terminal_id', $terminalId))
            ->when($transType, fn ($query) => $query->where('kiosk_terminal_transactions.trans_type', $transType))
            ->orderByDesc('kiosk_terminal_transactions.create_date')
            ->get()
            ->map(fn (KioskTerminalTransaction $row) => [
                'id' => $row->id,
                'kiosk_terminal' => $row->kiosk_name,
                'location' => $row->kiosk_location,
                'create_date' => optional($row->create_date)->format('Y-m-d H:i:s') ?? $row->create_date,
                'transaction_id' => $row->transaction_id,
                'trans_type' => strtoupper((string) $row->trans_type),
                'description' => $row->description,
                'amount' => (float) $row->amount,
                'notes' => $row->notes,
            ])
            ->all();
    }

    // ── Adjustment ───────────────────────────────────────────────────────────

    /**
     * Mirrors `fastpay::adjust_kiosk_transaction()` + `kiosk_model::kiosk_adjustment_transaction()`.
     *
     * @throws ValidationException
     */
    public function createAdjustment(int $terminalId, array $data, User $actor): array
    {
        $terminal = $this->findTerminalOrFail($terminalId);
        $transType = (string) ($data['trans_type'] ?? '');
        $notes = $data['notes'] ?? null;
        $actorName = $actor->name ?? $actor->email;

        $insert = [
            'client_id' => $terminal->branch?->client_id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) time(),
            'trans_type' => $transType,
            'notes' => $notes,
            'create_by' => $actorName,
            'current_balance' => 0,
        ];

        if (in_array($transType, ['debit', 'credit'], true)) {
            $amount = (float) ($data['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Please enter a valid amount.']]);
            }

            $merchantId = $terminal->branch?->client_id;
            if (! $merchantId) {
                throw ValidationException::withMessages(['id' => ['This kiosk terminal has no owning merchant to adjust.']]);
            }

            $reason = (string) ($data['reason'] ?? '');
            $insert['amount'] = $amount;
            $insert['description'] = $reason;
            $insert['deposit_status'] = 'adjustment';

            KioskTerminalTransaction::create($insert);

            $description = 'Kiosk Terminal Adjustment ('.$insert['transaction_id'].') - '.strtoupper($transType);
            $this->merchantMoney->adjustPrefund($merchantId, $transType, $amount, $description, (string) $actor->id);

            return ['success' => true, 'message' => 'Transaction has been processed.'];
        }

        if ($transType === 'deposit') {
            $this->applyDepositFields($insert, $terminal, $data);
            $insert['deposit_status'] = 'pending';
            KioskTerminalTransaction::create($insert);

            return ['success' => true, 'message' => 'Transaction has been processed.'];
        }

        throw ValidationException::withMessages(['trans_type' => ['Please enter a valid adjustment type.']]);
    }

    /**
     * @throws ValidationException
     */
    private function applyDepositFields(array &$insert, KioskTerminal $terminal, array $data): void
    {
        $depositLocation = (string) ($data['deposit_location'] ?? '');
        $recycledAmt = (float) ($data['recyclable_amount'] ?? 0);
        $nonRecycledAmt = (float) ($data['non_recyclable_amount'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);

        if ($depositLocation === '') {
            throw ValidationException::withMessages(['deposit_location' => ['Deposit Location is required.']]);
        }

        $isRecycled = str_contains($depositLocation, 'Recycled');
        $isStore = str_contains($depositLocation, 'SunCash Store');
        $isHeldTemporary = str_contains($depositLocation, 'Held Temporary');

        $insert['deposit_location'] = $depositLocation;
        $insert['recyclable_amount'] = $recycledAmt;
        $insert['non_recyclable_amount'] = $nonRecycledAmt;
        $insert['is_non_recyclable'] = $nonRecycledAmt > 0 ? 1 : 0;

        if ($isRecycled) {
            if ($recycledAmt <= 0 && $nonRecycledAmt <= 0) {
                throw ValidationException::withMessages(['recyclable_amount' => ['Please enter any recyclable bills.']]);
            }

            $depositTerminalId = $data['deposit_terminal_id'] ?? null;
            if (str_contains($depositLocation, 'Another Kiosk')) {
                if (! filled($depositTerminalId)) {
                    throw ValidationException::withMessages(['deposit_terminal_id' => ['Please select another kiosk.']]);
                }
                $insert['deposit_terminal_id'] = $depositTerminalId;
            } else {
                $insert['deposit_terminal_id'] = $terminal->id;
            }

            $insert['amount'] = $nonRecycledAmt > 0 && $recycledAmt > 0
                ? $nonRecycledAmt + $recycledAmt
                : ($nonRecycledAmt > 0 ? $nonRecycledAmt : $recycledAmt);

            if ($nonRecycledAmt > 0) {
                $recycledDestId = $data['recyclable_deposit_dest'] ?? null;
                $recycledDestName = (string) ($data['recycle_location'] ?? '');
                if (! filled($recycledDestId) || $recycledDestName === '') {
                    throw ValidationException::withMessages(['recyclable_deposit_dest' => ['Non Recyclabled Bills Destination is required.']]);
                }

                $insert['recyclable_deposit_dest'] = $recycledDestId;
                $insert['recycle_location'] = $recycledDestName;

                if (str_contains($recycledDestName, 'SunCash Store')) {
                    $storeId = $data['deposit_store_id'] ?? null;
                    if (! filled($storeId)) {
                        throw ValidationException::withMessages(['deposit_store_id' => ['Please select a store.']]);
                    }
                    $insert['deposit_store_id'] = $storeId;
                    $insert['description'] = 'Sent to ('.($data['deposit_store_name'] ?? '').') Store';
                } elseif (str_contains($recycledDestName, 'Location Temporary')) {
                    $heldAt = (string) ($data['location_at'] ?? '');
                    if ($heldAt === '') {
                        throw ValidationException::withMessages(['location_at' => ['Please add a Temporary Location.']]);
                    }
                    $insert['location_at'] = $heldAt;
                    $insert['description'] = "Held at {$heldAt}";
                } else {
                    $insert['description'] = $recycledDestName;
                }
            } else {
                $insert['description'] = str_contains($depositLocation, 'Another Kiosk')
                    ? 'Recycled to Another Kiosk ('.($data['deposit_terminal_name'] ?? '').')'
                    : 'Recycled to Same Kiosk';
            }

            $insert['held_since'] = $data['held_since'] ?? now()->toDateTimeString();

            return;
        }

        if ($isStore) {
            $storeId = $data['deposit_store_id'] ?? null;
            if (! filled($storeId)) {
                throw ValidationException::withMessages(['deposit_store_id' => ['Please select a store.']]);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Please enter a valid amount.']]);
            }
            $insert['deposit_store_id'] = $storeId;
            $insert['amount'] = $amount;
            $insert['description'] = 'Deposited to ('.($data['deposit_store_name'] ?? '').') Store';

            return;
        }

        if ($isHeldTemporary) {
            $heldAt = (string) ($data['location_at'] ?? '');
            if ($heldAt === '') {
                throw ValidationException::withMessages(['location_at' => ['Please add a Temporary Location.']]);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Please enter a valid amount.']]);
            }
            $insert['location_at'] = $heldAt;
            $insert['amount'] = $amount;
            $insert['description'] = "Held at {$heldAt}";

            return;
        }

        // Bank Deposit and any other plain deposit-location option.
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Please enter a valid amount.']]);
        }
        $insert['amount'] = $amount;
        $insert['description'] = $depositLocation;
    }
}
