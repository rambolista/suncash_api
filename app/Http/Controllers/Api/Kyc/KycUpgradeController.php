<?php

namespace App\Http\Controllers\Api\Kyc;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Services\Kyc\KycUpgradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycUpgradeController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/customers/kyc-upgrade';

    private const STATUS_BY_TAB = [
        'pending' => Customer::ACCESS_PENDING,
        'approved' => Customer::ACCESS_FULL,
        'rejected' => Customer::ACCESS_REJECTED,
    ];

    public function __construct(private readonly KycUpgradeService $kyc) {}

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

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'created_at' => ['sometimes', 'nullable', 'string', 'max:20'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reason_reject' => ['sometimes', 'nullable', 'string', 'max:100'],
            'updated_at' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $columnFilters = collect($validated)
            ->only(['created_at', 'name', 'mobile', 'email', 'reason_reject', 'updated_at'])
            ->filter()
            ->all();

        $page = $this->kyc->paginatedList($validated['status'] ?? 'pending', (int) ($validated['page'] ?? 1), $validated['search'] ?? null, $columnFilters);

        return response()->json($page + ['counts' => $this->kyc->counts(), 'reject_reasons' => KycUpgradeService::REJECT_REASONS]);
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

        return $this->exportTabularReport($request, $format, $columns, $rows, 'KYC Upgrade — '.ucfirst($tab ?: 'all'), 'kyc-upgrade', $tab ? ['status' => $tab] : [], maxPdfRows: null);
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
