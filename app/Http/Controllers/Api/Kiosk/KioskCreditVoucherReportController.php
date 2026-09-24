<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCreditVoucherReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KioskCreditVoucherReportController extends Controller
{
    use ExportsTabularReports;

    /** Consolidated under the "Kiosk > Reports" tabbed page — permission is gated per-tab (see `menu_tabs`), not on the parent menu. */
    protected const MODULE_PATH = '/kiosk/reports';

    protected const TAB_KEY = 'credit_voucher';

    public function __construct(private readonly KioskCreditVoucherReportService $reports) {}

    private function filtersFromRequest(Request $request): array
    {
        $today = now()->toDateString();

        return [
            'date_from' => (string) $request->query('date_from', $today),
            'date_to' => (string) $request->query('date_to', $today),
            'branch_id' => $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            'terminal_id' => $request->query('terminal_id') ? (int) $request->query('terminal_id') : null,
            'island_id' => $request->query('island_id') ? (int) $request->query('island_id') : null,
            'voucher_code' => $request->query('voucher_code') ?: null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);

        return response()->json([
            'data' => $this->reports->list($f['date_from'], $f['date_to'], $f['branch_id'], $f['terminal_id'], $f['island_id'], $f['voucher_code']),
            'totals' => $this->reports->totalSummary($f['date_from'], $f['date_to'], $f['branch_id'], $f['terminal_id'], $f['island_id'], $f['voucher_code']),
            'branches' => $this->reports->listBranches(),
            'islands' => $this->reports->listIslands(),
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        if ($response = $this->forbiddenTab($request, 'can_view')) {
            return $response;
        }

        $branchId = (int) $request->query('branch_id');
        if (! $branchId) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->reports->listTerminalsForBranch($branchId)]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbiddenTab($request, 'can_export')) {
            return $response;
        }

        $f = $this->filtersFromRequest($request);
        $format = (string) $request->query('format', 'csv');
        $rows = $this->reports->list($f['date_from'], $f['date_to'], $f['branch_id'], $f['terminal_id'], $f['island_id'], $f['voucher_code']);
        $totals = $this->reports->totalSummary($f['date_from'], $f['date_to'], $f['branch_id'], $f['terminal_id'], $f['island_id'], $f['voucher_code']);

        ActivityLog::recordAction($request->user(), 'Kiosk Credit Voucher Report', 'exported', 'Exported Kiosk Credit Voucher Report ('.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport(
            $request,
            $format,
            KioskCreditVoucherReportService::COLUMNS,
            $rows,
            'Kiosk Credit Voucher Report',
            'kiosk-credit-voucher-report',
            array_filter(['Date From' => $f['date_from'], 'Date To' => $f['date_to'], 'Voucher Code' => $f['voucher_code']]),
            summary: [
                'Total Transaction Count' => $totals['transaction_count'],
                'Total Cash Received' => number_format($totals['total_cash_received'], 2),
                'Total Fees' => number_format($totals['total_fees'], 2),
                'Total VAT' => number_format($totals['total_vat'], 2),
                'Grand Total Fees' => number_format($totals['grand_total_fees'], 2),
                'Total Voucher Amount' => number_format($totals['total_product_amount'], 2),
            ],
        );
    }
}
