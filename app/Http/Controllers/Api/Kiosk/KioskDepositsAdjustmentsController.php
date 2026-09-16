<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskDepositsAdjustmentsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskDepositsAdjustmentsController extends Controller
{
    protected const MODULE_PATH = '/kiosk/deposits-and-adjustments';

    private const LIST_COLUMNS = [
        ['key' => 'kiosk_terminal', 'label' => 'Kiosk'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'create_date', 'label' => 'Timestamp'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'trans_type', 'label' => 'Adjustment Type'],
        ['key' => 'description', 'label' => 'Description'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'notes', 'label' => 'Notes'],
    ];

    public function __construct(private readonly KioskDepositsAdjustmentsService $service)
    {
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function dateFilters(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'date_from' => (string) $request->query('date_from', $today),
            'date_to' => (string) $request->query('date_to', $today),
            'trans_type' => $request->query('trans_type') ?: null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ?: null;
        $terminalId = $request->query('terminal_id') ?: null;

        return response()->json([
            'kiosks' => $this->service->listKiosks($branchId ? (int) $branchId : null, $terminalId ? (int) $terminalId : null),
            'branches' => $this->service->listBranchesForFilter(),
        ]);
    }

    public function showTerminal(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $context = $this->service->getTerminalContext($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $f = $this->dateFilters($request);
        $context['transactions'] = $this->service->listTransactions($id, $f['date_from'], $f['date_to'], $f['trans_type']);
        $context['date_from'] = $f['date_from'];
        $context['date_to'] = $f['date_to'];

        return response()->json($context);
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->dateFilters($request);

        return response()->json([
            'transactions' => $this->service->listTransactions($id, $f['date_from'], $f['date_to'], $f['trans_type']),
        ]);
    }

    public function adjust(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->service->createAdjustment($id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Deposits and Adjustments', 'adjusted', "Recorded a {$request->input('trans_type')} adjustment for kiosk terminal #{$id}.", null, $request);

        return response()->json($result);
    }

    private function exportResponse(array $rows, string $format, string $filenamePrefix, array $filters, Request $request): JsonResponse|StreamedResponse|Response
    {
        ActivityLog::recordAction($request->user(), 'Kiosk Deposits and Adjustments', 'exported', 'Exported Kiosk Deposits and Adjustments ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Deposits and Adjustments',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => $filters,
                'columns' => self::LIST_COLUMNS,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download($filenamePrefix.'-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = $filenamePrefix.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column(self::LIST_COLUMNS, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', self::LIST_COLUMNS));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->dateFilters($request);
        $format = (string) $request->query('format', 'csv');
        $rows = $this->service->listTransactions(null, $f['date_from'], $f['date_to'], $f['trans_type']);

        return $this->exportResponse($rows, $format, 'kiosk-deposits-adjustments', [
            'Date From' => $f['date_from'],
            'Date To' => $f['date_to'],
        ], $request);
    }

    public function exportTerminal(Request $request, int $id): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->dateFilters($request);
        $format = (string) $request->query('format', 'csv');

        try {
            $context = $this->service->getTerminalContext($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $rows = $this->service->listTransactions($id, $f['date_from'], $f['date_to'], $f['trans_type']);

        return $this->exportResponse($rows, $format, 'kiosk-deposits-adjustments-'.$context['terminal']['id'], [
            'Kiosk' => $context['terminal']['name'],
            'Date From' => $f['date_from'],
            'Date To' => $f['date_to'],
        ], $request);
    }
}
