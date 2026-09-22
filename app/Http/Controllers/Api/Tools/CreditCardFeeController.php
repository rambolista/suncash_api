<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\CreditCardFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CreditCardFeeController extends Controller
{
    protected const MODULE_PATH = '/tools/credit-card-fees';

    public function __construct(private readonly CreditCardFeeService $fees) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->fees->list()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);

        try {
            $fee = $this->fees->update($id, (float) $data['amount'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Not found.', 'errors' => $exception->errors()], 404);
        }

        return response()->json(['message' => 'Fee has been updated.', 'data' => $fee]);
    }
}
