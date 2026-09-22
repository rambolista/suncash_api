<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\AlivSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlivSettingsController extends Controller
{
    protected const MODULE_PATH = '/tools/aliv-settings';

    public function __construct(private readonly AlivSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->settings->get());
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'replenish_balance' => ['required', 'numeric', 'min:0'],
            'notification_address' => ['required', 'string', 'max:255'],
        ]);

        $this->settings->update((string) $data['replenish_balance'], $data['notification_address'], $request->user());

        return response()->json(['message' => 'Successfully Updated.'] + $this->settings->get());
    }
}
