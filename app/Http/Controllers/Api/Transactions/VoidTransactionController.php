<?php

namespace App\Http\Controllers\Api\Transactions;

use App\Http\Controllers\Controller;
use App\Services\Transactions\VoidTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoidTransactionController extends Controller
{
    private const MODULE_PATH = '/transactions/void-transaction';

    public function __construct(private readonly VoidTransactionService $voidTransaction) {}

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
