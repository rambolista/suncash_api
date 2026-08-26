<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Services\MerchantType\CharityManagementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CharityManagementController extends Controller
{
    private const MODULE_PATH = '/merchants/charity-management';

    public function __construct(private readonly CharityManagementService $charity) {}

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

    private function actorName(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->charity->list());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->charity->getInitialInfo($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->charity->updateInitialInfo($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Charity updated successfully.'] + $data);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->charity->approve($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Charity approved successfully.', 'merchant' => $merchant]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->charity->reject($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Charity rejected successfully.', 'merchant' => $merchant]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $status = $request->query('status') ?: null;
        $format = (string) $request->query('format', 'csv');
        $columns = CharityManagementService::COLUMNS;
        $rows = $this->charity->exportRows($status);

        if ($format === 'pdf') {
            return Pdf::loadView('reports.table', [
                'title' => 'Charity Management',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => array_filter(['status' => $status]),
                'columns' => $columns,
                'rows' => $rows,
                'totalCount' => count($rows),
                'truncated' => false,
            ])->setPaper('a4', 'landscape')->download('charity-management-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'charity-management-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
