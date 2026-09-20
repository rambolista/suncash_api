<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\ForexRate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Forex Rate" (legacy `tools/forex` + `tools_model::get_forex()`/
 * `save_forex()`). An append-only rate history — saving never edits an
 * existing row, it inserts a new one; the latest row per currency pair is
 * what real cross-currency transactions elsewhere in this system actually
 * use, so this is live financial config, not cosmetic reference data.
 */
class ForexRateService
{
    /** Matches legacy's hardcoded `<select>` options in `tools/forex.php` — Bahamas is the only source currency this system quotes from. */
    public const SOURCE_CURRENCIES = ['BSD'];

    public const DESTINATION_CURRENCIES = ['PHP', 'HTG', 'JMD'];

    public function list(): array
    {
        return ForexRate::orderByDesc('timestamp')
            ->get(['id', 'from_currency', 'to_currency', 'rate', 'timestamp'])
            ->toArray();
    }

    /** @throws ValidationException */
    public function create(string $from, string $to, float $rate, User $actor): ForexRate
    {
        if (! in_array($from, self::SOURCE_CURRENCIES, true)) {
            throw ValidationException::withMessages(['from_currency' => ['Invalid source currency.']]);
        }
        if (! in_array($to, self::DESTINATION_CURRENCIES, true)) {
            throw ValidationException::withMessages(['to_currency' => ['Invalid destination currency.']]);
        }
        if ($rate <= 0) {
            throw ValidationException::withMessages(['rate' => ['Please enter a valid rate.']]);
        }

        $forexRate = ForexRate::create([
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $rate,
            'timestamp' => now(),
        ]);

        ActivityLog::recordCreated($actor, 'Tools - Forex Rate', $forexRate, ['from_currency', 'to_currency', 'rate']);

        return $forexRate;
    }
}
