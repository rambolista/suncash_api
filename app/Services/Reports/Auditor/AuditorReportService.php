<?php

namespace App\Services\Reports\Auditor;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * "Reports > Auditor's Report" (legacy `auditors_reports`): pick a report type, a date window (or a merchant, or nothing,
 * depending on the type — see AuditorReportCatalog), see how many rows it has and export it to Excel.
 *
 * Legacy never showed the rows: Search built the workbook in the browser and offered an Export button. Here Search returns
 * the first rows as a preview plus the full row count, and Export builds the same workbook on the server — one sheet per
 * month ("2026 August", from the Transaction Date) or a single sheet for the types that have no date.
 *
 * Bug fixed in passing: legacy pasted the posted dates straight into every SQL string; they are bound parameters here.
 */
class AuditorReportService
{
    public const MODULE_PATH = '/reports/auditors-report';

    public const PREVIEW_ROWS = 1000;

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private function db()
    {
        return DB::connection('mysuncash');
    }

    // ------------------------------------------------------------------ catalog

    /** @return array<string, array> */
    private function catalog(): array
    {
        return AuditorReportCatalog::all();
    }

    public function find(string $key): ?array
    {
        return $this->catalog()[$key] ?? null;
    }

    public function types(): array
    {
        return collect($this->catalog())->map(fn ($r, $key) => [
            'value' => $key, 'label' => $r['label'], 'dates' => $r['dates'], 'note' => $r['note'] ?? null,
        ])->values()->all();
    }

    /** Columns shown for a type; amounts / counts are flagged so the table sorts them as numbers. */
    public function columns(string $key): array
    {
        return collect($this->catalog()[$key]['columns'])->map(fn ($label, $alias) => [
            'key' => $alias, 'label' => $label,
            'type' => preg_match('/amount|fee|vat|total|count|balance|price/i', $alias) ? 'number' : 'text',
        ])->values()->all();
    }

    /** The Merchant dropdown (Merchant Transactions): Merchant Name, else DBA, Legal or User name. */
    public function merchants(): array
    {
        return $this->db()->table('clients')->orderBy('client_id')->orderBy('id')
            ->get(['id', 'client_id', 'user_name', 'merchant_name', 'dba_name', 'legal_name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => collect([$c->merchant_name, $c->dba_name, $c->legal_name, $c->user_name])->first(fn ($v) => filled($v)) ?? (string) $c->id])
            ->all();
    }

    // ------------------------------------------------------------------ query

    /**
     * Expands the catalog's date macros. The optimized form is a plain range on the column; `$legacy` gives the DATE() /
     * CAST form legacy ran (same rows — it only stops the index from being used), kept so the two can be compared.
     */
    public function compile(string $sql, bool $legacy = false): string
    {
        $macros = [
            'day' => ['{c} >= {from} AND {c} < {to_next}', 'DATE({c}) >= {from} AND DATE({c}) <= {to}'],
            'day_ge' => ['{c} >= {from}', 'DATE({c}) >= {from}'],
            'day_le' => ['{c} < {to_next}', 'DATE({c}) <= {to}'],
            'day_lt' => ['{c} < {to}', 'DATE({c}) < {to}'],
            'day_lt_next' => ['{c} < {to_next}', 'DATE({c}) < {to_next}'],
        ];

        return preg_replace_callback('/\{(day\w*) ([\w.]+)\}/', fn ($m) => str_replace('{c}', $m[2], $macros[$m[1]][$legacy ? 1 : 0]), $sql);
    }

    /**
     * @param  array{from?:?string,to?:?string,merchant?:int|string|null}  $f
     * @return array{0:string,1:list<mixed>} positional SQL and its bindings
     */
    private function bind(string $sql, array $f): array
    {
        $values = [
            'from' => $f['from'] ?? null,
            'to' => $f['to'] ?? null,
            // The day after End Date, worked out here: DATE_ADD() over a bound text parameter returns text in a collation
            // MySQL won't compare with the latin1 `ezkard_transactions.timestamp`.
            'to_next' => filled($f['to'] ?? null) ? Carbon::parse($f['to'])->addDay()->toDateString() : null,
            'merchant' => isset($f['merchant']) ? (string) $f['merchant'] : null,
            'fix' => AuditorReportCatalog::GAMING_DEPOSIT_DATE_FIX,
            // Legacy looked this id up for the NIB report and compared against it even when there was none.
            'voucher_client' => str_contains($sql, '{voucher_client}') ? (string) ($this->db()->table('clients')->where('client_id', 'VOUCHER')->value('id') ?? '') : null,
        ];

        $bindings = [];
        $sql = preg_replace_callback('/\{(from|to_next|to|merchant|fix|voucher_client)\}/', function ($m) use ($values, &$bindings) {
            $bindings[] = $values[$m[1]];

            return '?';
        }, $sql);

        return [$sql, $bindings];
    }

    /**
     * The report's rows, keyed by the columns the export shows. `$legacy` runs the DATE()-style windows legacy used.
     *
     * @param  array{from?:?string,to?:?string,merchant?:int|string|null}  $f
     * @return list<array<string,mixed>>
     */
    public function rows(string $key, array $f, bool $legacy = false): array
    {
        $report = $this->catalog()[$key];
        $sql = $report['sql'];
        // Before the gaming-deposit cut-over the Gaming Funds fee lines live in another table.
        if (isset($report['sql_before_fix']) && strtotime((string) ($f['from'] ?? '')) <= strtotime(AuditorReportCatalog::GAMING_DEPOSIT_DATE_FIX)) {
            $sql = $report['sql_before_fix'];
        }

        [$sql, $bindings] = $this->bind($this->compile($sql, $legacy), $f);

        try {
            $result = $this->db()->select($sql, $bindings);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), '1021893_sunpass')) {
                throw new RuntimeException("This report reads the Sunpass ticketing database, which isn't available on this server.");
            }
            throw $e;
        }

