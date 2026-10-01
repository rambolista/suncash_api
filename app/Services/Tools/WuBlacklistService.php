<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\WebLog;
use App\Models\Mysuncash\WuBlockList;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > WU Blacklist" (legacy `tools/wu_blacklist` +
 * `tools_model::get_wu_blacklist()`/`add_wu_blacklist()`/
 * `activate_blacklist()`/`inactivate_blacklist()`). A flat list of names
 * blocked from Western Union transactions, with an Active/Inactive toggle.
 * No edit of the name itself and no delete — matches legacy exactly.
 */
class WuBlacklistService
{
    public const COLUMNS = [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'status', 'label' => 'Status'],
    ];

    public function list(): array
    {
        return WuBlockList::orderByDesc('id')->get(['id', 'name', 'status'])->all();
    }

    /** @throws ValidationException */
    public function create(string $name, User $actor, Request $request): WuBlockList
    {
        if (! filled($name)) {
            throw ValidationException::withMessages(['name' => ['Please check input fields.']]);
        }

        $entry = WuBlockList::create([
            'name' => $name,
            'status' => WuBlockList::STATUS_ACTIVE,
            'updated_by' => (string) $actor->id,
        ]);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name,
            'data' => $entry->name,
            'log_type' => 'ADD_WU_BLACKLISTED',
            'user_ip_address' => $request->ip(),
            'cloudflare_ip_address' => $request->ip(),
        ]);

        return $entry;
    }

    /** @throws ValidationException */
    public function setStatus(int $id, bool $active, User $actor, Request $request): WuBlockList
    {
        $entry = WuBlockList::find($id);
        if (! $entry) {
            throw ValidationException::withMessages(['id' => ['Blacklist entry not found.']]);
        }

        $entry->update([
            'status' => $active ? WuBlockList::STATUS_ACTIVE : WuBlockList::STATUS_INACTIVE,
            'updated_by' => (string) $actor->id,
        ]);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name,
            'data' => ($active ? 'ACTIVE - ' : 'INACTIVE - ').$entry->name,
            'log_type' => 'UPDATE_WU_BLACKLISTED',
            'user_ip_address' => $request->ip(),
            'cloudflare_ip_address' => $request->ip(),
        ]);

        return $entry;
    }
}
