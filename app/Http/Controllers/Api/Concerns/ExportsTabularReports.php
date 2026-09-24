<?php

namespace App\Http\Controllers\Api\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ExportsTabularReports
{
    /**
     * Every report export branches on ?format=pdf|csv the same way: render
     * the PDF via the shared reports.table view (optionally capping rows so
     * a huge dataset doesn't blow up DomPDF), or stream a CSV built from the
     * same columns/rows. $maxPdfRows=null skips the cap entirely, matching
     * the controllers whose datasets are already small/bounded and never
     * truncated before this was extracted.
     */
    protected function exportTabularReport(
        Request $request,
        string $format,
        array $columns,
        array $rows,
        string $title,
        string $filenamePrefix,
        array $filters = [],
        ?int $maxPdfRows = 1000,
        string $orientation = 'landscape',
        array $summary = [],
    ): StreamedResponse|Response {
        if ($format === 'pdf') {
            $truncated = $maxPdfRows !== null && count($rows) > $maxPdfRows;
            $pdfRows = $truncated ? array_slice($rows, 0, $maxPdfRows) : $rows;

            ini_set('memory_limit', '-1');

            return Pdf::loadView('reports.table', [
                'title' => $title,
                'generatedBy' => $request->user()?->name ?? $request->user()?->email ?? 'system',
                'generatedAt' => now()->toDayDateTimeString(),
                'filters' => $filters,
                'columns' => $columns,
                'rows' => $pdfRows,
                'totalCount' => count($rows),
                'truncated' => $truncated,
                'summary' => $summary,
            ])->setPaper('a4', $orientation)->download($filenamePrefix.'-'.now()->format('Ymd-His').'.pdf');
        }

        $filename = $filenamePrefix.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $columns, $summary) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($column) => $row[$column['key']] ?? '', $columns));
            }
            if ($summary) {
                fputcsv($handle, []);
                foreach ($summary as $label => $value) {
                    fputcsv($handle, [$label, $value]);
                }
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
