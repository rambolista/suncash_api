<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\ForexRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ForexRateController extends Controller
{
    protected const MODULE_PATH = '/tools/forex-rate';

    public function __construct(private readonly ForexRateService $forex) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'data' => $this->forex->list(),
            'source_currencies' => ForexRateService::SOURCE_CURRENCIES,
            'destination_currencies' => ForexRateService::DESTINATION_CURRENCIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        $data = $request->validate([
            'from_currency' => ['required', 'string'],
            'to_currency' => ['required', 'string'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $forexRate = $this->forex->create($data['from_currency'], $data['to_currency'], (float) $data['rate'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }

        return response()->json(['message' => 'Successfully saved forex.', 'data' => $forexRate], 201);
    }
}
