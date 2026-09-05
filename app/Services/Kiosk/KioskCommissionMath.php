<?php

namespace App\Services\Kiosk;

use Illuminate\Support\Facades\DB;

/**
 * Legacy `Kiosk_commission_model::computeCommission()` / `get_kiosk_percentage_profile()`,
 * shared verbatim by every Kiosk report that splits a transaction's commission
 * into agent/SunCash/owner legs (`KioskCommissionReportService`, per-transaction;
 * `KioskAgentCommissionReportService`, aggregated by terminal+product).
 *
 * Legacy quirk preserved deliberately: `computeCommission()` takes an optional
 * `$timedate` multiplier for the "Fixed" commission leg, but no caller across
 * either report ever passes it — so it defaults to `""`, and
 * `$fixed_commission = commission_fixed_value * ""` evaluates to `0` in PHP.
 * That makes the "Fixed" leg of every commission type always zero:
 * `commission_type` 1 (Fixed) always yields 0/0/0, type 3 (Greater Amount)
 * effectively reduces to "percentage or zero", and type 4 (Fixed + Percentage)
 * reduces to plain percentage. Only type 2 (and the percentage half of 3/4)
 * ever produces non-zero commission. `commission_type` values with no
 * matching `switch` case (0, 5, ... — no legacy `default:` arm) also fall
 * through to zero. Not a bug to "fix" — replicated exactly, since it's what
 * today's live reports actually show.
 */
class KioskCommissionMath
{
    /** @return array{commission_type: int, commission_fixed_value: float}|null */
    public function terminalConfig(array &$cache, int $terminalId): ?array
    {
        if (! array_key_exists($terminalId, $cache)) {
            $row = DB::connection('mysuncash')->table('kiosk_terminal')
                ->where('id', $terminalId)
                ->first(['commission_type', 'commission_fixed_value']);

            $cache[$terminalId] = $row ? ['commission_type' => (int) $row->commission_type, 'commission_fixed_value' => (float) $row->commission_fixed_value] : null;
        }

        return $cache[$terminalId];
    }

    /** @return array{agent_percentage: float, suncash_percentage: float, owner_percentage: float, provider_percentage: float}|null */
    public function percentageProfile(array &$cache, string $transactionType, int $terminalId): ?array
    {
        $key = "{$terminalId}:{$transactionType}";
        if (! array_key_exists($key, $cache)) {
            $row = DB::connection('mysuncash')->table('kiosk_terminal as kt')
                ->join('kiosk_profiles as kp', 'kp.profile_name', '=', 'kt.profile_id')
                ->join('kiosk_products as kpt', 'kpt.id', '=', 'kp.kiosk_product_id')
                ->where('kt.id', $terminalId)
                ->where('kpt.product_code', $transactionType)
                ->first(['kp.agent_percentage', 'kp.suncash_percentage', 'kp.owner_percentage', 'kp.provider_percentage']);

            $cache[$key] = $row ? [
                'agent_percentage' => (float) $row->agent_percentage,
                'suncash_percentage' => (float) $row->suncash_percentage,
                'owner_percentage' => (float) $row->owner_percentage,
                'provider_percentage' => (float) $row->provider_percentage,
            ] : null;
        }

        return $cache[$key];
    }

    /** Legacy `Kiosk_commission_model::computeCommission()`, called with no `$timedate` (so the "Fixed" leg is always 0 — see class docblock). */
    public function computeCommission(array &$terminalCache, array &$profileCache, string $transactionType, int $terminalId, float $amount, float $fees): array
    {
        $agentCommission = $suncashCommission = $ownerCommission = 0.0;

        $terminal = $this->terminalConfig($terminalCache, $terminalId);
        if (! $terminal) {
            return ['agent_commission' => 0.0, 'suncash_commission' => 0.0, 'owner_commission' => 0.0];
        }

        $fixedCommission = 0.0; // commission_fixed_value * "" (no $timedate passed) => 0, replicated as-is

        switch ($terminal['commission_type']) {
            case 1: // Fixed
                $agentCommission = $fixedCommission * 0.10;
                $suncashCommission = $fixedCommission * 0.10;
                $ownerCommission = $fixedCommission * 0.10;
                break;

            case 2: // Percentage
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentCommission = $commission * ($profile['agent_percentage'] / 100);
                    $suncashCommission = $commission * ($profile['suncash_percentage'] / 100);
                    $ownerCommission = $commission * ($profile['owner_percentage'] / 100);
                }
                break;

            case 3: // Greater Amount — max(fixed leg, percentage leg) per commission recipient
                $agentCommission = $fixedCommission * 0.10;
                $suncashCommission = $fixedCommission * 0.10;
                $ownerCommission = $fixedCommission * 0.10;
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentVal = $commission * ($profile['agent_percentage'] / 100);
                    $suncashVal = $commission * ($profile['suncash_percentage'] / 100);
                    $ownerVal = $commission * ($profile['owner_percentage'] / 100);
                    $agentCommission = max($agentCommission, $agentVal);
                    $suncashCommission = max($suncashCommission, $suncashVal);
                    $ownerCommission = max($ownerCommission, $ownerVal);
                }
                break;

