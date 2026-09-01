<?php

namespace App\Http\Controllers\Api\Kyc;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Services\Kyc\KycUpgradeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycUpgradeController extends Controller
{
    private const MODULE_PATH = '/customers/kyc-upgrade';

    private const STATUS_BY_TAB = [
        'pending' => Customer::ACCESS_PENDING,
        'approved' => Customer::ACCESS_FULL,
        'rejected' => Customer::ACCESS_REJECTED,
    ];

    public function __construct(private readonly KycUpgradeService $kyc) {}

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

    private function actorIp(Request $request): string
    {
        return (string) $request->ip();
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->kyc->list() + ['reject_reasons' => KycUpgradeService::REJECT_REASONS]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $result = $this->kyc->getDetail($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $tab = (string) $request->query('status', '');
        $status = self::STATUS_BY_TAB[$tab] ?? null;
        $format = (string) $request->query('format', 'csv');

        $rows = $this->kyc->exportRows($status);
        $columns = $status === Customer::ACCESS_REJECTED
            ? KycUpgradeService::COLUMNS
            : array_slice(KycUpgradeService::COLUMNS, 0, 4);

        ActivityLog::recordAction($request->user(), 'KYC Upgrade', 'exported', 'Exported KYC Upgrade list ('.($tab ?: 'all').' tab, '.strtoupper($format).', '.count($rows).' rows)', null, $request);

        if ($format === 'pdf') {
            return Pdf::loadView('reports.table', [
                'title' => 'KYC Upgrade — '.ucfirst($tab ?: 'all'),
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => $tab ? ['status' => $tab] : [],
                'columns' => $columns,
                'rows' => $rows,
                'totalCount' => count($rows),
                'truncated' => false,
            ])->setPaper('a4', 'landscape')->download('kyc-upgrade-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'kyc-upgrade-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->kyc->approve($id, (string) $request->user()->id, $this->actorIp($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Customer has been approved.'] + $result);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->kyc->reject($id, (string) $request->input('reason'), (string) $request->user()->id, $this->actorIp($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Customer has been rejected.'] + $result);
    }
}
