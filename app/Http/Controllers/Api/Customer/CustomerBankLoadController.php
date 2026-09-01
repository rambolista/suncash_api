<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerBankLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerBankLoadController extends Controller
{
    private const MODULE_PATH = '/customers/bank-loads';

    public function __construct(private readonly CustomerBankLoadService $bankLoads) {}

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

    private function actorId(Request $request): string
    {
        return (string) $request->user()->id;
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->bankLoads->list());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->bankLoads->getDetail($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->bankLoads->history($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->bankLoads->transactionHistory($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->bankLoads->approve($id, $request->all(), $this->actorId($request));
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
            $result = $this->bankLoads->reject($id, $request->all(), $this->actorId($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
