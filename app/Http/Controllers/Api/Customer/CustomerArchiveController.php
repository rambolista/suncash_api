<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerArchiveService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerArchiveController extends Controller
{
    private const MODULE_PATH = '/customers/archive';

    public function __construct(private readonly CustomerArchiveService $archive) {}

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

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->archive->list()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $detail = $this->archive->getDetail($id);
            $detail['transactions'] = $this->archive->recentTransactions($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($detail);
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

    public function export(Request $request, int $id): JsonResponse|StreamedResponse|Response
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

        $columns = CustomerArchiveService::COLUMNS;

        if ($format === 'pdf') {
            return Pdf::loadView('reports.table', [
                'title' => 'Customer Transaction History',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => array_filter(['from' => $from, 'to' => $to]),
                'columns' => $columns,
                'rows' => $rows,
                'totalCount' => count($rows),
                'truncated' => false,
            ])->setPaper('a4', 'landscape')->download('customer-transactions-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = 'customer-transactions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $result = $this->archive->archive($id, (string) $request->user()->id, (string) $request->ip());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
