<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\CardBlacklist;
use App\Models\Mysuncash\CustomerCreditCard;
use App\Models\Mysuncash\WebLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Card Blacklist" (legacy `tools/card_blacklist` +
 * `tools_model::get_card_blacklist()`/`add_card_blacklist()`/
 * `edit_card_blacklist()`/`activate_card_blacklist()`/
 * `inactivate_card_blacklist()`). Blocks a card (or every card matching a
 * name/number/type/expiry combination, depending on `validation_type`)
 * from being linked/used.
 */
class CardBlacklistService
{
    private const VALIDATION_TYPES = ['all', 'except_name', 'name_only'];

    public const COLUMNS = [
        ['key' => 'name', 'label' => 'Card Holder Name'],
        ['key' => 'last_4_digit_number', 'label' => 'Last 4 Digit Card Number'],
        ['key' => 'expiry_date', 'label' => 'Expiry Date'],
        ['key' => 'card_type', 'label' => 'Card Type'],
        ['key' => 'validation_type', 'label' => 'Validation Type'],
    ];

    public function list(): array
    {
        return CardBlacklist::orderByDesc('id')->get()->all();
    }

    /** @throws ValidationException */
    public function find(int $id): CardBlacklist
    {
        $entry = CardBlacklist::find($id);
        if (! $entry) {
            throw ValidationException::withMessages(['id' => ['Card details not found.']]);
        }

        return $entry;
    }

