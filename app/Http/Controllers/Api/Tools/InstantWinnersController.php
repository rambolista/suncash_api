<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\InstantWinnersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstantWinnersController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/instant-winners';

    public function __construct(private readonly InstantWinnersService $instantWinners) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'data' => $this->instantWinners->list(),
            'prizes' => $this->instantWinners->prizes(),
            'next_ticket_threshold' => $this->instantWinners->nextTicketThreshold(),
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $rows = $this->instantWinners->list();

        return $this->exportTabularReport($request, $format, InstantWinnersService::COLUMNS, $rows, 'Instant Winners', 'instant-winners', maxPdfRows: null);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $this->instantWinners->create($request->all(), $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->errors()[array_key_first($exception->errors())][0],
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json(['message' => 'Instant Winner successfully added.'], 201);
    }
}
