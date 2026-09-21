<?php

namespace App\Http\Controllers\Api\Transactions;

use App\Http\Controllers\Controller;
use App\Services\Transactions\ResendVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResendVoucherController extends Controller
{
    protected const MODULE_PATH = '/transactions/resend-voucher';

    public function __construct(private readonly ResendVoucherService $vouchers) {}

    public function resend(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $validated = $request->validate([
            'voucher_number' => ['required', 'string'],
        ]);

        try {
            $result = $this->vouchers->resend($validated['voucher_number'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }

        return response()->json($result);
    }
}
