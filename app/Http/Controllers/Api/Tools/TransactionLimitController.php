<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\TransactionLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionLimitController extends Controller
{
    protected const MODULE_PATH = '/tools/transaction-limits';

    public function __construct(private readonly TransactionLimitService $limits) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->limits->list()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);

        try {
            $limit = $this->limits->update($id, (float) $data['amount'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Not found.', 'errors' => $exception->errors()], 404);
        }

        return response()->json(['message' => 'Transaction limit has been updated.', 'data' => $limit]);
    }
}
