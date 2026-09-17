<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Promotions\GrandDrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GrandDrawController extends Controller
{
    protected const MODULE_PATH = '/promotions/grand-draw';

    public function __construct(private readonly GrandDrawService $grandDraw) {}

    public function status(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->grandDraw->status());
    }

    public function winners(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['winners' => $this->grandDraw->winners()]);
    }

    public function run(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $winners = $this->grandDraw->run();
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Unable to run the draw.',
            ], 422);
        }

        ActivityLog::recordAction($request->user(), 'Promotions Grand Draw', 'executed', 'Ran the grand draw ('.count($winners).' winner(s)).', null, $request);

        return response()->json(['winners' => $winners]);
    }
}
