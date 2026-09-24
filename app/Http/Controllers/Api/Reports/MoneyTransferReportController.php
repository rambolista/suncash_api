<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Reports\MoneyTransferReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MoneyTransferReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/reports/money-transfer';

    public function __construct(private readonly MoneyTransferReportService $report) {}

    public function cashiers(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->report->cashiers()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $completed = $request->query('type') === 'completed';
        $from = $request->query('from');
        $to = $request->query('to');
        $cashierId = $request->query('cashier') ? (int) $request->query('cashier') : null;
        $search = $request->query('search');

        $result = $this->report->paginatedList($completed, $from, $to, $cashierId, $search, (int) $request->query('page', 1));
        $result['summary'] = $this->report->totalSummary($completed, $from, $to, $cashierId, $search);

        return response()->json($result);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $completed = $request->query('type') === 'completed';
        $from = $request->query('from');
        $to = $request->query('to');
        $cashierId = $request->query('cashier') ? (int) $request->query('cashier') : null;
        $search = $request->query('search');
        $format = (string) $request->query('format', 'csv');

        $rows = $this->report->exportRows($completed, $from, $to, $cashierId, $search);
        $columns = $completed ? MoneyTransferReportService::COLUMNS_COMPLETED : MoneyTransferReportService::COLUMNS_PENDING;
        $title = $completed ? 'Completed Money Transfers Report' : 'Pending Money Transfers Report';

        $summaryData = $this->report->totalSummary($completed, $from, $to, $cashierId, $search);
        $summary = ['Total Transaction Count' => $summaryData['transaction_count']];
        foreach ($summaryData['amounts'] as $amount) {
            $summary["Total Amount ({$amount['currency']})"] = number_format($amount['total'], 2);
        }

        return $this->exportTabularReport($request, $format, $columns, $rows, $title, $completed ? 'money-transfer-completed' : 'money-transfer-pending', array_filter(['from' => $from, 'to' => $to, 'search' => $search]), maxPdfRows: 1000, summary: $summary);
    }
}
