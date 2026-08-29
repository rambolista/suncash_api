<?php

namespace App\Http\Controllers\Api\Terminal;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\Terminal;
use App\Services\Terminal\TerminalManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TerminalManagementController extends Controller
{
    private const MODULE_PATH = '/terminals/management';

    public function __construct(private readonly TerminalManagementService $terminals) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
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

    private function actorId(Request $request): string
    {
        return (string) $request->user()->id;
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'terminals' => $this->terminals->list(),
            'device_types' => Terminal::DEVICE_TYPES,
            'connection_types' => Terminal::CONNECTION_TYPES,
        ]);
    }

    public function merchants(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->terminals->listMerchantsForPicker());
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $result = $this->terminals->create($request->all(), $this->actorId($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Terminal registered successfully.', 'terminal' => $result], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->terminals->update($id, $request->all(), $this->actorId($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Terminal updated successfully.', 'terminal' => $result]);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $statusId = (int) $request->input('status');
        $action = $statusId === 2 ? 'can_delete' : 'can_edit';

        if ($response = $this->forbidden($request, $action)) {
            return $response;
        }

        try {
            $result = $this->terminals->changeStatus($id, $statusId, $this->actorId($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Terminal status updated successfully.'] + $result);
    }
}
