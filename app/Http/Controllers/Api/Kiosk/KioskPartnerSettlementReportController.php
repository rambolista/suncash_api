<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskPartnerSettlementReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskPartnerSettlementReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'partner_settlement';

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
            'branch_id' => $request->query('branch_id') ? (int) $request->query('branch_id') : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
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
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $result = $this->reports->list($f['date_from'], $f['date_to'], $f['branch_id']);
        $rows = $result['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Partner Settlement Report', 'exported', 'Exported Kiosk Partner Settlement Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Partner Settlement Report',
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
            ])->setPaper('a4', 'landscape')->download('kiosk-partner-settlement-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-partner-settlement-report-'.now()->format('Ymd-His').'.csv';

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
