<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskReconciliationReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskReconciliationReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'reconciliation';

    /** Legacy `kiosk_recon_report_filter()` rejects any date_from before this — cash-meter data isn't reliable earlier. */
    private const MINIMUM_DATE = '2024-08-02';

    private const LIST_COLUMNS = [
        ['key' => 'kiosk', 'label' => 'Kiosk'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'running_balance', 'label' => 'Balance B/F'],
        ['key' => 'total_cash_in', 'label' => 'Total Cash In'],
        ['key' => 'total_cash_out', 'label' => 'Total Cash Out'],
        ['key' => 'total_fee', 'label' => 'Total Fees'],
        ['key' => 'total_vat', 'label' => 'Total Vat'],
        ['key' => 'credit_adjustments', 'label' => 'Total Credit Adjustment'],
        ['key' => 'debit_adjustments', 'label' => 'Total Debit Adjustment'],
        ['key' => 'total_cash_loaded', 'label' => 'Total Cash Loaded'],
        ['key' => 'total_deposits', 'label' => 'Total Cash Deposit'],
        ['key' => 'cash_movement', 'label' => 'Total Cash Movement'],
        ['key' => 'net_balance', 'label' => 'Net Balance'],
    ];

    public function __construct(private readonly KioskReconciliationReportService $reports) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, self::TAB_KEY, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function filtersFromRequest(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'date_from' => (string) $request->query('date_from', $today),
            'date_to' => (string) $request->query('date_to', $today),
            'terminal_id' => $request->query('terminal_id') ? (int) $request->query('terminal_id') : null,
            'island_id' => $request->query('island_id') ? (int) $request->query('island_id') : null,
        ];
    }

    private function tooEarly(string $dateFrom): ?JsonResponse
    {
        if ($dateFrom < self::MINIMUM_DATE) {
            return response()->json([
                'message' => 'The date you selected is too early. Please choose a date on or after August 2, 2024.',
            ], 422);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        if ($response = $this->tooEarly($f['date_from'])) {
            return $response;
        }

        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['terminal_id'], $f['island_id']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'terminals' => $this->reports->listTerminals(),
            'islands' => $this->reports->listIslands(),
            'minimum_date' => self::MINIMUM_DATE,
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        if ($response = $this->tooEarly($f['date_from'])) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['terminal_id'], $f['island_id']);
        $rows = $result['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Reconciliation Report', 'exported', 'Exported Kiosk Reconciliation Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Reconciliation Report',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => [
                    'Date From' => $f['date_from'],
                    'Date To' => $f['date_to'],
                ],
                'columns' => self::LIST_COLUMNS,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-reconciliation-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-reconciliation-report-'.now()->format('Ymd-His').'.csv';

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
