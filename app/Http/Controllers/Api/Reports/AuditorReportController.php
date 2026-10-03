<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Reports\Auditor\AuditorReportCatalog;
use App\Services\Reports\Auditor\AuditorReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditorReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = Report::MODULE_PATH;

    public function __construct(private readonly Report $report) {}

    public function options(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['types' => $this->report->types(), 'merchants' => $this->report->merchants()]);
    }

    /** Search: how many rows the report has, and the first of them. */
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filters($request);

        try {
            $rows = $this->report->rows($f['type'], $f);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'columns' => $this->report->columns($f['type']),
            'data' => array_slice($rows, 0, Report::PREVIEW_ROWS),
            'total' => count($rows),
            'preview_rows' => Report::PREVIEW_ROWS,
        ]);
    }

    /** Excel (legacy's export, one sheet per month) or PDF (first 1,000 rows — a 100,000-row report is not a printable page count). */
    public function export(Request $request): JsonResponse|BinaryFileResponse|Response|StreamedResponse
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filters($request);
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';

        try {
            $rows = $this->report->rows($f['type'], $f);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        if (! $rows) {
            return response()->json(['message' => 'No records found.'], 404);
        }

        $filters = array_filter(['from' => $f['from'], 'to' => $f['to'], 'merchant' => $f['merchant']], fn ($v) => filled($v));
        ActivityLog::recordAction($request->user(), "Auditor's Report", 'exported', "Exported Auditor's Report {$f['type']} (".strtoupper($format).', '.count($rows).' rows) '.json_encode($filters), null, $request);

        if ($format === 'pdf') {
            return $this->exportTabularReport(
                $request,
                'pdf',
                $this->report->columns($f['type']),
                $rows,
                "Auditor's Report — ".$this->report->find($f['type'])['label'],
                $f['type'].'_reports',
                $filters,
                maxPdfRows: Report::PREVIEW_ROWS,
            );
        }

        ini_set('memory_limit', '-1');
        set_time_limit(0);
        $path = $this->report->workbook($f['type'], $rows);

        return response()->download($path, $this->report->filename($f['type'], $f), headers: ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** The filters each type uses: a window, an End Date only, a merchant plus window, or nothing. */
    private function filters(Request $request): array
    {
        $type = $request->validate(['type' => ['required', Rule::in(array_keys(AuditorReportCatalog::all()))]], ['type.required' => 'Select a report type.'])['type'];
        $dates = $this->report->find($type)['dates'];
        $date = ['date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'];

        $v = $request->validate([
            'from' => in_array($dates, ['range', 'merchant'], true) ? ['required', ...$date] : ['nullable'],
            'to' => $dates === 'none' ? ['nullable'] : ['required', ...$date, ...($dates === 'end' ? [] : ['after_or_equal:from'])],
            'merchant' => $dates === 'merchant' ? ['required', 'integer', 'min:1'] : ['nullable'],
        ], [
            'from.required' => 'Please fill up date from and date to.',
            'to.required' => 'Please fill up date from and date to.',
            'to.after_or_equal' => 'End date should be greater than Start date.',
            'merchant.required' => 'Select a merchant.',
        ]);

        return [
            'type' => $type,
            'from' => in_array($dates, ['range', 'merchant'], true) ? $v['from'] : null,
            'to' => $v['to'] ?? null,
            'merchant' => $dates === 'merchant' ? (int) $v['merchant'] : null,
        ];
    }
}
