<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerArchiveService;
use App\Services\Tools\CustomerDebitCreditService;
use App\Services\Tools\CustomerManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDebitCreditController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/customer-debit-credit';

    public function __construct(
        private readonly CustomerManagementService $customers,
        private readonly CustomerArchiveService $archive,
        private readonly CustomerDebitCreditService $debitCredit,
    ) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function search(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->customers->search($request->only(['first_name', 'last_name', 'mobile_number', 'card_number', 'email', 'bank_topup']));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $detail = $this->customers->detail($id);
            $detail['transactions'] = $this->archive->recentTransactions($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($detail);
    }

    public function transactionTypes(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate(['orientation' => ['required', 'string', 'in:Credit,Debit']]);

        return response()->json(['data' => $this->debitCredit->transactionTypes($data['orientation'])]);
    }

    public function process(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $data = $request->validate([
            'id_trans_type' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->debitCredit->process($id, $data['id_trans_type'], (float) $data['amount'], $data['notes'] ?? null, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        try {
            $data = $this->archive->transactionsInRange($id, $validated['from'], $validated['to']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function exportTransactions(Request $request, int $id): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $from = $request->query('from');
        $to = $request->query('to');

        try {
            $rows = ($from && $to)
                ? $this->archive->transactionsInRange($id, $from, $to)
                : $this->archive->recentTransactions($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return $this->exportTabularReport($request, $format, CustomerArchiveService::COLUMNS, $rows, 'Customer Transaction History', 'customer-transactions', array_filter(['from' => $from, 'to' => $to]), maxPdfRows: null);
    }
}
