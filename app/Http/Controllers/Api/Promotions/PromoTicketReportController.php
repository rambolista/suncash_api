<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Services\Promotions\PromoTicketReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromoTicketReportController extends Controller
{
    private const MODULE_PATH = '/promotions/ticket-reports';

    public function __construct(private readonly PromoTicketReportService $reports) {}

    private function forbidden(Request $request): ?JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request)) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());
        $status = $request->query('status') ?: null;
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->reports->list($dateFrom, $dateTo, config('promotions.active_code'), $status, $page);

        return response()->json([
            'data' => $result->items(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
            'total' => $result->total(),
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request)) {
            return $response;
        }

        $dateFrom = (string) $request->query('date_from', now()->toDateString());
        $dateTo = (string) $request->query('date_to', now()->toDateString());
        $status = $request->query('status') ?: null;
        $format = (string) $request->query('format', 'csv');
        $promoType = config('promotions.active_code');

        $filters = array_filter([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
        ]);

        if ($format === 'pdf') {
            $data = $this->reports->listForPdf($dateFrom, $dateTo, $promoType, $status);

            return Pdf::loadView('reports.table', [
                'title' => 'Summer Cool Down Reloaded — Ticket Report',
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => $filters,
                'columns' => PromoTicketReportService::COLUMNS,
                'rows' => $data['rows'],
                'totalCount' => $data['total_count'],
                'truncated' => $data['truncated'],
            ])->setPaper('a4', 'landscape')->download('ticket-report-'.now()->format('Ymd-His').'.pdf');
        }

        $rows = $this->reports->listForCsv($dateFrom, $dateTo, $promoType, $status);
        $columns = PromoTicketReportService::COLUMNS;
        $filename = 'ticket-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                $row = (array) $row;
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
