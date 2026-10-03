<?php

namespace App\Services\Promotions;

use App\Models\ActivityLog;
use App\Models\Mysuncash\SuncashPromoSetting;
use App\Models\Mysuncash\TicketPromoSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Promotions > Promo Ticket Settings" (legacy `tools/ticket_promo_settings` + `tools_model::*_ticket_promo`).
 * Free raffle-ticket prizes for the active promo: how many tickets each winner receives (Tickets), how many winners
 * can claim it (Total Count) and how many slots are still open (Remaining Count), plus a draw date.
 *
 * Kept from legacy: the list shows only ACTIVE rows of the active promo; Remaining defaults to Total Count and can't
 * exceed it; an instant prize is a single winner (count and remaining forced to 1) with no draw date; a draw date
 * is unique per promo among active rows (compared by day); delete only flags the row DELETED.
 * Legacy let you update/delete any id (deleted or another promo's) — here they must be an active row of the active
 * promo — and called an update that changed nothing a failure ("Cant update Ticket Promo.").
 * Legacy only insisted on a draw date when editing a weekly draw; here a weekly draw needs one when adding too
 * (Copy even tells you to pick a new one), since a weekly draw without a date can never be drawn.
 */
class TicketPromoSettingService
{
    public const DRAW_TYPES = ['weekly_draw' => 'WEEKLY DRAW', 'grand_draw' => 'GRAND DRAW', 'instant_prize' => 'INSTANT PRIZE'];

    /** Legacy offered a single hard-coded Service and Service Reference, and only the Weekly Draw type. */
    public const SERVICES = ['sunpass_event_ticket' => 'Sunpass Event Promo'];

    public const SERVICE_REFS = ['106' => 'Red Cross'];

    private const LOGGED = ['ticket_count', 'quantity', 'remaining_quantity', 'description', 'draw_type', 'draw_date', 'service', 'service_ref', 'status'];

    private function activePromoType(): string
    {
        return (string) config('promotions.active_code');
    }

    private function active()
    {
        return TicketPromoSetting::where('promo_type', $this->activePromoType())->where('status', TicketPromoSetting::STATUS_ACTIVE);
    }

    public function list(): array
    {
        $promo = SuncashPromoSetting::where('code', $this->activePromoType())->first();

        return [
            'promo_title' => $promo?->description,
            'draw_types' => [['value' => 'weekly_draw', 'label' => 'Weekly Draw']],
            'services' => $this->options(self::SERVICES),
            'service_refs' => $this->options(self::SERVICE_REFS),
            'data' => $promo ? $this->active()->orderBy('id')->get()->map(fn ($r) => $this->present($r))->all() : [],
        ];
    }

    private function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }

    private function present(TicketPromoSetting $r): array
    {
        return [
            'id' => $r->id,
            'created_date' => $r->created_date,
            'ticket_count' => $r->ticket_count,
            'quantity' => $r->quantity,
            'remaining_quantity' => $r->remaining_quantity,
            'description' => $r->description,
            'draw_type' => $r->draw_type,
            'draw_type_label' => self::DRAW_TYPES[$r->draw_type] ?? self::DRAW_TYPES['instant_prize'],
            'promo_type' => $r->promo_type,
            'draw_date' => $r->draw_date,
            'status' => $r->status,
            'service' => $r->service,
            'service_ref' => $r->service_ref,
        ];
    }

    /** @return array clean values ready to store */
    private function validate(array $d, ?TicketPromoSetting $existing = null): array
    {
        $errors = [];
        $drawType = $d['draw_type'] ?? null;
        if (! is_string($drawType) || ! array_key_exists($drawType, self::DRAW_TYPES)) {
            $errors['draw_type'] = ['Invalid draw type.'];
        }
        $instant = $drawType === 'instant_prize';

        $tickets = $d['ticket_count'] ?? null;
        if (! is_numeric($tickets) || (int) $tickets <= 0) {
            $errors['ticket_count'] = ['Invalid number of free tickets.'];
        }

        $quantity = $instant ? 1 : ($d['quantity'] ?? null);
        $remaining = $instant ? 1 : ($d['remaining_quantity'] ?? null);
        if (! is_numeric($quantity) || (int) $quantity <= 0) {
            $errors['quantity'] = ['Invalid total count.'];
        } elseif (filled($remaining) && (! is_numeric($remaining) || (int) $remaining < 0)) {
            $errors['remaining_quantity'] = ['Invalid remaining count.'];
        } elseif (filled($remaining) && (int) $remaining > (int) $quantity) {
            $errors['remaining_quantity'] = ['Remaining count cannot be higher than the total count.'];
        }

        if (! filled($d['description'] ?? null) || mb_strlen((string) $d['description']) > 100) {
            $errors['description'] = ['Description is required (100 characters max).'];
        }
        foreach (['service' => [self::SERVICES, 'service'], 'service_ref' => [self::SERVICE_REFS, 'service reference']] as $key => [$allowed, $label]) {
            $v = (string) ($d[$key] ?? '');
            if (! array_key_exists($v, $allowed) && $v !== (string) $existing?->{$key}) {
                $errors[$key] = ["Select a valid {$label}."];
            }
        }

        // Only weekly draws have a draw date; instant / grand rows never keep one.
        $drawDate = null;
        if ($drawType === 'weekly_draw') {
            try {
                $drawDate = filled($d['draw_date'] ?? null) ? Carbon::parse($d['draw_date']) : null;
            } catch (\Throwable) {
            }
            if (! $drawDate) {
                $errors['draw_date'] = ['Invalid raffle date.'];
            } elseif ($this->dateTaken($drawDate, $existing?->id)) {
                $errors['draw_date'] = ['A Ticket Promo is already set for '.$drawDate->toDateString().'. Please choose a different draw date.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'ticket_count' => (int) $tickets,
            'quantity' => (int) $quantity,
            'remaining_quantity' => filled($remaining) ? (int) $remaining : (int) $quantity,
            'description' => $d['description'],
            'draw_type' => $drawType,
            'draw_date' => $drawDate?->format('Y-m-d H:i:s'),
            'service' => (string) $d['service'],
            'service_ref' => (string) $d['service_ref'],
        ];
    }

    /** One ticket promo per draw day among the active rows (same-day range, so the draw_date index can be used). */
    private function dateTaken(Carbon $date, ?int $ignoreId): bool
    {
        return $this->active()
            ->whereBetween('draw_date', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function create(array $data, Request $request): TicketPromoSetting
    {
        $values = $this->validate($data);

        $row = TicketPromoSetting::create($values + [
            'promo_type' => $this->activePromoType(),
            'target_group_type' => 'all',
            'target_group' => '',
            'created_date' => now(),
            'status' => TicketPromoSetting::STATUS_ACTIVE,
        ]);

        ActivityLog::recordCreated($request->user(), 'Promo Ticket Settings', $row, self::LOGGED, $request);

        return $row;
    }

    public function update(int $id, array $data, Request $request): TicketPromoSetting
    {
        $row = $this->find($id);
        $values = $this->validate($data, $row);
        $before = $row->getAttributes();

        $row->update($values + ['updated_date' => now()]);

        ActivityLog::recordUpdated($request->user(), 'Promo Ticket Settings', $row, $before, self::LOGGED, $request);

        return $row->fresh();
    }

    public function delete(int $id, Request $request): void
    {
        $row = $this->find($id);
        $before = $row->getAttributes();

        $row->update(['status' => TicketPromoSetting::STATUS_DELETED, 'updated_date' => now()]);

        ActivityLog::recordUpdated($request->user(), 'Promo Ticket Settings', $row, $before, ['status'], $request, "Removed ticket promo #{$row->id}: {$row->description}");
    }

    private function find(int $id): TicketPromoSetting
    {
        return $this->active()->find($id)
            ?? throw ValidationException::withMessages(['id' => ['Ticket promo not found.']]);
    }
}
