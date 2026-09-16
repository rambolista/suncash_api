<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskReplenishReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskReplenishReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'replenish';

    private const LIST_COLUMNS = [
        ['key' => 'replenishment_date', 'label' => 'Replenishment Date'],
        ['key' => 'kiosk_terminal', 'label' => 'Kiosk Terminal'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'location', 'label' => 'Location'],
    ];

    private const METER_COLUMNS = [
        ['key' => 'denom', 'label' => 'Denom'],
        ['key' => 'count', 'label' => 'Count'],
        ['key' => 'value', 'label' => 'Total Value'],
    ];

    private const ADD_CASH_COLUMNS = [
        ['key' => 'bin', 'label' => 'Bin'],
        ['key' => 'denom', 'label' => 'Denom'],
        ['key' => 'count', 'label' => 'Count'],
        ['key' => 'value', 'label' => 'Value'],
    ];

    private const CLEAR_ACCEPTOR_COLUMNS = [
        ['key' => 'denom', 'label' => 'Denom'],
        ['key' => 'count', 'label' => 'Count'],
        ['key' => 'value', 'label' => 'Value'],
    ];

    public function __construct(private readonly KioskReplenishReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $terminalId = $request->query('terminal_id') ? (int) $request->query('terminal_id') : null;
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        // Legacy reuses the same unfiltered, grouped-by-terminal query for both the
        // initial table rows AND the "Kiosk Terminal" dropdown's option list — the
        // dropdown always offers every terminal that has any meter data, regardless
        // of the currently-applied filter.
        $allTerminalsWithData = $this->reports->list();

        return response()->json([
            'data' => ($terminalId || $branchId) ? $this->reports->list($terminalId, $branchId) : $allTerminalsWithData,
            'branches' => $this->reports->listBranches(),
            'terminals' => $allTerminalsWithData,
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $branchId = (int) $request->query('branch_id');
        if (! $branchId) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->reports->listTerminalsForBranch($branchId)]);
    }

    public function meter(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->meterDetail($terminalId));
    }

    public function addCash(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->addCashDetail($terminalId));
    }

    public function clearAcceptor(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->clearAcceptorDetail($terminalId));
    }

    private function exportRows(Request $request, string $title, string $filenameBase, array $columns, array $rows): JsonResponse|StreamedResponse|Response
    {
        $format = (string) $request->query('format', 'csv');

        ActivityLog::recordAction($request->user(), 'Kiosk Replenish Reports', 'exported', "Exported {$title} (".strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, $columns, $rows, $title, $filenameBase);
    }

    public function exportList(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $terminalId = $request->query('terminal_id') ? (int) $request->query('terminal_id') : null;
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return $this->exportRows($request, 'Kiosk Replenish Report', 'kiosk-replenish-report', self::LIST_COLUMNS, $this->reports->list($terminalId, $branchId));
    }

    public function exportMeter(Request $request, int $terminalId): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $detail = $this->reports->meterDetail($terminalId);
        $rows = array_map(fn ($row) => $row + ['date' => $detail['date']], $detail['rows']);

        return $this->exportRows($request, "View Meter — Terminal #{$terminalId}", "kiosk-replenish-meter-{$terminalId}", [
            ['key' => 'date', 'label' => 'Replenish Date'],
            ...self::METER_COLUMNS,
        ], $rows);
    }

    public function exportAddCash(Request $request, int $terminalId): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $detail = $this->reports->addCashDetail($terminalId);
        $rows = array_map(fn ($row) => $row + ['date' => $detail['date']], $detail['rows']);

        return $this->exportRows($request, "View Add Cash — Terminal #{$terminalId}", "kiosk-replenish-add-cash-{$terminalId}", [
            ['key' => 'date', 'label' => 'Replenish Date'],
            ...self::ADD_CASH_COLUMNS,
        ], $rows);
    }

    public function exportClearAcceptor(Request $request, int $terminalId): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $detail = $this->reports->clearAcceptorDetail($terminalId);

        return $this->exportRows($request, "View Clear Acceptor — Terminal #{$terminalId}", "kiosk-replenish-clear-acceptor-{$terminalId}", self::CLEAR_ACCEPTOR_COLUMNS, $detail['rows']);
    }
}
