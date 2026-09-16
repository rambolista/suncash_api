<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskVoucherAccessReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskVoucherAccessReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'voucher_access';

    private const LIST_COLUMNS = [
        ['key' => 'date', 'label' => 'Date/Time'],
        ['key' => 'user', 'label' => 'Admin User'],
        ['key' => 'voucher', 'label' => 'Voucher'],
        ['key' => 'ip', 'label' => 'IP Address'],
    ];

    public function __construct(private readonly KioskVoucherAccessReportService $reports)
    {
    }

    private function filtersFromRequest(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'date_from' => (string) $request->query('date_from', $today),
            'date_to' => (string) $request->query('date_to', $today),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);

        return response()->json(['data' => $this->reports->list($f['date_from'], $f['date_to'])]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $rows = $this->reports->list($f['date_from'], $f['date_to']);

        ActivityLog::recordAction($request->user(), 'Kiosk Voucher Access Report', 'exported', 'Exported Kiosk Voucher Access Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, self::LIST_COLUMNS, $rows, 'Kiosk Voucher Access Report', 'kiosk-voucher-access-report', [
            'Date From' => $f['date_from'],
            'Date To' => $f['date_to'],
        ]);
    }
}
