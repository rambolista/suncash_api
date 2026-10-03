<?php

namespace App\Http\Controllers\Api\Reports;

use App\Services\Reports\UserClientReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reports > Users / Client Management — seven tabs; see TabbedReportController for the per-tab access rules. */
class UserClientReportController extends TabbedReportController
{
    protected const MODULE_PATH = Report::MODULE_PATH;

    protected const EXPORT_PREFIX = 'user-client-';

    public function __construct(private readonly Report $report) {}

    protected function tabs(): array
    {
        return Report::TABS;
    }

    protected function columns(string $tab): array
    {
        return Report::COLUMNS[$tab];
    }

    protected function exportColumns(string $tab): array
    {
        return $this->report->exportColumns($tab);
    }

    protected function rows(string $tab, array $filters): array
    {
        return $this->report->list($tab, $filters['from'], $filters['to'], $filters['amount'], $filters['applied']);
    }

    public function options(Request $request): JsonResponse
    {
        foreach (array_keys(Report::TABS) as $tab) {
            if ($this->userHasTabPermission($request->user(), static::MODULE_PATH, $tab, 'can_view')) {
                return response()->json(['amounts' => $this->report->amountOptions()]);
            }
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }

    /** Users Profile > Transaction. */
    public function customerTransactions(Request $request, int $id): JsonResponse
    {
        if ($response = $this->denied($request, 'user_profile', 'can_view')) {
            return $response;
        }

        $rows = $this->report->customerTransactions($id);
        abort_if($rows === null, 404);

        return response()->json(['data' => $rows]);
    }
}
