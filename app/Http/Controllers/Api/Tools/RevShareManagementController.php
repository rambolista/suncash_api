<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\RevShareManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RevShareManagementController extends Controller
{
    protected const MODULE_PATH = '/tools/revshare-management';

    public function __construct(private readonly RevShareManagementService $revShare) {}

    public function filters(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'merchants' => $this->revShare->merchants(),
            'transaction_types' => $this->revShare->transactionTypes(),
            'share_types' => RevShareManagementService::SHARE_TYPES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate([
            'merchant_id' => ['required', 'string'],
            'transaction_type_id' => ['required', 'string'],
            'share_type' => ['required', 'string'],
        ]);

        try {
            $rows = $this->revShare->list($data['merchant_id'], $data['transaction_type_id'], $data['share_type']);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }

        return response()->json([
            'data' => $rows,
            'fee_info' => $this->revShare->feeInfo($data['merchant_id'], $data['transaction_type_id']),
        ]);
    }
}
