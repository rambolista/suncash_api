<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\WebLog;

/**
 * "Kiosk > Reports > Voucher Access" (legacy `fastpay::voucher_access_form`
 * / `voucher_access_filter`, and `tools_model::get_voucher_logs`) — an audit
 * trail of every time an admin looked up a voucher's PIN/details.
 */
class KioskVoucherAccessReportService
{
    public function list(string $dateFrom, string $dateTo): array
    {
        return WebLog::where('log_type', 'GET_VOUCHER_INFO')
            ->whereBetween('updated_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->orderByDesc('updated_date')
            ->get()
            ->map(fn (WebLog $log) => [
                'date' => (string) $log->updated_date,
                'user' => $log->updated_by,
                'voucher' => $log->data,
                'ip' => $log->user_ip_address,
            ])
            ->all();
    }
}
