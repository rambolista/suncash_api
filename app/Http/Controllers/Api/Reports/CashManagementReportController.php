<?php

namespace App\Http\Controllers\Api\Reports;

use App\Services\Reports\CashManagementReportService as Report;

/** Reports > Cash Management — Sales by Product / Sales by Location tabs; see TabbedReportController for the per-tab access rules. */
class CashManagementReportController extends TabbedReportController
{
    protected const MODULE_PATH = Report::MODULE_PATH;

    protected const EXPORT_PREFIX = 'cash-management-';

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
        return $this->report->list($tab, $filters['from'], $filters['to']);
    }
}