        $keep = array_flip(array_keys($report['columns']));

        return array_map(fn ($row) => array_intersect_key((array) $row, $keep), $result);
    }

    // ------------------------------------------------------------------ export

    /** Builds the workbook and returns its path (the caller deletes it after sending). */
    public function workbook(string $key, array $rows): string
    {
        $report = $this->catalog()[$key];
        $columns = array_diff_key($report['columns'], array_flip($report['export_without'] ?? []));
        $dateAlias = array_search('Transaction Date', $report['columns'], true);

        // Legacy split by the month of the Transaction Date; types without one export as a single sheet.
        $sheets = [];
        foreach ($rows as $row) {
            $sheets[$this->sheetName($key, $dateAlias ? ($row[$dateAlias] ?? null) : null, $dateAlias !== false)][] = $row;
        }

        Cell::setValueBinder(new StringValueBinder);
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $name => $sheetRows) {
            $sheet = $book->createSheet();
            $sheet->setTitle((string) $name);
            $sheet->fromArray(array_values($columns), null, 'A1');
            $sheet->fromArray(array_map(fn ($r) => array_map(fn ($alias) => $r[$alias] ?? null, array_keys($columns)), $sheetRows), null, 'A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'auditor');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function sheetName(string $key, mixed $date, bool $dated): string
    {
        if (! $dated) {
            return mb_substr($key, 0, 31);
        }
        if (preg_match('/^(\d{4})-(\d{2})/', (string) $date, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $m[1].' '.self::MONTHS[(int) $m[2] - 1];
        }

        return 'No date';
    }

    /** The download name: legacy's `<type>_reports.xlsx`, with the as-of date on the balance snapshots. */
    public function filename(string $key, array $f): string
    {
        $asOf = in_array($key, ['customer_balance', 'customer_merchant_balance'], true) && ! empty($f['to']) ? '-'.$f['to'] : '';

        return $key.$asOf.'_reports.xlsx';
    }
}
