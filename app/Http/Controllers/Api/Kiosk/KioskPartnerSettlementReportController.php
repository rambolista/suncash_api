<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskPartnerSettlementReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskPartnerSettlementReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'partner_settlement';

    private const LIST_COLUMNS = [
        ['key' => 'partner', 'label' => 'Partner'],
        ['key' => 'kiosk', 'label' => 'Kiosk'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'cash_collected', 'label' => 'Total Cash-In'],
        ['key' => 'cash_dispensed', 'label' => 'Total Cash-Out'],
        ['key' => 'partner_deposits', 'label' => 'Partner Deposits'],
        ['key' => 'partner_withdrawals', 'label' => 'Partner Withdrawals'],
        ['key' => 'total_fees', 'label' => 'Total Fees'],
        ['key' => 'total_vat', 'label' => 'Total VAT'],
        ['key' => 'commission', 'label' => 'Commission'],
        ['key' => 'net_settlement', 'label' => 'Net Settlement'],
    ];

    public function __construct(private readonly KioskPartnerSettlementReportService $reports) {}

    private function filtersFromRequest(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'date_from' => (string) $request->query('date_from', $today),
            'date_to' => (string) $request->query('date_to', $today),
            'branch_id' => $request->query('branch_id') ? (int) $request->query('branch_id') : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['branch_id']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'partners' => $this->reports->listPartners(),
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['branch_id']);
        $rows = $result['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Partner Settlement Report', 'exported', 'Exported Kiosk Partner Settlement Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, self::LIST_COLUMNS, $rows, 'Kiosk Partner Settlement Report', 'kiosk-partner-settlement-report', [
            'Date From' => $f['date_from'],
            'Date To' => $f['date_to'],
        ]);
    }
}