    /**
     * Legacy requires different fields depending on validation_type — the
     * fields that type doesn't screen on are irrelevant to the match, so
     * they're optional. (Legacy's own client-side JS has this exact rule for
     * Add, but a copy/paste bug on Edit instead gates on `name_only`
     * requiring the card fields; not replicated — that's clearly a mistake,
     * not an intentional difference between the two forms.)
     */
    private function validate(array $data): void
    {
        $errors = [];

        $validationType = $data['validation_type'] ?? '';
        if (! in_array($validationType, self::VALIDATION_TYPES, true)) {
            $errors['validation_type'] = ['Please Select Validation Type.'];
        }

        $needsName = in_array($validationType, ['all', 'name_only'], true);
        $needsCard = in_array($validationType, ['all', 'except_name'], true);

        if ($needsName && ! filled($data['name'] ?? null)) {
            $errors['name'] = ['Please check name.'];
        }
        if ($needsCard) {
            if (! filled($data['card_num'] ?? null)) {
                $errors['card_num'] = ['Please check last 4 digit number.'];
            }
            if (! filled($data['card_type'] ?? null)) {
                $errors['card_type'] = ['Please check card type.'];
            }
            if (! filled($data['exp'] ?? null)) {
                $errors['exp'] = ['Please check card type.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Legacy's ifCardBlockedList(): an existing ACTIVE entry already covers
     * the new one if (1) it's an exact name+card+type+expiry match, or (2) a
     * `name_only` entry already blocks that name, or — when no entry
     * matches the name at all — (3) an `except_name` entry already blocks
     * that card+type+expiry regardless of name.
     */
    private function alreadyBlocked(string $name, string $cardNum, string $cardType, string $exp): bool
    {
        $byName = CardBlacklist::whereRaw('LOWER(name) = ?', [strtolower($name)])->where('is_active', 'Y');

        if ($byName->exists()) {
            $exactMatch = (clone $byName)
                ->where('last_4_digit_number', $cardNum)
                ->where('card_type', $cardType)
                ->where('expiry_date', $exp)
                ->exists();

            return $exactMatch || (clone $byName)->where('validation_type', 'name_only')->exists();
        }

        return CardBlacklist::where('last_4_digit_number', $cardNum)
            ->where('card_type', $cardType)
            ->where('expiry_date', $exp)
            ->where('validation_type', 'except_name')
            ->where('is_active', 'Y')
            ->exists();
    }

    /** Legacy's per-validation_type lookup of already-linked cards to auto-tag as blacklisted. */
    private function matchingCustomerCards(array $data): Collection
    {
        $query = CustomerCreditCard::query();

        match ($data['validation_type']) {
            'all' => $query->where('cardholder_name', 'like', $data['name'])
                ->where('card_last_four_digits', $data['card_num'])
                ->where('card_type', $data['card_type'])
                ->where('expiration', $data['exp']),
            'name_only' => $query->where('cardholder_name', 'like', $data['name']),
            default => $query->where('card_last_four_digits', $data['card_num'])
                ->where('card_type', $data['card_type'])
                ->where('expiration', $data['exp']),
        };

        return $query->get();
    }

    /** @throws ValidationException */
    public function create(array $data, User $actor, Request $request): void
    {
        $this->validate($data);

        if ($this->alreadyBlocked($data['name'] ?? '', $data['card_num'] ?? '', $data['card_type'] ?? '', $data['exp'] ?? '')) {
            throw ValidationException::withMessages(['name' => ['Setup for this customer already exist.']]);
        }

        $matches = $this->matchingCustomerCards($data);

        if ($matches->isNotEmpty()) {
            foreach ($matches as $card) {
                $entry = CardBlacklist::create([
                    'name' => $card->cardholder_name,
                    'last_4_digit_number' => $card->card_last_four_digits,
                    'card_type' => $card->card_type,
                    'expiry_date' => $card->expiration,
                    'validation_type' => $data['validation_type'],
                    'updated_by' => (string) $actor->id,
                ]);

                $card->is_blacklisted = 1;
                if ((int) $card->is_pending === 1) {
                    $card->is_pending = 0;
                }
                $card->updated_by = $actor->id;
                $card->save();

                WebLog::create([
                    'customer_id' => $card->customer_id,
                    'user_id' => $actor->id,
                    'updated_by' => $actor->name,
                    'data' => json_encode($entry->only(['name', 'last_4_digit_number', 'card_type', 'expiry_date', 'validation_type'])),
                    'log_type' => 'ADD_CARD_BLACKLISTED',
                    'user_ip_address' => $request->ip(),
                    'cloudflare_ip_address' => $request->ip(),
                    'web_channel' => 'admin',
                ]);
            }

            return;
        }

        $entry = CardBlacklist::create([
            'name' => $data['name'] ?? null,
            'last_4_digit_number' => $data['card_num'] ?? null,
            'card_type' => $data['card_type'] ?? null,
            'expiry_date' => $data['exp'] ?? null,
            'validation_type' => $data['validation_type'],
            'updated_by' => (string) $actor->id,
        ]);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name,
            'data' => json_encode($entry->only(['name', 'last_4_digit_number', 'card_type', 'expiry_date', 'validation_type'])),
            'log_type' => 'ADD_CARD_BLACKLISTED',
            'user_ip_address' => $request->ip(),
            'cloudflare_ip_address' => $request->ip(),
        ]);
    }

    /** @throws ValidationException */
    public function update(int $id, array $data, User $actor, Request $request): CardBlacklist
    {
        $entry = $this->find($id);

        $this->validate($data);

        $duplicate = CardBlacklist::whereRaw('LOWER(name) = ?', [strtolower($data['name'] ?? '')])
            ->where('id', '!=', $id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['Setup for this customer already exist.']]);
        }

        $update = [
            'name' => $data['name'] ?? null,
            'last_4_digit_number' => $data['card_num'] ?? null,
            'card_type' => $data['card_type'] ?? null,
            'expiry_date' => $data['exp'] ?? null,
            'validation_type' => $data['validation_type'],
            'updated_by' => (string) $actor->id,
        ];
        $entry->update($update);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name,
            'data' => json_encode($update),
            'log_type' => 'EDIT_CARD_BLACKLISTED',
            'user_ip_address' => $request->ip(),
            'cloudflare_ip_address' => $request->ip(),
        ]);

        return $entry;
    }

    /** @throws ValidationException */
    public function setStatus(int $id, bool $active, User $actor, Request $request): CardBlacklist
    {
        $entry = $this->find($id);

        $entry->update([
            'is_active' => $active ? 'Y' : 'N',
            'updated_by' => (string) $actor->id,
        ]);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name,
            'data' => ($active ? 'ACTIVE - ' : 'INACTIVE - ').$entry->name,
            'log_type' => 'UPDATE_CARD_BLACKLISTED',
            'user_ip_address' => $request->ip(),
            'cloudflare_ip_address' => $request->ip(),
        ]);

        return $entry;
    }
}
