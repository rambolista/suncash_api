<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\SmsLogsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SmsLogsController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/sms-logs';

    public function __construct(private readonly SmsLogsService $smsLogs) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $result = $this->smsLogs->paginatedList(
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('search'),
            (int) $request->query('page', 1),
        );

        return response()->json($result);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $search = $request->query('search');
        $format = (string) $request->query('format', 'csv');

        $rows = $this->smsLogs->exportRows($dateFrom, $dateTo, $search);

        return $this->exportTabularReport($request, $format, SmsLogsService::COLUMNS, $rows, 'SMS Logs', 'sms-logs', array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search]), maxPdfRows: 1000);
    }
}
