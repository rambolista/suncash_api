<?php

namespace App\Services\FloatManagement\Concerns;

/** Legacy's transaction id format — `time()` + a short random suffix, retried until unique. */
trait GeneratesTransactionId
{
    private function generateTransactionId(string $modelClass): string
    {
        do {
            $candidate = (string) time().random_int(11, 99);
        } while ($modelClass::where('transaction_id', $candidate)->exists());

        return $candidate;
    }
}
