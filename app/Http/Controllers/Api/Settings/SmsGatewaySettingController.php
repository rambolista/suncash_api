<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\SmsGatewaySettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SmsGatewaySettingController extends Controller
{
    protected const MODULE_PATH = '/settings/notifications';

    public function __construct(private readonly SmsGatewaySettingService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->service->get());
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['primary' => ['required', 'string']]);

        try {
            $result = $this->service->updatePrimary(
                $data['primary'],
                (string) $request->user()->id,
                (string) $request->user()->name,
                (string) $request->ip(),
                $request
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Invalid gateway.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json($result);
    }

    public function toggleFailover(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $result = $this->service->updateFailover(
            (bool) $data['enabled'],
            (string) $request->user()->id,
            (string) $request->user()->name,
            (string) $request->ip(),
            $request
        );

        return response()->json($result);
    }
}
