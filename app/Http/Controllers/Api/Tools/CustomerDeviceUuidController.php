<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\CustomerDeviceUuidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDeviceUuidController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/customer-device-uuid';

    public function __construct(private readonly CustomerDeviceUuidService $devices) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->devices->list($request->query('date_from'), $request->query('date_to'))]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $format = (string) $request->query('format', 'csv');
        $rows = $this->devices->list($dateFrom, $dateTo);

        return $this->exportTabularReport($request, $format, CustomerDeviceUuidService::COLUMNS, $rows, 'Customer Device UUID', 'customer-device-uuid', array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo]), maxPdfRows: null);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->devices->delete($id, $request->user(), $request);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Not found.', 'errors' => $exception->errors()], 404);
        }

        return response()->json(['message' => 'Customer Uuid Successfully Deleted']);
    }
}
