<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Services\Promotions\PromoTicketReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoTicketReportController extends Controller
{
    private const MODULE_PATH = '/promotions/ticket-reports';

    public function __construct(private readonly PromoTicketReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
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
}
