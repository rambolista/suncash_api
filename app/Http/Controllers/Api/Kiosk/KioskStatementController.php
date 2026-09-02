<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskStatementController extends Controller
{
    private const MODULE_PATH = '/kiosk/statement';

    public const BALANCE_COLUMNS = [
        ['key' => 'create_date', 'label' => 'Registered Date'],
        ['key' => 'machine', 'label' => 'Machine'],
        ['key' => 'island_name', 'label' => 'Island'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'balance', 'label' => 'Balance'],
    ];

    public const LEDGER_COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'Transaction Date'],
        ['key' => 'transaction_id', 'label' => 'Transaction Id'],
        ['key' => 'machine', 'label' => 'Machine'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'transaction_type', 'label' => 'Product'],
        ['key' => 'total_amount', 'label' => 'Total Amount'],
        ['key' => 'finance_type', 'label' => 'Entry Type'],
        ['key' => 'balance', 'label' => 'Balance'],
    ];

    public function __construct(private readonly KioskStatementService $statements) {}

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
        ], 422);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;
        $terminalId = $request->query('terminal_id') ? (int) $request->query('terminal_id') : null;

        return response()->json([
            'data' => $this->statements->balances($branchId, $terminalId),
            'branches' => $this->statements->listBranches(),
            'terminals' => $this->statements->listTerminals($branchId),
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json(['data' => $this->statements->listTerminals($branchId)]);
    }

    public function ledger(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());

        try {
            $data = $this->statements->ledger($terminalId, $dateFrom, $dateTo);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function exportBalances(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;
        $terminalId = $request->query('terminal_id') ? (int) $request->query('terminal_id') : null;
        $format = (string) $request->query('format', 'csv');

        $rows = $this->statements->balances($branchId, $terminalId);
        $columns = self::BALANCE_COLUMNS;

        ActivityLog::recordAction($request->user(), 'Kiosk Statement', 'exported', 'Exported kiosk statement balance report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Statement Balance Report',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => array_filter(['branch_id' => $branchId, 'terminal_id' => $terminalId]),
                'columns' => $columns,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-statement-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-statement-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportLedger(Request $request, int $terminalId): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());
        $format = (string) $request->query('format', 'csv');

        try {
            $data = $this->statements->ledger($terminalId, $dateFrom, $dateTo);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $columns = self::LEDGER_COLUMNS;
        $rows = $data['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Statement', 'exported', "Exported ledger for kiosk terminal {$data['terminal']['code']} ({$dateFrom} to {$dateTo}, ".strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Statement — '.$data['terminal']['name'],
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
                'columns' => $columns,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-statement-ledger-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-statement-ledger-'.now()->format('Ymd-His').'.csv';

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
