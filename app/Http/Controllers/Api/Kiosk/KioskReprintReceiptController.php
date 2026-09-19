<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskReprintReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskReprintReceiptController extends Controller
{
    protected const MODULE_PATH = '/kiosk/reprint-receipt';

    public function __construct(private readonly KioskReprintReceiptService $service)
    {
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => collect($exception->errors())->flatten()->first() ?? 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], 404);
    }

    public function types(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'types' => collect(KioskReprintReceiptService::TYPES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'max:100'],
            'transaction_type' => ['required', 'string'],
        ]);

        try {
            $result = $this->service->search($data['transaction_id'], $data['transaction_type']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function receipt(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_print')) {
            return $response;
        }

        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'max:100'],
            'transaction_type' => ['required', 'string'],
        ]);

        try {
            $result = $this->service->receipt($data['transaction_id'], $data['transaction_type'], (string) $request->user()->id, (string) $request->ip());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
