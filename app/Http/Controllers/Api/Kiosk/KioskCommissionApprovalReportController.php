<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCommissionApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Kiosk > Reports > Commission Approval Report" — read-only counterpart of "Kiosk > Commission Approval" (`KioskCommissionApprovalController`), no approve/reject actions. */
class KioskCommissionApprovalReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'commission_approval';

    private const LIST_COLUMNS = [
        ['key' => 'create_date', 'label' => 'Transaction Date'],
        ['key' => 'kiosk', 'label' => 'Kiosk'],
        ['key' => 'partner_name', 'label' => 'Partner Name'],
        ['key' => 'partner_mobile', 'label' => 'Partner Mobile'],
        ['key' => 'total_amount', 'label' => 'Transaction Volume'],
        ['key' => 'total_revenue', 'label' => 'Revenue'],
        ['key' => 'commission_type', 'label' => 'Commission Type'],
        ['key' => 'commission_rate', 'label' => 'Commission Rate'],
        ['key' => 'commission_payment', 'label' => 'Commission Payment'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'decided_by', 'label' => 'Approved/Rejected By'],
        ['key' => 'note', 'label' => 'Note'],
    ];

    public function __construct(private readonly KioskCommissionApprovalService $approvals) {}

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
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $result = $this->approvals->report($f['year'], $f['month'], $f['status'], $f['location'], $f['partner_name']);

        return response()->json([
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'statuses' => KioskCommissionApprovalService::STATUS_OPTIONS,
            'locations' => $this->approvals->listLocations(),
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $rows = $this->approvals->report($f['year'], $f['month'], $f['status'], $f['location'], $f['partner_name'])['rows'];

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Approval Report', 'exported', 'Exported Kiosk Commission Approval Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, self::LIST_COLUMNS, $rows, 'Kiosk Commission Approval Report', 'kiosk-commission-approval-report', [
            'Month' => $f['month'],
            'Year' => $f['year'],
        ]);
    }
}
