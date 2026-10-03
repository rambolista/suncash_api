<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Reports\TransactionsReportService as Report;
use App\Services\Reports\WebposReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/reports/transactions';

    public function __construct(private readonly Report $report, private readonly WebposReceiptService $receipts) {}

    /** Merchant dropdown + transaction-type dropdown (legacy `getTrasTypeList` / `client_list`). */
    public function options(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['merchants' => $this->report->merchants(), 'types' => $this->report->typeOptions()]);
    }

    /** Users + branches for the chosen merchant (legacy `getMerchantTerminalUserList`, `getBranchbyMerchant`, `getTerminalUserListByBranch`). */
    public function scope(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $v = $request->validate(['merchant_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'only' => ['nullable', 'in:users,branches']]);
        $branchId = $v['branch_id'] ?? null;

        return response()->json([
            'users' => $this->report->users($v['merchant_id'], $branchId),
            // Picking a branch narrows the user list only — the branch list itself stays as it was.
            'branches' => ($v['only'] ?? null) === 'users' ? null : $this->report->branches($v['merchant_id']),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        [$merchant, $type, $user, $branch, $from, $to] = $this->filters($request);

        return response()->json($this->report->summary($merchant, $type, $user, $branch, $from, $to));
    }

    public function details(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        [$merchant, $type, $user, $branch, $from, $to] = $this->filters($request, detail: true);

        return response()->json($this->report->details($type, $merchant, $user, $branch, $from, $to));
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        // `detail=1` exports the open detail list (all rows, not just the visible page) — legacy exported whichever table was on screen.
        $detail = $request->boolean('detail');
        [$merchant, $type, $user, $branch, $from, $to] = $this->filters($request, $detail);
        $format = (string) $request->query('format', 'csv');
        $filters = array_filter(['merchant' => $merchant, 'type' => Report::TYPES[$type], 'user' => $user, 'branch' => $branch, 'from' => $from, 'to' => $to]);

        if ($detail) {
            $rows = $this->report->exportDetails($type, $merchant, $user, $branch, $from, $to);

            return $this->exportTabularReport($request, $format, $this->report->detailColumns($type), $rows, 'Transaction Details - '.Report::TYPES[$type], 'transactions-'.strtolower($type), $filters, maxPdfRows: 1000);
        }

        $summary = $this->report->summary($merchant, $type, $user, $branch, $from, $to);
        $rows = $summary['data'];
        $rows[] = ['type' => 'Total'] + $summary['total'];

        return $this->exportTabularReport($request, $format, Report::SUMMARY_COLUMNS, $rows, 'Transaction Report', 'transactions-summary', $filters, maxPdfRows: null);
    }

    public function receipt(Request $request): JsonResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_print')) {
            return $response;
        }

        $v = $request->validate([
            'transaction_id' => ['required', 'string'],
            'trans_type' => ['required', Rule::in(array_filter(array_keys(Report::TYPES)))],
            'merchant_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $data = $this->receipts->build($v['transaction_id'], $v['trans_type'], $v['merchant_id']);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Not found.', 'errors' => $e->errors()], 404);
        }

        return Pdf::loadView('reports.webpos-receipt', ['r' => $data])->setPaper([0, 0, 360, 576])->download('receipt-'.$v['transaction_id'].'.pdf');
    }

    /** @return array{0:int,1:string,2:?int,3:?int,4:string,5:string} */
    private function filters(Request $request, bool $detail = false): array
    {
        $v = $request->validate([
            'merchant_id' => ['required', 'integer', 'min:1'],
            'trans_type' => [$detail ? 'required' : 'nullable', Rule::in($detail ? array_filter(array_keys(Report::TYPES)) : array_keys(Report::TYPES))],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return [$v['merchant_id'], $v['trans_type'] ?? '', $v['user_id'] ?? null, $v['branch_id'] ?? null, $v['from'], $v['to']];
    }
}
