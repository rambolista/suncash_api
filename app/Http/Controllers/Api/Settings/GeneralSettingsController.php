<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\GeneralSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GeneralSettingsController extends Controller
{
    protected const MODULE_PATH = '/apps/access-management/general-settings';

    public function __construct(private readonly GeneralSettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->settings->status());
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['mandatory_2fa' => ['required', 'boolean']]);

        try {
            return response()->json($this->settings->setMandatoryTwoFactor($data['mandatory_2fa'], $request->user(), $request));
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }
    }
}
