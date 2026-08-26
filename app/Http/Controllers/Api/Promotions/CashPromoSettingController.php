<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Services\Promotions\CashPromoSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashPromoSettingController extends Controller
{
    private const MODULE_PATH = '/promotions/settings';

    public function __construct(private readonly CashPromoSettingService $cashPromos) {}

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

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->cashPromos->list());
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $setting = $this->cashPromos->create($request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Cash promo added successfully.', 'setting' => $setting], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $setting = $this->cashPromos->update($id, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Cash promo updated successfully.', 'setting' => $setting]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->cashPromos->delete($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Cash promo removed successfully.']);
    }
}
