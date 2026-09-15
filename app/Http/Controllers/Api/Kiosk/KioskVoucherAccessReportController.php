<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskVoucherAccessReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskVoucherAccessReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'voucher_access';

    private const LIST_COLUMNS = [
        ['key' => 'date', 'label' => 'Date/Time'],
        ['key' => 'user', 'label' => 'Admin User'],
        ['key' => 'voucher', 'label' => 'Voucher'],
        ['key' => 'ip', 'label' => 'IP Address'],
    ];

    public function __construct(private readonly KioskVoucherAccessReportService $reports)
    {
    }

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
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);

        return response()->json(['data' => $this->reports->list($f['date_from'], $f['date_to'])]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $rows = $this->reports->list($f['date_from'], $f['date_to']);

        ActivityLog::recordAction($request->user(), 'Kiosk Voucher Access Report', 'exported', 'Exported Kiosk Voucher Access Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Voucher Access Report',
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
            ])->setPaper('a4', 'landscape')->download('kiosk-voucher-access-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-voucher-access-report-'.now()->format('Ymd-His').'.csv';

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
