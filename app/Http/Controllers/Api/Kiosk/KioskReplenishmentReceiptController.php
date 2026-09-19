<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskReplenishmentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskReplenishmentReceiptController extends Controller
{
    protected const MODULE_PATH = '/kiosk/reprint-replenishment-receipt';

    public function __construct(private readonly KioskReplenishmentReceiptService $service)
    {
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => collect($exception->errors())->flatten()->first() ?? 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], 404);
    }

    public function filters(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'meter_types' => collect(KioskReplenishmentReceiptService::METER_TYPES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'terminals' => $this->service->listTerminals(),
            'branches' => $this->service->listBranches(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate([
            'meter_type' => ['required', 'string'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'terminal_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        try {
            $rows = $this->service->search(
                $data['meter_type'],
                $data['date_from'],
                $data['date_to'],
                $data['terminal_id'] ?? null,
                $data['branch_id'] ?? null,
            );
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['rows' => $rows]);
    }

    public function detail(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_print')) {
            return $response;
        }

        $data = $request->validate([
            'meter_type' => ['required', 'string'],
            'settlement_no' => ['required', 'string'],
            'ref_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $this->service->detail($data['meter_type'], $data['settlement_no'], $data['ref_id'] ?? null);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
