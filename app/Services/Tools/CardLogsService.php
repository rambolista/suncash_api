<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\CheckoutLog;
use App\Models\Mysuncash\Merchant;
use Illuminate\Support\Carbon;

/**
 * "Tools > Card Logs" (legacy `tools/card_logs` + `tools_model::getAllCardLogs()`/
 * `getCardLogsData()`) — a read-only report over `suncashme_checkout_logs`
 * (card-link/checkout attempts), joined to `clients` for the merchant name.
 *
 * Legacy's page also has a second "Cenpos" source, backed not by a local
 * query but by a live SOAP/XML call to Cenpos's own transaction API
 * (`getCenposLogsData()` / `_callTrxApi()`). That's a separate third-party
 * gateway integration, not a query to index, so it's out of scope here —
 * this only ports the Suncash-source log, which is the actual slow query.
 *
 * Legacy's date filter (`date_created >= ...`) was already unwrapped (no
 * `DATE()` around the column), so no query rewrite was needed there — the
 * table just had no index at all besides its primary key, so every load
 * was a full scan regardless of the date range. See the paired migration
 * for the index this adds.
 */
class CardLogsService
{
    private const TYPES = ['cenpos', 'card-link', 'card-linkv3'];

    public const COLUMNS = [
        ['key' => 'date_created', 'label' => 'Timestamp'],
        ['key' => 'merchant_name', 'label' => 'Merchant Name'],
        ['key' => 'order_id', 'label' => 'Order ID'],
        ['key' => 'reference_number', 'label' => 'Transaction ID'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'card_name', 'label' => 'Card Name'],
        ['key' => 'last_4_digit_card', 'label' => 'Last 4 Digit Card Number'],
        ['key' => 'card_type', 'label' => 'Card Type'],
        ['key' => 'source', 'label' => 'Source'],
        ['key' => 'auth_code', 'label' => 'Auth Code'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'message', 'label' => 'Status Description'],
    ];

    /** Defaults to today, matching legacy's initial page load. */
    public function list(?string $dateFrom, ?string $dateTo): array
    {
        $dateFrom ??= now()->toDateString();
        $dateTo ??= $dateFrom;

        $rows = CheckoutLog::whereIn('type', self::TYPES)
            ->where('card_name', '!=', '')
            ->where('response', '!=', 'Successfully Added')
            ->where('date_created', '>=', Carbon::parse($dateFrom)->startOfDay())
            ->where('date_created', '<', Carbon::parse($dateTo)->addDay()->startOfDay())
            ->orderBy('id')
            ->get();

        $merchantNames = Merchant::whereIn('merchant_key', $rows->pluck('merchant_key')->filter()->unique())
            ->pluck('dba_name', 'merchant_key');

        return $rows->map(fn (CheckoutLog $row) => $this->mapRow($row, $merchantNames))->all();
    }

    private function mapRow(CheckoutLog $row, $merchantNames): array
    {
        return [
            'id' => $row->id,
            'date_created' => $row->date_created,
            'merchant_name' => $merchantNames[$row->merchant_key] ?? null,
            'order_id' => $row->order_id,
            'reference_number' => $row->reference_number,
            'amount' => $row->amount,
            'card_name' => $row->card_name,
            'last_4_digit_card' => $row->last_4_digit_card,
            'card_type' => $row->card_type,
            'source' => $row->source,
            'auth_code' => $row->auth_code,
            'status' => $row->status,
            'message' => $row->message,
        ];
    }
}
