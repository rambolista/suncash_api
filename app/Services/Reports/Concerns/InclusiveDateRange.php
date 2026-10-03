<?php

namespace App\Services\Reports\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;

/** Date-range helpers shared by the tabbed Reports services. */
trait InclusiveDateRange
{
    /**
     * Legacy applied a date filter only when BOTH ends were given. The To day is included in full (an end-of-day bound
     * rather than "next midnight", so extreme dates can't overflow).
     *
     * @return array{0:string,1:string}|null
     */
    private function range(?string $from, ?string $to): ?array
    {
        if (blank($from) || blank($to)) {
            return null;
        }

        return [Carbon::parse($from)->toDateString().' 00:00:00', Carbon::parse($to)->toDateString().' 23:59:59'];
    }

    /**
     * `ezkard_transactions.timestamp` is a VARCHAR and holds a few non-date values ("1", epoch seconds); legacy's
     * `CAST(timestamp AS DATE)` turned those into NULL so they never matched a date range. A string range alone would
     * let them through for extreme ranges, so keep them out explicitly.
     */
    private function tsGuard(Builder $q, string $alias = 'e'): void
    {
        $q->whereRaw("{$alias}.`timestamp` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'");
    }
}
