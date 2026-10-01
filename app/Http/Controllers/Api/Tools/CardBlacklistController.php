<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\CardBlacklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardBlacklistController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/card-blacklist';

    public function __construct(private readonly CardBlacklistService $cardBlacklist) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->cardBlacklist->list()]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $rows = $this->cardBlacklist->list();

        return $this->exportTabularReport($request, $format, CardBlacklistService::COLUMNS, $rows, 'Card Blacklist', 'card-blacklist', maxPdfRows: null);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            return response()->json(['data' => $this->cardBlacklist->find($id)]);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $this->cardBlacklist->create($request->all(), $request->user(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Blacklist has been saved.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $this->cardBlacklist->update($id, $request->all(), $request->user(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Successfully updated.']);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $this->cardBlacklist->setStatus($id, true, $request->user(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Successfully updated.']);
    }

    public function inactivate(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $this->cardBlacklist->setStatus($id, false, $request->user(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Successfully updated.']);
    }
}
