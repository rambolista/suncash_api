<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\BillersSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillersSetupController extends Controller
{
    protected const MODULE_PATH = '/tools/billers-setup';

    public function __construct(private readonly BillersSetupService $billers) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->billers->list()]);
    }

    public function save(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'selected_ids' => ['present', 'array'],
            'selected_ids.*' => ['integer'],
        ]);

        $result = $this->billers->save($data['selected_ids'], $request->user());

        return response()->json(['message' => 'Billers updated successfully.'] + $result);
    }
}
