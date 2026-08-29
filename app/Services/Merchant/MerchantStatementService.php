<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\Merchant;
use Illuminate\Validation\ValidationException;

/**
 * "Merchant Statement" — a read-only ledger report over `client_transactions`
 * (legacy `Merchant_ledger` controller / `Merchant_ledger_model`). Legacy's
 * merchant lookup list additionally requires a matching row in the small,
 * largely-vestigial legacy `merchants` table (confirmed live: only 130 of
 * 316 active clients have one) — that restriction isn't replicated here, so
 * every active merchant is searchable, not just the ones legacy's `merchants`
 * table happens to know about.
 */
class MerchantStatementService
{
    public function listMerchants(?string $search = null): array
    {
        $query = Merchant::where('registration_status', 'A');

        if (filled($search)) {
            $query->where(fn ($q) => $q->where('dba_name', 'like', "%{$search}%")
                ->orWhere('suntag_shortcode', 'like', "%{$search}%"));
        }

        return $query->orderBy('dba_name')
            ->get(['id', 'dba_name', 'suntag_shortcode', 'client_prefund'])
            ->map(fn (Merchant $m) => [
                'id' => $m->id,
                'dba_name' => $m->dba_name,
                'suntag_shortcode' => $m->suntag_shortcode,
                'client_prefund' => (float) $m->client_prefund,
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    private function mapRow(ClientTransaction $transaction): array
    {
        $direction = $transaction->transactionType?->direction;

        return [
            'id' => $transaction->id,
            'timestamp' => $transaction->timestamp,
            'amount' => (float) $transaction->amount,
            'description' => $transaction->description,
            'reference_no' => $transaction->ref_trans_id,
            'running_balance' => (float) $transaction->running_balance,
            'available_balance' => (float) $transaction->available_balance,
            'onhold_balance' => (float) $transaction->onhold_balance,
            'transtype' => $direction === 0 ? 'CREDIT' : ($direction === 1 ? 'DEBIT' : 'UNKNOWN'),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function statement(int $merchantId, string $dateFrom, string $dateTo): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $rows = ClientTransaction::with('transactionType')
            ->where('client_record_id', $merchantId)
            ->whereDate('timestamp', '>=', $dateFrom)
            ->whereDate('timestamp', '<=', $dateTo)
            ->whereHas('transactionType', fn ($q) => $q->whereIn('direction', [0, 1]))
            ->orderBy('timestamp')
            ->get()
            ->map(fn (ClientTransaction $t) => $this->mapRow($t))
            ->all();

        return [
            'merchant' => [
                'id' => $merchant->id,
                'dba_name' => $merchant->dba_name,
                'suntag_shortcode' => $merchant->suntag_shortcode,
                'client_prefund' => (float) $merchant->client_prefund,
            ],
            'rows' => $rows,
        ];
    }
}
