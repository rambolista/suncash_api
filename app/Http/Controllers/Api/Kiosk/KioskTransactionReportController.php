<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskTransactionReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskTransactionReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'transaction';

    private const LIST_COLUMNS = [
        ['key' => 'datetime', 'label' => 'Date/Time'],
        ['key' => 'terminal_code', 'label' => 'Kiosk'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'product', 'label' => 'Product'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'customer_number', 'label' => 'Customer / Account No'],
        ['key' => 'total_amount', 'label' => 'Cash Received'],
        ['key' => 'fee_amount', 'label' => 'Fee'],
        ['key' => 'vat_amount', 'label' => 'VAT'],
        ['key' => 'total_fees', 'label' => 'Total Fees'],
        ['key' => 'amount', 'label' => 'Product Amount'],
    ];

    public function __construct(private readonly KioskTransactionReportService $reports) {}

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
            'type' => $request->query('type') ?: null,
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
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['type'], $f['terminal_id'], $f['island_id']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'terminals' => $this->reports->listTerminals(),
            'islands' => $this->reports->listIslands(),
            'products' => KioskTransactionReportService::PRODUCT_OPTIONS,
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $f = $this->filtersFromRequest($request);
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['type'], $f['terminal_id'], $f['island_id']);
        $rows = $result['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Transaction Report', 'exported', 'Exported Kiosk Transaction Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Transaction Report',
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
            ])->setPaper('a4', 'landscape')->download('kiosk-transaction-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-transaction-report-'.now()->format('Ymd-His').'.csv';

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
