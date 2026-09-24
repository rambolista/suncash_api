<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\SmsGatewayLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Tools > SMS Logs" — legacy `sms_logs/index` (`smsgateway_logs` joined to
 * `smsgateway_err_codes`). Legacy gated the whole feature behind a hardcoded
 * admin-username whitelist instead of the real permission system — replaced
 * here by the normal can_view/can_export menu permissions.
 *
 * 45k+ rows and growing with zero prior indexing (see the accompanying
 * migration), so this is real server-side pagination, not a
 * fetch-everything-then-paginate-client-side page like the smaller Tools
 * reports.
 */
class SmsLogsService
{
    public const COLUMNS = [
        ['key' => 'timestamp', 'label' => 'Timestamp'],
        ['key' => 'mobile', 'label' => 'Mobile'],
        ['key' => 'message', 'label' => 'Message'],
        ['key' => 'status_code', 'label' => 'Status Code'],
        ['key' => 'status_desc', 'label' => 'Status Description'],
    ];

    private const PER_PAGE = 300;

    private function baseQuery(?string $dateFrom, ?string $dateTo, ?string $search): Builder
    {
        // Defaults to the last 7 days when no range is given (legacy only ever showed today).
        $from = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : now()->subDays(7)->startOfDay();
        $to = $dateTo ? Carbon::parse($dateTo)->endOfDay() : now()->endOfDay();

        $query = SmsGatewayLog::with('errCode')->whereBetween('timestamp', [$from, $to]);

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('mobile', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function present(SmsGatewayLog $log): array
    {
        return [
            'id' => $log->id,
            'timestamp' => $log->timestamp,
            'mobile' => $log->mobile,
            'message' => $log->message,
            'status_code' => $log->status_code,
            'status_desc' => $log->errCode?->status_desc,
        ];
    }

    public function paginatedList(?string $dateFrom, ?string $dateTo, ?string $search, int $page): array
    {
        $paginator = $this->baseQuery($dateFrom, $dateTo, $search)
            ->orderByDesc('timestamp')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'data' => $paginator->getCollection()->map(fn (SmsGatewayLog $log) => $this->present($log))->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** Export rows for the same filtered scope — capped by the date range like the on-screen list, never the whole table. */
    public function exportRows(?string $dateFrom, ?string $dateTo, ?string $search): array
    {
        return $this->baseQuery($dateFrom, $dateTo, $search)
            ->orderByDesc('timestamp')
            ->get()
            ->map(fn (SmsGatewayLog $log) => $this->present($log))
            ->all();
    }
}
