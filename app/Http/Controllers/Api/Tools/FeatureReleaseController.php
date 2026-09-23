<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\FeatureReleaseControl;
use App\Services\Tools\FeatureReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeatureReleaseController extends Controller
{
    protected const MODULE_PATH = '/tools/feature-release';

    public function __construct(private readonly FeatureReleaseService $featureRelease) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function islands(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->featureRelease->islands()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->featureRelease->list()]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'feature_type' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::in([FeatureReleaseControl::SCOPE_ALL, FeatureReleaseControl::SCOPE_SPECIFIC])],
            'release_date' => ['required', 'date'],
            'islands' => ['array'],
            'islands.*' => ['integer'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $release = $this->featureRelease->create($this->validated($request), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Feature release has been saved.', 'data' => $release], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $release = $this->featureRelease->update($id, $this->validated($request), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Feature release has been saved.', 'data' => $release]);
    }
}
