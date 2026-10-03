<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A Reports menu whose legacy "List of Reports" dropdown became tabs (as on Kiosk > Reports). Access is per tab
 * (`menu_tabs`): `can_view` to read a tab, `can_export` for its PDF/Excel. Subclasses only say which tabs/columns/rows.
 *
 * Every tab accepts `from`/`to` (dates), `amount` and `applied` (the user pressed Apply Filters); subclasses may add
 * more filters via `extraRules()` and a totals strip via `summary()`.
 */
abstract class TabbedReportController extends Controller
{
    use ExportsTabularReports;

    /** @return array<string,string> tab key => title */
    abstract protected function tabs(): array;

    abstract protected function columns(string $tab): array;

    /** Export columns (no image/action columns). */
    abstract protected function exportColumns(string $tab): array;

    /** @param array{from:?string,to:?string,amount:?string,applied:bool}&array<string,mixed> $filters */
    abstract protected function rows(string $tab, array $filters): array;

    /** Extra validation rules for tab-specific filters (their validated values arrive in `$filters`). */
    protected function extraRules(): array
    {
        return [];
    }

    /** Totals shown under the table and appended to exports: label => value. */
    protected function summary(string $tab, array $rows): array
    {
        return [];
    }

    protected function denied(Request $request, string $tab, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), static::MODULE_PATH, $tab, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    protected function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'numeric'],
        ] + $this->extraRules());

        return [
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'amount' => isset($v['amount']) ? (string) $v['amount'] : null,
            'applied' => $request->boolean('applied'),
        ] + array_diff_key($v, ['from' => 1, 'to' => 1, 'amount' => 1]);
    }

    protected function tab(string $tab): string
    {
        abort_unless(array_key_exists($tab, $this->tabs()), 404);

        return $tab;
    }

    public function index(Request $request, string $tab): JsonResponse
    {
        $tab = $this->tab($tab);
        if ($response = $this->denied($request, $tab, 'can_view')) {
            return $response;
        }

        $rows = $this->rows($tab, $this->filters($request));

        return response()->json(['columns' => $this->columns($tab), 'data' => $rows, 'summary' => $this->summary($tab, $rows)]);
    }

    public function export(Request $request, string $tab): JsonResponse|StreamedResponse|Response
    {
        $tab = $this->tab($tab);
        if ($response = $this->denied($request, $tab, 'can_export')) {
            return $response;
        }

        $f = $this->filters($request);
        $rows = $this->rows($tab, $f);

        return $this->exportTabularReport(
            $request,
            (string) $request->query('format', 'csv'),
            $this->exportColumns($tab),
            $rows,
            $this->tabs()[$tab],
            static::EXPORT_PREFIX.str_replace('_', '-', $tab),
            array_filter(array_diff_key($f, ['applied' => 1]), fn ($v) => filled($v)),
            maxPdfRows: 1000,
            summary: $this->summary($tab, $rows),
        );
    }
}
