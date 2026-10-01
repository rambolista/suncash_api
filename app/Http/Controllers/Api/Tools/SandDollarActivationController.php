<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\SandDollarActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SandDollarActivationController extends Controller
{
    protected const MODULE_PATH = '/tools/sanddollar-activation';

    public function __construct(private readonly SandDollarActivationService $sandDollar) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], 422);
    }

    public function merchants(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->sandDollar->merchants()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->sandDollar->list()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $account = $this->sandDollar->activate($request->all(), $request->user(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Successfully activated sanddollar account.', 'data' => $account], 201);
    }
}
