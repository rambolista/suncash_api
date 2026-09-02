<?php

namespace App\Http\Controllers\Api\Transactions;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TransactionReceiptController extends Controller
{
    private const MODULE_PATH = '/transactions/resend-receipt';

    public function __construct(private readonly TransactionReceiptService $receipts) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function renderPdf(array $receipt, string $generatedBy)
    {
        return Pdf::loadView('reports.transaction-receipt', [
            'data' => $receipt,
            'generatedBy' => $generatedBy,
            'generatedAt' => now()->toDayDateTimeString(),
        ])->setPaper([0, 0, 320, 500]);
    }

    public function search(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'transaction_type' => ['required', 'string'],
        ]);

        try {
            $data = $this->receipts->search($validated['transaction_id'], $validated['transaction_type']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function generate(Request $request): JsonResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'transaction_type' => ['required', 'string'],
        ]);

        try {
            $receipt = $this->receipts->getReceipt($validated['transaction_id'], $validated['transaction_type']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $generatedBy = $request->user()?->name ?? $request->user()?->email ?? 'system';

        return $this->renderPdf($receipt, $generatedBy)->download('receipt-'.$receipt['transaction_id'].'.pdf');
    }

    public function send(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'transaction_type' => ['required', 'string'],
            'mobile' => ['required', 'string'],
        ]);

        try {
            $result = $this->receipts->sendReceipt($validated['transaction_id'], $validated['transaction_type'], $validated['mobile'], (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    /** Public, signed link the SMS points to — no Sanctum token; the `signed` route middleware verifies it instead. */
    public function view(string $transactionType, string $transactionId): Response|JsonResponse
    {
        try {
            $receipt = $this->receipts->getReceipt($transactionId, $transactionType);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return $this->renderPdf($receipt, 'Suncash')->stream('receipt-'.$receipt['transaction_id'].'.pdf');
    }
}
