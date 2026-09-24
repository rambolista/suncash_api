<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\PaymentManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentManagementController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/payment-management';

    public function __construct(private readonly PaymentManagementService $payments) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : (collect($exception->errors())->collapse()->first() ?? 'The given data was invalid.'),
            'errors' => $exception->errors(),
        ], $status);
    }

    private function filters(Request $request): array
    {
        return $request->only(['date_from', 'date_to', 'status', 'payment_type_id', 'search', 'settlement_period']);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'data' => $this->payments->list($this->filters($request)),
            'summary' => $this->payments->summary(),
            'types' => $this->payments->listTypes(),
            'methods' => $this->payments->listMethods(),
            'clients' => $this->payments->listClients(),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateTo = $request->query('date_to') ?: now()->toDateString();
        $dateFrom = $request->query('date_from') ?: now()->subDays(14)->toDateString();

        return response()->json($this->payments->dashboard($dateFrom, $dateTo));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->payments->details($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            return response()->json($this->payments->addManualPayment($request->all(), $request->user()));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            return response()->json($this->payments->editManualPayment($id, $request->all(), $request->user()));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:500']]);

        $permission = PaymentManagementService::ACTIONS[$data['action']]['permission'] ?? 'can_execute';
        if ($response = $this->forbidden($request, $permission)) {
            return $response;
        }

        try {
            return response()->json($this->payments->updateStatus($id, $data['action'], $data['reason'] ?? null, $request->user()));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function cloneToDraft(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            return response()->json($this->payments->cloneToDraft($id, $request->user()));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function history(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->payments->history($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function exportHistory(Request $request, int $id): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        try {
            $rows = $this->payments->historyExportRows($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $format = (string) $request->query('format', 'csv');

        return $this->exportTabularReport($request, $format, PaymentManagementService::HISTORY_COLUMNS, $rows, 'Payment History', 'payment-history');
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $filters = $this->filters($request);
        $rows = $this->payments->list($filters);
        $summary = collect($this->payments->summary())->mapWithKeys(fn ($s) => ["{$s['label']} — Count" => $s['count'], "{$s['label']} — Amount" => number_format($s['amount'], 2)])->all();

        return $this->exportTabularReport($request, $format, PaymentManagementService::COLUMNS, $rows, 'Payment Management', 'payment-management', $filters, summary: $summary);
    }
}
