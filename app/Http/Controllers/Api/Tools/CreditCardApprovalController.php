<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\CreditCardApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditCardApprovalController extends Controller
{
    protected const MODULE_PATH = '/tools/credit-card-approval';

    public function __construct(private readonly CreditCardApprovalService $approval) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $tab = $request->validate(['tab' => ['required', 'string', 'in:'.implode(',', array_keys(CreditCardApprovalService::STATUS_MAP))]])['tab'];

        return response()->json(['data' => $this->approval->list($tab), 'counts' => $this->approval->counts()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $this->approval->approve($id, $request->user());

        return response()->json(['message' => 'Card has been approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->approval->reject($id, $data['reason'], $request->user());

        return response()->json(['message' => 'Card has been rejected.']);
    }
}