            case 4: // Fixed + Percentage — sums both legs
                $agentCommission = $fixedCommission * 0.10;
                $suncashCommission = $fixedCommission * 0.10;
                $ownerCommission = $fixedCommission * 0.10;
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentCommission += $commission * ($profile['agent_percentage'] / 100);
                    $suncashCommission += $commission * ($profile['suncash_percentage'] / 100);
                    $ownerCommission += $commission * ($profile['owner_percentage'] / 100);
                }
                break;

            // No default: any other commission_type (0, 5, ...) has no legacy switch arm — stays zero.
        }

        return [
            'agent_commission' => round($agentCommission, 2),
            'suncash_commission' => round($suncashCommission, 2),
            'owner_commission' => round($ownerCommission, 2),
        ];
    }

    /**
     * Legacy `Kiosk_commission_model::computeCommissionTimedate()` — used
     * ONLY by the Partner Settlement report. Unlike `computeCommission()`
     * above (always `$timedate=""`), this receives a real `$months`
     * multiplier for the "Fixed" leg (full months spanned by the report's
     * date range — see `KioskPartnerSettlementReportService::commissionMonths()`),
     * so commission_type 1/3/4 terminals DO produce non-zero commission
     * here, unlike every other ported commission report. Also unlike
     * `computeCommission()`, the Fixed leg's agent/suncash/owner split is
     * hardcoded to 10% each (`$agent_percentage = ... = 10/100`), not read
     * from any profile. Only the `agent_commission` leg is surfaced by
     * Partner Settlement's "Commission" column, but the full legacy return
     * shape (all three legs + `commission_type` label, used by the caller to
     * decide whether to add the Fixed leg once-per-terminal or per-transaction)
     * is preserved here.
     *
     * @return array{agent_commission: float, suncash_commission: float, owner_commission: float, commission_type: string}
     */
    public function computeCommissionTimedate(array &$terminalCache, array &$profileCache, string $transactionType, int $terminalId, float $amount, float $fees, int $months): array
    {
        $agentPercentage = $suncashPercentage = $ownerPercentage = 0.10;
        $agentCommission = $suncashCommission = $ownerCommission = 0.0;
        $commissionType = '';

        $terminal = $this->terminalConfig($terminalCache, $terminalId);
        if (! $terminal) {
            return ['agent_commission' => 0.0, 'suncash_commission' => 0.0, 'owner_commission' => 0.0, 'commission_type' => $commissionType];
        }

        $fixedCommission = $terminal['commission_fixed_value'] * $months;

        switch ($terminal['commission_type']) {
            case 1: // Fixed
                $commissionType = 'fixed';
                $agentCommission = $fixedCommission * $agentPercentage;
                $suncashCommission = $fixedCommission * $suncashPercentage;
                $ownerCommission = $fixedCommission * $ownerPercentage;
                break;

            case 2: // Percentage
                $commissionType = 'percentage';
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentCommission = $commission * ($profile['agent_percentage'] / 100);
                    $suncashCommission = $commission * ($profile['suncash_percentage'] / 100);
                    $ownerCommission = $commission * ($profile['owner_percentage'] / 100);
                }
                break;

            case 3: // Greater Amount — max(fixed leg, percentage leg) per commission recipient
                $commissionType = 'fixed';
                $agentCommission = $fixedCommission * $agentPercentage;
                $suncashCommission = $fixedCommission * $suncashPercentage;
                $ownerCommission = $fixedCommission * $ownerPercentage;
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentCommission = max($agentCommission, $commission * ($profile['agent_percentage'] / 100));
                    $suncashCommission = max($suncashCommission, $commission * ($profile['suncash_percentage'] / 100));
                    $ownerCommission = max($ownerCommission, $commission * ($profile['owner_percentage'] / 100));
                    $commissionType = 'greater_percentage';
                }
                break;

            case 4: // Fixed + Percentage — sums both legs
                $commissionType = 'fixed_percentage';
                $agentCommission = $fixedCommission * $agentPercentage;
                $suncashCommission = $fixedCommission * $suncashPercentage;
                $ownerCommission = $fixedCommission * $ownerPercentage;
                if ($profile = $this->percentageProfile($profileCache, $transactionType, $terminalId)) {
                    $commission = ($profile['provider_percentage'] / 100 * $amount) + $fees;
                    $agentCommission += $commission * ($profile['agent_percentage'] / 100);
                    $suncashCommission += $commission * ($profile['suncash_percentage'] / 100);
                    $ownerCommission += $commission * ($profile['owner_percentage'] / 100);
                }
                break;

            // No default: any other commission_type (0, 5, ...) has no legacy switch arm — stays zero.
        }

        return [
            'agent_commission' => round($agentCommission, 2),
            'suncash_commission' => round($suncashCommission, 2),
            'owner_commission' => round($ownerCommission, 2),
            'commission_type' => $commissionType,
        ];
    }
}
