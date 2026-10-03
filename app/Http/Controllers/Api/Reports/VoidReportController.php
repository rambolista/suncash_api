<?php

namespace App\Http\Controllers\Api\Reports;

use App\Services\Reports\VoidReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reports > Void — Voided Sales / Voids by Product / Number of Voids tabs; see TabbedReportController for the per-tab access rules. */
class VoidReportController extends TabbedReportController
{
    protected const MODULE_PATH = Report::MODULE_PATH;

    protected const EXPORT_PREFIX = 'void-';

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
        return Report::COLUMNS[$tab];
    }

    protected function extraRules(): array
    {
        return ['location' => ['nullable', 'string', 'max:255'], 'cashier' => ['nullable', 'string', 'max:255']];
    }

    protected function rows(string $tab, array $filters): array
    {
        return $this->report->list($tab, $filters['from'], $filters['to'], $filters['location'] ?? null, $filters['cashier'] ?? null);
    }

    protected function summary(string $tab, array $rows): array
    {
        return $this->report->summary($tab, $rows);
    }

    /** Location + Cashier dropdowns for the two tabs that filter by them. */
    public function options(Request $request): JsonResponse
    {
        foreach (['voided_sales', 'voids_by_product'] as $tab) {
            if ($this->userHasTabPermission($request->user(), static::MODULE_PATH, $tab, 'can_view')) {
                return response()->json($this->report->options());
            }
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
