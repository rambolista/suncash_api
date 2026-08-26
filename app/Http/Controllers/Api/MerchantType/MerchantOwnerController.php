<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Services\MerchantType\MerchantOwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Owners/directors CRUD, nested under a Business's Initial Info screen — same permission scope as Business Management. */
class MerchantOwnerController extends Controller
{
    private const MODULE_PATH = '/merchants/business-management';

    public function __construct(private readonly MerchantOwnerService $owners) {}

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

    public function store(Request $request, int $merchantId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $owner = $this->owners->create($merchantId, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Owner added successfully.', 'owner' => $owner], 201);
    }

    public function update(Request $request, int $merchantId, int $ownerId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $owner = $this->owners->update($merchantId, $ownerId, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Owner updated successfully.', 'owner' => $owner]);
    }
}
