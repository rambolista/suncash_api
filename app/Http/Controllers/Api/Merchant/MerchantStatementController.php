<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Merchant\MerchantMoneyService;
use App\Services\Merchant\MerchantStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantStatementController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/merchants/statement';

    public const COLUMNS = [
        ['key' => 'timestamp', 'label' => 'Timestamp'],
        ['key' => 'transtype', 'label' => 'Transaction Type'],
        ['key' => 'description', 'label' => 'Description'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'available_balance', 'label' => 'Available Balance'],
        ['key' => 'onhold_balance', 'label' => 'Onhold Balance'],
        ['key' => 'running_balance', 'label' => 'Total Balance'],
        ['key' => 'reference_no', 'label' => 'Reference'],
    ];

    public function __construct(
        private readonly MerchantStatementService $statements,
        private readonly MerchantMoneyService $merchantMoney,
    ) {}

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

        return response()->json($this->statements->listMerchants($request->query('search')));
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());

        try {
            $data = $this->statements->statement($id, $dateFrom, $dateTo);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function export(Request $request, int $id): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());
        $format = (string) $request->query('format', 'csv');

        try {
            $data = $this->statements->statement($id, $dateFrom, $dateTo);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        $columns = self::COLUMNS;
        $rows = $data['rows'];

        ActivityLog::recordAction($request->user(), 'Merchant Statement', 'exported', 'Exported statement for '.$data['merchant']['dba_name']." ({$dateFrom} to {$dateTo}, ".strtoupper($format).', '.count($rows).' rows)', null, $request);

        return $this->exportTabularReport($request, $format, $columns, $rows, 'Merchant Statement — '.$data['merchant']['dba_name'], 'merchant-statement', ['date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    public function adjustment(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $type = (string) $request->input('type');
        $amount = (float) $request->input('amount', 0);
        $description = (string) $request->input('description', '');

        try {
            $result = $this->merchantMoney->adjustPrefund($id, $type, $amount, $description, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Statement', 'adjusted', ucfirst($type)." of {$amount} applied to merchant #{$id} prefund balance: {$description}", null, $request);

        return response()->json(['message' => 'Adjustment applied successfully.'] + $result);
    }
}
