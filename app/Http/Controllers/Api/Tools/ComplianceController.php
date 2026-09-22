<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\ComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    protected const MODULE_PATH = '/tools/compliance';

    public function __construct(private readonly ComplianceService $compliance) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->compliance->list()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'other_info' => ['required', 'string', 'max:2000'],
        ]);

        $this->compliance->update($id, $data['name'], $data['other_info'], $request->user());

        return response()->json(['message' => 'Successfully updated blocked list entry.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $this->compliance->delete($id, $request->user());

        return response()->json(['message' => 'Successfully deleted.']);
    }

    public function import(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $result = $this->compliance->import($data['file'], $request->user());

        return response()->json(['message' => 'Successfully uploaded.'] + $result);
    }
}
