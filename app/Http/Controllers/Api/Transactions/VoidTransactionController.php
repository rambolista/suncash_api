<?php

namespace App\Http\Controllers\Api\Transactions;

use App\Http\Controllers\Controller;
use App\Services\Transactions\VoidTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoidTransactionController extends Controller
{
    protected const MODULE_PATH = '/transactions/void-transaction';

    public function __construct(private readonly VoidTransactionService $voidTransaction) {}

    /**
     * Every voidXxx() throws under the 'id' key regardless of the actual
     * reason (already voided, missing linked customer, insufficient balance,
     * etc.) — surface that real message instead of a canned "Not found.",
     * which previously hid why a visibly-existing transaction couldn't void.
     */
    private function invalid(ValidationException $exception): JsonResponse
    {
        $message = collect($exception->errors())->collapse()->first() ?? 'The given data was invalid.';

        return response()->json([
            'message' => $message,
            'errors' => $exception->errors(),
        ], str_contains(strtolower($message), 'not found') ? 404 : 422);
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
            $data = $this->voidTransaction->search($validated['transaction_id'], $validated['transaction_type']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function void(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_reverse')) {
            return $response;
        }

        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'transaction_type' => ['required', 'string'],
        ]);

        try {
            $result = $this->voidTransaction->void($validated['transaction_id'], $validated['transaction_type'], (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
