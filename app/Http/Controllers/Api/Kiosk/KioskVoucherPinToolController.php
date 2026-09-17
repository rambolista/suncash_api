<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskVoucherPinToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskVoucherPinToolController extends Controller
{
    protected const MODULE_PATH = '/kiosk/voucher-pin-tool';

    public function __construct(private readonly KioskVoucherPinToolService $service)
    {
    }

    public function lookup(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:suncash,unibucks,credit'],
        ]);

        try {
            $result = $this->service->lookup($data['code'], $data['type'], (string) $request->user()->id, (string) $request->ip());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'No record found.',
            ], 404);
        }

        return response()->json($result);
    }
}
