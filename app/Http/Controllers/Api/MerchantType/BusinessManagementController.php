<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Merchant;
use App\Services\MerchantType\BusinessManagementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessManagementController extends Controller
{
    private const MODULE_PATH = '/merchants/business-management';

    public function __construct(private readonly BusinessManagementService $business) {}

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

        return response()->json($this->business->list());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->business->getInitialInfo($id);
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

        $merchantForLog = Merchant::find($id);
        $before = $merchantForLog ? $merchantForLog->getAttributes() : [];

        try {
            $data = $this->business->updateInitialInfo($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        if ($merchantForLog) {
            ActivityLog::recordUpdated($request->user(), 'Business Management', $merchantForLog->fresh(), $before, ['dba_name', 'trade_name', 'suntag_shortcode', 'risk_rating', 'business_size', 'require_second_auth'], $request);
        }

        return response()->json(['message' => 'Business updated successfully.'] + $data);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->business->approve($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'approve', "Approved business #{$merchant->id} ({$merchant->dba_name})", $merchant, $request);

        return response()->json(['message' => 'Business approved successfully.', 'merchant' => $merchant]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $merchant = $this->business->reject($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'reject', "Rejected business #{$merchant->id} ({$merchant->dba_name})", $merchant, $request);

        return response()->json(['message' => 'Business rejected successfully.', 'merchant' => $merchant]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $merchant = $this->business->activate($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'activate', "Activated business #{$merchant->id} ({$merchant->dba_name})", $merchant, $request);

        return response()->json(['message' => 'Business activated successfully.', 'merchant' => $merchant]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $status = $request->query('status') ?: null;
        $format = (string) $request->query('format', 'csv');
        $columns = BusinessManagementService::COLUMNS;
        $rows = $this->business->exportRows($status);

        if ($format === 'pdf') {
            return Pdf::loadView('reports.table', [
                'title' => 'Business Management',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => array_filter(['status' => $status]),
                'columns' => $columns,
                'rows' => $rows,
                'totalCount' => count($rows),
                'truncated' => false,
            ])->setPaper('a4', 'landscape')->download('business-management-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'business-management-'.now()->format('Ymd-His').'.csv';

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
