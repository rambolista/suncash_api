<?php

namespace App\Services\Promotions;

use App\Models\Mysuncash\PromoEntry;
use App\Models\Mysuncash\PromoItem;
use App\Models\Mysuncash\PromoWinner;
use App\Models\Mysuncash\SuncashPromoSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Promotions > Grand Draw" — mirrors legacy admin's `christmas_promo::
 * grand_draw_wu()` / `select_grand_draw_winner()` (the controller name is
 * legacy naming drift: it's reused across whichever seasonal promo is
 * currently active, driven by `suncash_promo_settings.code` rather than
 * anything Christmas-specific).
 *
 * One "RUN" click picks a random eligible customer per configured island
 * (`ORDER BY RAND()`, one candidate row per customer via GROUP BY, customer
 * needs >= grand_draw_ticket_limit tickets), records the win, and decrements
 * the matching prize. Legacy's admin page also had an island `<select>`, but
 * it was dead code (wrapped in an HTML comment, never rendered) — the real
 * island scoping always came from a fixed backend-configured list
 * (`config('promotions.grand_draw_islands')`), which is what this replicates.
 *
 * Not replicated: legacy's `call_pos_api(...itemWinners)` push notification
 * to the separate POS/customer-app system — an external integration this
 * codebase has no credentials or established client for (same reasoning as
 * `InfobipSmsService`'s safety gate elsewhere in this app). The win is still
 * fully recorded in `promo_winners`/`promo_entries`/`promo_items`, which is
 * what the customer-facing app actually reads.
 */
class GrandDrawService
{
    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    private function activePromo(): ?SuncashPromoSetting
    {
        return SuncashPromoSetting::where('code', $this->activePromoType())
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>=', now())
            ->first();
    }

    private function isScheduledToday(): bool
    {
        return PromoItem::where('draw_type', 'grand_draw')
            ->where('event_description', $this->activePromoType())
            ->where('status', PromoItem::STATUS_ACTIVE)
            ->whereDate('draw_date', now()->toDateString())
            ->where('remaining_quantity', '>', 0)
            ->exists();
    }

    private function winnerCount(SuncashPromoSetting $promo): int
    {
        return (string) $promo->is_gd_separate_date === '1' ? 1 : (int) ($promo->grand_winner_count ?: 1);
    }

    public function status(): array
    {
        $promo = $this->activePromo();
        $winners = $promo ? $this->winners() : [];

        return [
            'promo_title' => $promo?->description,
            'promo_active' => (bool) $promo,
            'can_run' => (bool) $promo && $this->isScheduledToday() && $winners === [],
            'can_show' => $winners !== [],
        ];
    }

    /** Port of legacy `get_grand_draw_winner()` — today's already-drawn winner(s), for "Show Winner". */
    public function winners(int $limit = 5): array
    {
        return PromoWinner::query()
            ->join('customers', 'customers.id', '=', 'promo_winners.customer_id')
            ->join('promo_items', 'promo_items.id', '=', 'promo_winners.promo_id')
            ->where('promo_winners.status', PromoWinner::STATUS_WON)
            ->where('promo_winners.prize_type', PromoWinner::PRIZE_TYPE_ITEM)
            ->where('promo_winners.promo_type', $this->activePromoType())
            ->where('promo_items.draw_type', 'grand_draw')
            ->whereDate('promo_winners.draw_date', now()->toDateString())
            ->where('customers.status', 'A')
            ->where('customers.is_locked', 0)
            ->orderByDesc('promo_winners.id')
            ->limit($limit)
            ->get([
                DB::raw("CONCAT(customers.first_name,' ',customers.last_name) as customer_name"),
                DB::raw("CONCAT('TICKET #0', promo_winners.ticket_id) as ticket_no"),
                DB::raw("CONCAT(promo_items.item_description,'!') as item_description"),
                'promo_items.image_url',
            ])
            ->map(fn ($row) => [
                'customer_name' => $row->customer_name,
                'ticket_no' => $row->ticket_no,
                'item_description' => $row->item_description,
                'image_url' => $row->image_url,
            ])
            ->all();
    }

    /**
     * Port of legacy `select_grand_draw_winner()` — runs the draw and
     * returns the freshly-drawn winner(s) in the same shape as winners().
     *
     * @throws ValidationException
     */
    public function run(): array
    {
        $promo = $this->activePromo();
        if (! $promo) {
            throw ValidationException::withMessages(['promo' => ['This promo is not currently active.']]);
        }
        if (! $this->isScheduledToday()) {
            throw ValidationException::withMessages(['promo' => ["Sorry, today is not {$promo->description}'s grand draw."]]);
        }
        if ($this->winners() !== []) {
            throw ValidationException::withMessages(['promo' => ['A winner has already been drawn today.']]);
        }

        $totalCount = $this->winnerCount($promo);

        $prizes = PromoItem::where('draw_type', 'grand_draw')
            ->where('event_description', $this->activePromoType())
            ->where('status', PromoItem::STATUS_ACTIVE)
            ->whereDate('draw_date', now()->toDateString())
            ->where('remaining_quantity', '>', 0)
            ->orderBy('draw_date')
            ->limit($totalCount)
            ->get();

        if ($prizes->isEmpty()) {
            throw ValidationException::withMessages(['prize' => ['Please set up a grand draw prize.']]);
        }
        if ($prizes->count() < $totalCount) {
            throw ValidationException::withMessages(['prize' => ["Ensure the number of prizes is not less than the number of winners ({$totalCount})."]]);
        }

        $islands = array_values((array) config('promotions.grand_draw_islands'));
        $ticketLimit = (int) config('promotions.grand_draw_ticket_limit');
        $excludedCustomerIds = $this->excludedCustomerIds();

        $drawnCount = 0;
        $islandIndex = 0;
        while ($drawnCount < $prizes->count() && $islandIndex < count($islands)) {
            $winner = $this->pickWinner($islands[$islandIndex], $excludedCustomerIds, $ticketLimit);
            $islandIndex++;

            if (! $winner) {
                continue;
            }

            $this->recordWin($winner, $prizes[$drawnCount]);
            $excludedCustomerIds[] = $winner->customer_id;
            $drawnCount++;
        }

        return $this->winners($prizes->count());
    }

    /** Union of legacy `customer_grand_winners()`'s three disqualifying sets. */
    private function excludedCustomerIds(): array
    {
        $pastWinners = PromoWinner::query()
            ->join('promo_items', 'promo_items.id', '=', 'promo_winners.promo_id')
            ->where('promo_items.draw_type', 'grand_draw')
            ->where('promo_winners.prize_type', PromoWinner::PRIZE_TYPE_ITEM)
            ->pluck('promo_winners.customer_id');

        $inactive = DB::connection('mysuncash')->table('customer_promo_status')
            ->where('status', 'INACTIVE')
            ->pluck('customer_id');

        $blacklisted = DB::connection('mysuncash')->table('promo_prize_blacklist')
            ->where('status', 'active')
            ->pluck('customer_id');

        return $pastWinners->merge($inactive)->merge($blacklisted)->unique()->values()->all();
    }

    /** Port of legacy `tools_model::select_grand_draw_winner()` — one random eligible customer for the given island. */
    private function pickWinner(int $islandId, array $excludedCustomerIds, int $ticketLimit): ?object
    {
        return PromoEntry::query()
            ->join('customers', function ($join) use ($islandId) {
                $join->on('customers.id', '=', 'promo_entries.customer_id')
                    ->where('customers.is_locked', 0)
                    ->where('customers.is_sub_account', '0')
                    ->where('customers.status', 'A');
                if ($islandId) {
                    $join->where('customers.island', $islandId);
                }
            })
            ->where('promo_entries.status', PromoEntry::STATUS_ACTIVE)
            ->where('promo_entries.promo_type', $this->activePromoType())
            ->when($excludedCustomerIds !== [], fn ($query) => $query->whereNotIn('promo_entries.customer_id', $excludedCustomerIds))
            ->groupBy('promo_entries.customer_id')
            ->havingRaw('COUNT(promo_entries.id) >= ?', [$ticketLimit])
            ->inRandomOrder()
            ->limit(1)
            ->first(['promo_entries.id', 'promo_entries.customer_id']);
    }

    /** Port of legacy `update_winner_data()` + `update_xmas_grand_draw_prize()`. */
    private function recordWin(object $winner, PromoItem $prize): void
    {
        PromoEntry::where('id', $winner->id)->update([
            'status' => PromoEntry::STATUS_WON,
            'raffle_ticket_entries' => 0,
            'item_id' => $prize->id,
            'prize_type' => PromoWinner::PRIZE_TYPE_ITEM,
        ]);

        PromoWinner::create([
            'ticket_id' => $winner->id,
            'customer_id' => $winner->customer_id,
            'promo_id' => $prize->id,
            'prize_type' => PromoWinner::PRIZE_TYPE_ITEM,
            'promo_type' => $this->activePromoType(),
            'status' => PromoWinner::STATUS_WON,
        ]);

        $prize->update(['status' => PromoItem::STATUS_USED, 'remaining_quantity' => 0]);
    }
}
