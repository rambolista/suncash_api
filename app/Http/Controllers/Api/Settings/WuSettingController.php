<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\WuSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WuSettingController extends Controller
{
    protected const MODULE_PATH = '/settings/wu';

    public function __construct(private readonly WuSettingService $settings) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->settings->list());
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->settings->toggle(
                $id,
                $request->boolean('is_enabled'),
                (string) $request->user()->id,
                (string) ($request->user()->name ?? $request->user()->email),
                (string) $request->ip(),
                $request,
            );
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Not found.', 'errors' => $exception->errors()], 404);
        }

        return response()->json(['message' => $result['is_enabled'] ? 'Setting enabled.' : 'Setting disabled.'] + $result);
    }
}
