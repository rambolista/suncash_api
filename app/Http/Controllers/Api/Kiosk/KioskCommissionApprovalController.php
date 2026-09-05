<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCommissionApprovalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskCommissionApprovalController extends Controller
{
    private const MODULE_PATH = '/kiosk/commission-approval';

    private const LIST_COLUMNS = [
        ['key' => 'kiosk', 'label' => 'Kiosk'],
        ['key' => 'island', 'label' => 'Island'],
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'partner_name', 'label' => 'Partner Name'],
        ['key' => 'partner_mobile', 'label' => 'Partner Mobile'],
        ['key' => 'total_amount', 'label' => 'Transaction Volume'],
        ['key' => 'total_revenue', 'label' => 'Revenue'],
        ['key' => 'commission_type', 'label' => 'Commission Type'],
        ['key' => 'commission_rate', 'label' => 'Commission Rate'],
        ['key' => 'commission_payment', 'label' => 'Commission Payment'],
        ['key' => 'status', 'label' => 'Status'],
    ];

    public function __construct(private readonly KioskCommissionApprovalService $approvals) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('transaction_id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function filtersFromRequest(Request $request): array
    {
        $now = now();

        return [
            'year' => (int) $request->query('year', $now->year),
            'month' => (string) $request->query('month', $now->format('F')),
            'status' => $request->query('status') ?: null,
            'location' => $request->query('location') ?: null,
            'partner_name' => $request->query('partner_name') ?: null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $result = $this->approvals->list($f['year'], $f['month'], $f['status'], $f['location'], $f['partner_name']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'statuses' => KioskCommissionApprovalService::STATUS_OPTIONS,
            'locations' => $this->approvals->listLocations(),
        ]);
    }

    public function show(Request $request, string $transactionId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json(['data' => $this->approvals->show($transactionId)]);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function history(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->approvals->history($terminalId)]);
    }

    public function approve(Request $request, string $transactionId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        $data = $request->validate([
            'account_type' => ['nullable', 'string', 'in:SAVINGS,CHECKING'],
            'deposit_type' => ['nullable', 'string', 'in:STANDARD,EXPRESS'],
        ]);

        try {
            $this->approvals->approve($transactionId, $data, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Approval', 'approved', "Approved kiosk commission transaction {$transactionId}", null, $request);

        return response()->json(['message' => 'Commission has been successfully approved.']);
    }

    public function reject(Request $request, string $transactionId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_cancel')) {
            return $response;
        }

        $data = $request->validate(['reject_note' => ['required', 'string']]);

        try {
            $this->approvals->reject($transactionId, $data, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Approval', 'rejected', "Rejected kiosk commission transaction {$transactionId}", null, $request);

        return response()->json(['message' => 'Commission has been successfully rejected.']);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $result = $this->approvals->list($f['year'], $f['month'], $f['status'], $f['location'], $f['partner_name']);
        $rows = $result['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Approval', 'exported', 'Exported Kiosk Commission Approval ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            $maxPdfRows = 1000;
            $truncated = count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => 'Kiosk Commission Approval',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => [
                    'Month' => $f['month'],
                    'Year' => $f['year'],
                ],
                'columns' => self::LIST_COLUMNS,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
            ])->setPaper('a4', 'landscape')->download('kiosk-commission-approval-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-commission-approval-'.now()->format('Ymd-His').'.csv';

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
