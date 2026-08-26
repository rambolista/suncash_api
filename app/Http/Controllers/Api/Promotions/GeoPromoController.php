<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Services\Promotions\GeoPromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GeoPromoController extends Controller
{
    private const MODULE_PATH = '/promotions/signup';

    public function __construct(private readonly GeoPromoService $geoPromos) {}

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

        return response()->json($this->geoPromos->list());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->geoPromos->find($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $promo = $this->geoPromos->create($request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Sign up promotion zone added successfully.', 'promo' => $promo], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $promo = $this->geoPromos->update($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Sign up promotion zone updated successfully.', 'promo' => $promo]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->geoPromos->delete($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Sign up promotion zone removed successfully.']);
    }
}
