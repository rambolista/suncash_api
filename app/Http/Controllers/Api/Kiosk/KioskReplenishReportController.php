<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskReplenishReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskReplenishReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page (Zout/Replenish/Transaction tabs share one permission gate). */
    private const MODULE_PATH = '/kiosk/reports';

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

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->meterDetail($terminalId));
    }

    public function addCash(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->addCashDetail($terminalId));
    }

    public function clearAcceptor(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->reports->clearAcceptorDetail($terminalId));
    }

    private function exportRows(Request $request, string $title, string $filenameBase, array $columns, array $rows): JsonResponse|StreamedResponse|Response
    {
        $format = (string) $request->query('format', 'csv');

        ActivityLog::recordAction($request->user(), 'Kiosk Replenish Reports', 'exported', "Exported {$title} (".strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => $title,
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => [],
                'columns' => $columns,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download($filenameBase.'-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = $filenameBase.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportList(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $terminalId = $request->query('terminal_id') ? (int) $request->query('terminal_id') : null;
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return $this->exportRows($request, 'Kiosk Replenish Report', 'kiosk-replenish-report', self::LIST_COLUMNS, $this->reports->list($terminalId, $branchId));
    }

    public function exportMeter(Request $request, int $terminalId): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $detail = $this->reports->clearAcceptorDetail($terminalId);

        return $this->exportRows($request, "View Clear Acceptor — Terminal #{$terminalId}", "kiosk-replenish-clear-acceptor-{$terminalId}", self::CLEAR_ACCEPTOR_COLUMNS, $detail['rows']);
    }
}
