<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Services\MerchantType\BusinessManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusinessManagementController extends Controller
{
    private const MODULE_PATH = '/merchants/business-management';

    public function __construct(private readonly BusinessManagementService $business) {}

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

    private function actorName(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->business->list());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->business->getInitialInfo($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->business->updateInitialInfo($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Business updated successfully.'] + $data);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->business->approve($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Business approved successfully.', 'merchant' => $merchant]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->business->reject($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Business rejected successfully.', 'merchant' => $merchant]);
    }
}
