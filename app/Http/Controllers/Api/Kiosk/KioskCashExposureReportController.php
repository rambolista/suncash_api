<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCashExposureReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskCashExposureReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'cash_exposure';

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

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, self::TAB_KEY, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function filtersFromRequest(Request $request): array
    {
        return [
            'terminal_id' => $request->query('terminal_id') ? (int) $request->query('terminal_id') : null,
            'island_id' => $request->query('island_id') ? (int) $request->query('island_id') : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $f = $this->filtersFromRequest($request);
        $rows = $this->reports->list($f['terminal_id'], $f['island_id'])['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Cash Exposure Report', 'exported', 'Exported Kiosk Cash Exposure Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Cash Exposure Report',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => [],
                'columns' => self::LIST_COLUMNS,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-cash-exposure-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-cash-exposure-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column(self::LIST_COLUMNS, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', self::LIST_COLUMNS));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
