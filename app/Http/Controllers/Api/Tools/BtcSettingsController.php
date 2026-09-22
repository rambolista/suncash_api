<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\BtcSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BtcSettingsController extends Controller
{
    protected const MODULE_PATH = '/tools/btc-settings';

    public function __construct(private readonly BtcSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $channel = $request->validate(['channel' => ['required', 'string', 'in:'.implode(',', array_keys(BtcSettingsService::CHANNELS))]])['channel'];

        return response()->json($this->settings->get($channel));
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'channel' => ['required', 'string', 'in:'.implode(',', array_keys(BtcSettingsService::CHANNELS))],
            'replenish_amount' => ['required', 'numeric', 'min:0'],
            'notification_address' => ['required', 'string', 'max:255'],
        ]);

        $this->settings->update($data['channel'], (string) $data['replenish_amount'], $data['notification_address'], $request->user());

        return response()->json(['message' => 'Successfully Updated.'] + $this->settings->get($data['channel']));
    }
}
