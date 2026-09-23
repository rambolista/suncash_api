<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Merchant\MerchantSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantSettlementController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/merchants/settlements';

    public function __construct(private readonly MerchantSettlementService $settlements) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function actorName(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'transaction_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'merchant' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'withdrawal_type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'amount' => ['sometimes', 'nullable', 'string', 'max:20'],
            'created_date' => ['sometimes', 'nullable', 'string', 'max:20'],
            'updated_date' => ['sometimes', 'nullable', 'string', 'max:20'],
            'updated_by' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $columnFilters = collect($validated)
            ->only(['transaction_id', 'merchant', 'type', 'withdrawal_type', 'amount', 'created_date', 'updated_date', 'updated_by'])
            ->filter()
            ->all();

        try {
            $page = $this->settlements->paginatedList($validated['status'] ?? 'pending', (int) ($validated['page'] ?? 1), $validated['search'] ?? null, $columnFilters);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($page + ['counts' => $this->settlements->counts()]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $status = $request->query('status') ?: null;
        $format = (string) $request->query('format', 'csv');
        $columns = MerchantSettlementService::COLUMNS;
        $rows = $this->settlements->exportRows($status);

        ActivityLog::recordAction($request->user(), 'Merchant Settlements', 'exported', 'Exported Merchant Settlements list ('.($status ?: 'all').' status, '.strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, $columns, $rows, 'Merchant Settlements', 'merchant-settlements', array_filter(['status' => $status]), maxPdfRows: null);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->settlements->getDetail($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function history(Request $request, int $merchantId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->settlements->history($merchantId)]);
    }

    public function transactions(Request $request, int $merchantId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->settlements->transactionHistory($merchantId)]);
    }

    public function banks(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->settlements->listBanks());
    }

    public function linkedBankAccounts(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->settlements->listLinkedBankAccounts());
    }

    public function linkBankAccount(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->settlements->linkBankAccount($request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Bank account has been linked.', 'data' => $data]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->settlements->approve($id, $request->all(), $this->actorName($request), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->settlements->reject($id, $request->all(), $this->actorName($request), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
