<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\CashPromoSetting;
use App\Models\Mysuncash\PromoEntry;
use App\Models\Mysuncash\PromoInstantTicket;
use App\Models\Mysuncash\PromoItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Instant Winners" (legacy `christmas_promo/promo_instant_winner` +
 * `tools_model::get_instant_prizes()`/`get_instant_winners()`/
 * `add_instant_winner()`/`get_existing_ticket()`). Manually assigns an
 * instant-prize winner by raffle ticket number. Legacy's second "WU Promo"
 * tab was entirely commented out/dead in its own view, so only the single
 * active-promo list is ported here.
 */
class InstantWinnersService
{
    /** Legacy's PROMO_INSTANT_GAP constant — manually-assigned ticket numbers must be above the real ticket pool by this much, to avoid colliding with tickets customers actually earn. */
    private const TICKET_GAP = 100;

    public const COLUMNS = [
        ['key' => 'ticket_id', 'label' => 'Ticket'],
        ['key' => 'created_date', 'label' => 'Created Date'],
        ['key' => 'prize_description', 'label' => 'Prize'],
    ];

    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    /** Legacy's get_instant_prizes() — the dropdown of active instant-prize cash/item prizes. */
    public function prizes(): array
    {
        $cash = CashPromoSetting::where('status', 'ACTIVE')
            ->where('draw_type', 'instant_prize')
            ->where('promo_type', $this->activePromoType())
            ->get()
            ->map(fn (CashPromoSetting $c) => [
                'id' => $c->id,
                'prize_type' => 'cash',
                'label' => "CASH: {$c->description} - \${$c->price}",
            ]);

        $items = PromoItem::where('status', 'ACTIVE')
            ->where('draw_type', 'instant_prize')
            ->where('event_description', $this->activePromoType())
            ->get()
            ->map(fn (PromoItem $i) => [
                'id' => $i->id,
                'prize_type' => 'item',
                'label' => "ITEM: {$i->item_description}",
            ]);

        return $cash->concat($items)->values()->all();
    }

    /** Legacy's get_existing_ticket() — the minimum ticket number a manually-added winner may use. */
    public function nextTicketThreshold(): int
    {
        return PromoEntry::count() + self::TICKET_GAP;
    }

    public function list(): array
    {
        $tickets = PromoInstantTicket::where('promo_type', $this->activePromoType())
            ->where('status', PromoInstantTicket::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get();

        $cashPrizes = CashPromoSetting::whereIn('id', $tickets->where('prize_type', 'cash')->pluck('prize_id')->unique())
            ->get(['id', 'price', 'description'])->keyBy('id');
        $itemPrizes = PromoItem::whereIn('id', $tickets->where('prize_type', 'item')->pluck('prize_id')->unique())
            ->get(['id', 'item_description', 'image_url'])->keyBy('id');

        return $tickets->map(function (PromoInstantTicket $ticket) use ($cashPrizes, $itemPrizes) {
            $image = null;
            if ($ticket->prize_type === 'cash') {
                $cash = $cashPrizes->get($ticket->prize_id);
                $description = $cash ? "CASH: \${$cash->price}" : null;
            } else {
                $item = $itemPrizes->get($ticket->prize_id);
                $description = $item ? "ITEM: {$item->item_description}" : null;
                $image = $item?->image_url;
            }

            return [
                'id' => $ticket->id,
                'ticket_id' => '#0'.$ticket->ticket_id,
                'created_date' => $ticket->timestamp,
                'prize_description' => $description,
                'item_image' => $image,
            ];
        })->all();
    }

    /** @throws ValidationException */
    public function create(array $data, User $actor): void
    {
        $ticketId = $data['ticket_id'] ?? null;

        if (filled($ticketId) && PromoInstantTicket::where('ticket_id', $ticketId)->exists()) {
            throw ValidationException::withMessages(['ticket_id' => ['Ticket number already entered.']]);
        }
        if (! filled($ticketId)) {
            throw ValidationException::withMessages(['ticket_id' => ['Ticket is required.']]);
        }
        if (! filled($data['prize_id'] ?? null)) {
            throw ValidationException::withMessages(['prize_id' => ['Promo is required.']]);
        }
        if ((int) $ticketId < $this->nextTicketThreshold()) {
            throw ValidationException::withMessages(['ticket_id' => ['Invalid ticket no.']]);
        }

        PromoInstantTicket::create([
            'ticket_id' => (int) $ticketId,
            'prize_id' => $data['prize_id'],
            'prize_type' => $data['prize_type'] ?? null,
            'promo_type' => $this->activePromoType(),
            'status' => PromoInstantTicket::STATUS_ACTIVE,
            'created_by' => $actor->name,
        ]);
    }
}
