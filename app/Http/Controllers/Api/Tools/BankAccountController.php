<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\BankAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BankAccountController extends Controller
{
    protected const MODULE_PATH = '/tools/bank-accounts';

    public function __construct(private readonly BankAccountService $bankAccounts) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function banks(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->bankAccounts->banks()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->bankAccounts->list()]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'business_billpay_banks_id' => ['required', 'integer'],
            'account_name' => ['required', 'string', 'max:50'],
            'account_no' => ['required', 'string', 'max:50'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $account = $this->bankAccounts->create($this->validated($request), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Bank account has been saved.', 'data' => $account], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $account = $this->bankAccounts->update($id, $this->validated($request), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Bank account has been saved.', 'data' => $account]);
    }
}
