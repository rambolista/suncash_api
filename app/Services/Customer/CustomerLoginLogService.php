<?php

namespace App\Services\Customer;

use App\Models\Mysuncash\CustomerLoginLog;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Customer Logs" and "Customers > Customer Failed Linking
 * Logs" — legacy `Tools::customer_logs()` / `customer_failed_logs()`, a
 * read-only report of customer app login/card-linking attempts
 * (`customer_login_logs`). Legacy gates both menus behind a hardcoded
 * admin-username whitelist (`settings::$admin_users_for_mng`); this system
 * uses the normal role/menu permission system instead, so no such
 * allowlist is replicated.
 *
 * Both legacy actions default the date range to "today" when no filter is
 * submitted; mirrored here via nullable $from/$to that the controller
 * defaults the same way.
 */
class CustomerLoginLogService
{
    private const TEST_UUIDS = ['77678f702e5674d8', 'J980U1BYWGP513T', 'unknown'];

    private function present(CustomerLoginLog $log): array
    {
        return [
            'id' => $log->id,
            'customer_id' => $log->customer_id,
            'customer_name' => trim((string) $log->customer?->first_name.' '.(string) $log->customer?->last_name) ?: '—',
            'mobile' => $log->customer?->mobile,
            'model' => $log->model,
            'uuid' => $log->uuid,
            'geolocation' => $log->geolocation,
            'country' => $log->country,
            'ip_address' => $log->ip_address,
            'login_tries' => $log->login_tries,
            'source' => $log->source,
            'status' => $log->status,
            'risk_score' => $log->risk_score,
            'risk_reasons' => $log->risk_reasons,
            'message' => $log->message,
            'remarks' => $log->remarks,
            'timestamp' => $log->timestamp,
        ];
    }

    /**
     * Legacy's SUCCESS query excludes a handful of hardcoded test-device
     * UUIDs from this report (kept out of the "real" success log).
     */
    public function successLogs(string $from, string $to): array
    {
        return CustomerLoginLog::with('customer')
            ->whereHas('customer')
            ->whereNotIn('uuid', self::TEST_UUIDS)
            ->where('status', 'SUCCESS')
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->orderByDesc('timestamp')
            ->get()
            ->map(fn (CustomerLoginLog $log) => $this->present($log))
            ->all();
    }

    public function failedLogs(string $from, string $to): array
    {
        return CustomerLoginLog::with('customer')
            ->whereHas('customer')
            ->where('status', 'FAILED')
            ->whereBetween(DB::raw('DATE(timestamp)'), [$from, $to])
            ->orderByDesc('timestamp')
            ->get()
            ->map(fn (CustomerLoginLog $log) => $this->present($log))
            ->all();
    }
}
