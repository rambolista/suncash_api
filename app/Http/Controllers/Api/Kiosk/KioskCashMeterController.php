<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCashMeterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskCashMeterController extends Controller
{
    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    private const MODULE_PATH = '/kiosk/reports';

    private const TAB_KEY = 'cash_meters';

    public function __construct(private readonly KioskCashMeterService $cashMeters) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, self::TAB_KEY, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'branches' => $this->cashMeters->listBranches(),
            'terminals' => $this->cashMeters->listTerminals(),
            'transaction_types' => KioskCashMeterService::TRANSACTION_TYPES,
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json(['data' => $this->cashMeters->listTerminals($branchId)]);
    }

    private function readMeters(Request $request): ?array
    {
        $terminalId = (int) $request->query('terminal_id');
        $type = (string) $request->query('type');

        if (! $terminalId || ! array_key_exists($type, KioskCashMeterService::TRANSACTION_TYPES)) {
            return null;
        }

        return $this->cashMeters->meters($terminalId, $type);
    }

    public function meters(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $terminalId = (int) $request->query('terminal_id');
        $type = (string) $request->query('type');

        if (! $terminalId) {
            return response()->json(['message' => 'Please select a terminal.'], 422);
        }
        if (! array_key_exists($type, KioskCashMeterService::TRANSACTION_TYPES)) {
            return response()->json(['message' => 'Please select a transaction type.'], 422);
        }

        return response()->json($this->cashMeters->meters($terminalId, $type));
    }

    private function money(mixed $value): string
    {
        return '$'.number_format((float) $value, 2);
    }

    private function exportColumns(bool $isDispenser): array
    {
        return array_values(array_filter([
            ['key' => 'denom', 'label' => 'Denom'],
            ['key' => 'service', 'label' => $isDispenser ? 'Out (Dispensed)' : 'In (Accepted)'],
            ['key' => 'current', 'label' => 'Current'],
            $isDispenser ? ['key' => 'reject', 'label' => 'Reject'] : null,
            ['key' => 'lifetime', 'label' => 'Lifetime'],
        ]));
    }

    private function exportRow(array $row, bool $isDispenser): array
    {
        $formatted = [
            'denom' => $row['denom'],
            'service' => "{$row['service_count']} ({$this->money($row['service_value'])})",
            'current' => "{$row['current_count']} ({$this->money($row['current_value'])})",
            'lifetime' => "{$row['lifetime_count']} ({$this->money($row['lifetime_value'])})",
        ];
        if ($isDispenser) {
            $formatted['reject'] = "{$row['reject_count']} ({$this->money($row['reject_value'])})";
        }

        return $formatted;
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $result = $this->readMeters($request);
        if (! $result) {
            return response()->json(['message' => 'Please select a terminal and a transaction type.'], 422);
        }

        $isDispenser = $result['type'] === 'out';
        $columns = $this->exportColumns($isDispenser);
        $rows = array_map(fn ($row) => $this->exportRow($row, $isDispenser), $result['rows']);
        if ($result['totals']) {
            $rows[] = $this->exportRow(array_merge($result['totals'], ['denom' => 'Total']), $isDispenser);
        }

        $format = (string) $request->query('format', 'csv');
        $typeLabel = KioskCashMeterService::TRANSACTION_TYPES[$result['type']] ?? $result['type'];

        ActivityLog::recordAction($request->user(), 'Kiosk Cash Meters', 'exported', "Exported Kiosk Cash Meters ({$typeLabel}, ".strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => "Kiosk Cash Meters — {$typeLabel}",
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => [],
                'columns' => $columns,
                'rows' => $rows,
                'totalCount' => count($rows),
                'truncated' => false,
            ])->setPaper('a4', 'portrait')->download('kiosk-cash-meters-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kiosk-cash-meters-'.now()->format('Ymd-His').'.csv';

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
