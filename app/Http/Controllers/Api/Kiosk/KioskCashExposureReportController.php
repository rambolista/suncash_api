<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCashExposureReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskCashExposureReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'cash_exposure';

    private const LIST_COLUMNS = [
        ['key' => 'kiosk', 'label' => 'Kiosk'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'cash_acceptor', 'label' => 'Total Cash in Acceptor'],
        ['key' => 'cash_dispenser', 'label' => 'Total Cash Dispenser'],
        ['key' => 'cash_reserve', 'label' => 'Total Cash Reserve'],
        ['key' => 'cash_reject', 'label' => 'Total Cash Reject Bin'],
        ['key' => 'cash_exposure', 'label' => 'Cash Exposure'],
    ];

    public function __construct(private readonly KioskCashExposureReportService $reports) {}

    private function filtersFromRequest(Request $request): array
    {
        return [
            'terminal_id' => $request->query('terminal_id') ? (int) $request->query('terminal_id') : null,
            'island_id' => $request->query('island_id') ? (int) $request->query('island_id') : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $result = $this->reports->list($f['terminal_id'], $f['island_id']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'terminals' => $this->reports->listTerminals(),
            'islands' => $this->reports->listIslands(),
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $f = $this->filtersFromRequest($request);
        $rows = $this->reports->list($f['terminal_id'], $f['island_id'])['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Cash Exposure Report', 'exported', 'Exported Kiosk Cash Exposure Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, self::LIST_COLUMNS, $rows, 'Kiosk Cash Exposure Report', 'kiosk-cash-exposure-report');
    }
}
