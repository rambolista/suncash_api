<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CardWhitelist;
use App\Models\User;

/**
 * "Tools > Credit Card Approval" (legacy `tools/creditcard_approval`) — review
 * queue for cards submitted at checkout that need manual whitelisting.
 * Legacy's "ID Number" column is actually the free-text `customer_id` field
 * on this table (not a customers.id FK — it's varchar and holds whatever ID
 * number the submitter typed), kept as-is here.
 */
class CreditCardApprovalService
{
    public const STATUS_MAP = [
        'pending' => 'for_approval',
        'approved' => 'approved',
        'rejected' => 'rejected',
    ];

    public function list(string $tab): array
    {
        $status = self::STATUS_MAP[$tab] ?? abort(422, 'Invalid tab.');

        return CardWhitelist::where('status', $status)
            ->orderByDesc('created_at')
            ->get([
                'id', 'created_at', 'card_name', 'card_last4digits', 'card_type',
                'customer_id', 'status', 'remarks', 'customerid_pic_url', 'card_pic_url', 'cardwithid_pic_url',
            ])
            ->map(fn (CardWhitelist $c) => [
                'id' => $c->id,
                'created_at' => $c->created_at,
                'card_name' => $c->card_name,
                'card_last4digits' => $c->card_last4digits,
                'card_type' => $c->card_type,
                'id_number' => $c->customer_id,
                'status' => $c->status,
                'remarks' => $c->remarks,
                'customerid_pic_url' => $c->customerid_pic_url,
                'card_pic_url' => $c->card_pic_url,
                'cardwithid_pic_url' => $c->cardwithid_pic_url,
            ])
            ->all();
    }

    public function counts(): array
    {
        return CardWhitelist::query()
            ->selectRaw('status, count(*) as total')
            ->whereIn('status', self::STATUS_MAP)
            ->groupBy('status')
            ->pluck('total', 'status')
            ->pipe(fn ($counts) => [
                'pending' => (int) ($counts['for_approval'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ]);
    }

    public function approve(int $id, User $actor): void
    {
        $card = CardWhitelist::findOrFail($id);
        $card->update(['status' => 'approved']);

        ActivityLog::recordAction($actor, 'Tools - Credit Card Approval', 'approved', "Approved card whitelist request #{$id} ({$card->card_name})");
    }

    public function reject(int $id, string $reason, User $actor): void
    {
        $card = CardWhitelist::findOrFail($id);
        $card->update(['status' => 'rejected', 'remarks' => $reason]);

        ActivityLog::recordAction($actor, 'Tools - Credit Card Approval', 'rejected', "Rejected card whitelist request #{$id} ({$card->card_name}): {$reason}");
    }
}
