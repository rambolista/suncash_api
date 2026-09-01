<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Merchant\MerchantBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantBranchController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantBranchService $branches)
    {
    }

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, 'branch', $action)
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

        return response()->json($this->branches->listBranches($id));
    }

    public function islands(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->branches->listIslands());
    }

    public function store(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->branches->createBranch($id, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Branches', 'created', "Created branch {$result['branch_code']} for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Branch created successfully.', 'branch' => $result], 201);
    }

    public function update(Request $request, int $id, int $branchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->branches->updateBranch($id, $branchId, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Branches', 'updated', "Updated branch {$result['branch_code']} for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Branch updated successfully.', 'branch' => $result]);
    }

    public function changeStatus(Request $request, int $id, int $branchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->branches->changeBranchStatus($id, $branchId, (string) $request->input('status'));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Branches', 'status_changed', "Changed status of branch #{$branchId} for merchant #{$id} to {$request->input('status')}.", null, $request);

        return response()->json(['message' => 'Branch status updated successfully.'] + $result);
    }
}
