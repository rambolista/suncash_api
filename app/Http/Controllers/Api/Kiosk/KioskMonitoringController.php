<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskMonitoringController extends Controller
{
    private const MODULE_PATH = '/kiosk/monitoring-dashboard';

    public function __construct(private readonly KioskMonitoringService $monitoring) {}

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
        return $request->user()?->name ?? $request->user()?->email ?? 'system';
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->monitoring->list()]);
    }

    public function clear(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->monitoring->clear($id, (string) $request->user()->id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->monitoring->acknowledge($id, (string) $request->user()->id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
