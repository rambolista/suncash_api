<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskZoutReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskZoutReportController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page (Zout/Replenish/Transaction tabs share one permission gate). */
    private const MODULE_PATH = '/kiosk/reports';

    public const COLUMNS = [
        ['key' => 'kiosk_id', 'label' => 'Kiosk ID'],
        ['key' => 'location', 'label' => 'Kiosk Location'],
        ['key' => 'date', 'label' => 'Date'],
        ['key' => 'settlement_no', 'label' => 'Settlement No'],
        ['key' => 'user', 'label' => 'User'],
        ['key' => 'previous_settlement', 'label' => 'Previous Settlement'],
        ['key' => 'total_transactions', 'label' => 'Total Transactions'],
    ];

    public function __construct(private readonly KioskZoutReportService $reports) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], 404);
    }

    private function filtersFromRequest(Request $request): array
    {
        return [
            $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            $request->query('location') ?: null,
            $request->query('date') ?: null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        [$branchId, $location, $date] = $this->filtersFromRequest($request);

        return response()->json([
            'data' => $this->reports->list($branchId, $location, $date),
            'branches' => $this->reports->listBranches(),
        ]);
    }

    public function show(Request $request, string $settlementNo): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->reports->details($settlementNo);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        [$branchId, $location, $date] = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');

        $rows = $this->reports->list($branchId, $location, $date);
        $columns = self::COLUMNS;

        ActivityLog::recordAction($request->user(), 'Kiosk Zout Reports', 'exported', 'Exported kiosk zout report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Zout Report',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => array_filter(['branch_id' => $branchId, 'location' => $location, 'date' => $date]),
                'columns' => $columns,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-zout-report-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-zout-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
