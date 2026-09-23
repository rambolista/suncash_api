<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\CardLogsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardLogsController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/card-logs';

    public function __construct(private readonly CardLogsService $cardLogs) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        return response()->json(['data' => $this->cardLogs->list($dateFrom, $dateTo)]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $format = (string) $request->query('format', 'csv');
        $rows = $this->cardLogs->list($dateFrom, $dateTo);

        return $this->exportTabularReport($request, $format, CardLogsService::COLUMNS, $rows, 'Card Logs', 'card-logs', array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo]), maxPdfRows: null);
    }
}
