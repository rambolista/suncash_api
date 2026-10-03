<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Reports\VoucherReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = Report::MODULE_PATH;

    public function __construct(private readonly Report $report) {}

    public function options(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->report->options());
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filters($request);
        $rows = $this->report->list($f);

        // Legacy logged every Search press (with its parameters) as well as every export.
        if ($request->boolean('applied')) {
            ActivityLog::recordAction($request->user(), 'Voucher Report', 'searched', 'Searched Voucher Report '.json_encode($this->loggable($f)).' ('.count($rows).' rows)', null, $request);
        }

        return response()->json(['columns' => Report::COLUMNS, 'data' => $rows]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filters($request);
        $rows = $this->report->list($f);
        $format = (string) $request->query('format', 'csv');

        ActivityLog::recordAction($request->user(), 'Voucher Report', 'exported', 'Exported Voucher Report ('.strtoupper($format).', '.count($rows).' rows) '.json_encode($this->loggable($f)), null, $request);

        return $this->exportTabularReport(
            $request,
            $format,
            Report::COLUMNS,
            $rows,
            'Voucher Report',
            'voucher-report',
            array_filter(['from' => $f['from'], 'to' => $f['to'], 'status' => $f['status'], 'product' => $f['sku'], 'purchased' => $f['purchased'], 'redeemed' => $f['redeemed']], fn ($v) => filled($v)),
            maxPdfRows: 1000,
        );
    }

    /** Legacy rules: both dates required, and End must not precede Start. */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(Report::STATUSES)],
            'sku' => ['nullable', 'integer', 'min:1'],
            'purchased' => ['nullable', 'string', 'max:30', 'regex:/^(\d+|'.implode('|', Report::PURCHASE_SOURCES).')$/'],
            'redeemed' => ['nullable', 'string', 'max:30', 'regex:/^(\d+|'.implode('|', Report::REDEEM_SOURCES).')$/'],
        ], [
            'from.required' => 'Please fill up date from and date to.',
            'to.required' => 'Please fill up date from and date to.',
            'to.after_or_equal' => 'End date should be greater than Start date.',
        ]);

        return [
            'from' => $v['from'],
            'to' => $v['to'],
            'status' => $v['status'] ?? 'ALL',
            'sku' => $v['sku'] ?? 1,
            'purchased' => $v['purchased'] ?? '',
            'redeemed' => $v['redeemed'] ?? '',
        ];
    }

    private function loggable(array $f): array
    {
        return array_filter($f, fn ($v) => filled($v) && $v !== 'ALL');
    }
}
