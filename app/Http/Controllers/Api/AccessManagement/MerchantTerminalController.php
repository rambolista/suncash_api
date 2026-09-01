<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Terminal;
use App\Services\Merchant\MerchantTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantTerminalController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantTerminalService $terminals)
    {
    }

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, 'terminals', $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function index(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'terminals' => $this->terminals->listTerminals($id),
            'device_types' => Terminal::DEVICE_TYPES,
            'connection_types' => Terminal::CONNECTION_TYPES,
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->terminals->createTerminal($id, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Terminals', 'created', "Registered terminal for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Terminal registered successfully.', 'terminal' => $result], 201);
    }

    public function update(Request $request, int $id, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->terminals->updateTerminal($id, $terminalId, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Terminals', 'updated', "Updated terminal #{$terminalId} for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Terminal updated successfully.', 'terminal' => $result]);
    }

    public function changeStatus(Request $request, int $id, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->terminals->changeTerminalStatus($id, $terminalId, (int) $request->input('status'));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Terminals', 'status_changed', "Changed status of terminal #{$terminalId} for merchant #{$id} to {$request->input('status')}.", null, $request);

        return response()->json(['message' => 'Terminal status updated successfully.'] + $result);
    }
}
