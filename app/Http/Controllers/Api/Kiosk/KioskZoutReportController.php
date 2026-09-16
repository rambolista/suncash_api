<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskZoutReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskZoutReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'zout';

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
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        [$branchId, $location, $date] = $this->filtersFromRequest($request);

        return response()->json([
            'data' => $this->reports->list($branchId, $location, $date),
            'branches' => $this->reports->listBranches(),
            'locations' => $this->reports->listLocations(),
        ]);
    }

    public function show(Request $request, string $settlementNo): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
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
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        [$branchId, $location, $date] = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');

        $rows = $this->reports->list($branchId, $location, $date);
        $columns = self::COLUMNS;

        ActivityLog::recordAction($request->user(), 'Kiosk Zout Reports', 'exported', 'Exported kiosk zout report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, $columns, $rows, 'Kiosk Zout Report', 'kiosk-zout-report', array_filter(['branch_id' => $branchId, 'location' => $location, 'date' => $date]));
    }
}
