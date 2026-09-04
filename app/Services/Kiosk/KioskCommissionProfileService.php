<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskProduct;
use App\Models\Mysuncash\KioskProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Commission Profiles" (legacy `fastpay::kiosk_comm_profiles()` /
 * `display_commission_profile()` / `add_profile()` /
 * `copy_existing_manager_info()` / `edit_commission_profile()` /
 * `delete_kiosk_profile()`). A "profile" is really just a shared
 * `profile_name` value across many `kiosk_profiles` rows, one row per
 * `kiosk_product_id` — this is the same table `KioskCommissionMath` already
 * reads from when computing a transaction's commission split.
 *
 * Two legacy behaviors were fixed rather than replicated, both flagged here
 * since they're genuine data-integrity gaps, not display quirks:
 *
 * 1. Legacy's "Add Profile" (`add_profile()`) loops over its own hardcoded
 *    `$transaction_types` label map (~50 entries covering every product this
 *    app has ever supported) rather than the live `kiosk_products` table,
 *    and inserts `kiosk_product_id = false` (PHP `false`, not a real id) for
 *    any code with no matching `kiosk_products` row — silently creating
 *    orphaned rows. This port iterates the real `kiosk_products` table
 *    instead, applying the exact same hand-picked default percentages
 *    (`DEFAULT_PERCENTAGES`, straight from `add_profile()`'s if/else chain)
 *    to the same product codes and zero-defaulting everything else,
 *    preserving legacy's actual intent (seed sensible category defaults)
 *    without the orphan-row bug.
 * 2. Legacy's "Add Profile" never checks for an existing `profile_name`
 *    (only "Copy Profile" does, via `is_profile_name_existing()`) — so two
 *    profiles can silently share a name, after which any lookup/join by
 *    name (including `KioskCommissionMath`'s own terminal→profile match)
 *    becomes ambiguous. This port enforces the same uniqueness check on
 *    both Add and Copy.
 * 3. Legacy's delete (`delete_kiosk_profile()`) is a hard
 *    `DELETE FROM kiosk_profiles WHERE profile_name = ?` with NO check
 *    against `kiosk_terminal.profile_id` — deleting a profile still in use
 *    silently makes every terminal referencing it resolve to "no profile"
 *    (zero commission) rather than erroring. This port blocks the delete
 *    instead, listing which terminals still reference the profile. Also
 *    not replicated: legacy's tangled manager-soft-delete side path, which
 *    can leave the actual `kiosk_profiles` rows undeleted while still
 *    reporting success — the "kiosk manager" sub-entity itself is
 *    unreachable from the live legacy UI (its trigger buttons are
 *    commented out of the toolbar), so it isn't ported at all.
 */
class KioskCommissionProfileService
{
    /** Legacy `add_profile()`'s hand-picked defaults, keyed by `kiosk_products.product_code`. Every other product defaults to all-zero. */
    private const DEFAULT_PERCENTAGES = [
        'GAMINGHOUSE_DEPOSIT' => ['provider_percentage' => '1.50', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'GOVERNMENT_PAYMENT' => ['provider_percentage' => '1.50', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'BTC' => ['provider_percentage' => '1.50', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'ALIV' => ['provider_percentage' => '1.50', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'BPL' => ['provider_percentage' => '1.00', 'cap_amount' => '10.00', 'minimum_amount' => '1.99', 'frequency_in_limit_days' => 7],
        'WSC' => ['provider_percentage' => '1.00', 'cap_amount' => '10.00', 'minimum_amount' => '1.99', 'frequency_in_limit_days' => 7],
        'GAMINGHOUSE_WITHDRAWAL' => ['provider_percentage' => '1.00', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'CB' => ['provider_percentage' => '2.00', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'BTC_TOPUP' => ['provider_percentage' => '9.00', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'ALIV_TOPUP' => ['provider_percentage' => '8.5', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
        'INTERNATIONAL_TOPUP' => ['provider_percentage' => '6.00', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0],
    ];

    private const ZERO_DEFAULTS = ['provider_percentage' => '0.00', 'cap_amount' => '0.00', 'minimum_amount' => '0.00', 'frequency_in_limit_days' => 0];

    public function listProducts(): array
    {
        return KioskProduct::orderBy('product_name')->get(['id', 'product_name', 'product_code'])->all();
    }

    /**
     * Legacy `get_kiosk_profiles()` — distinct profile names across every
     * row. One live row has a NULL `profile_name` (pre-existing data debris,
     * not a real name-addressable profile — legacy's own name-keyed lookups
     * can never reach it either, since SQL `= NULL` never matches). Excluded
     * here rather than surfaced as an unusable/unselectable list entry.
     */
    public function listProfileNames(): array
    {
        return KioskProfile::query()
            ->select('profile_name')
            ->whereNotNull('profile_name')
            ->where('profile_name', '!=', '')
            ->distinct()
            ->orderBy('profile_name')
            ->pluck('profile_name')
            ->all();
    }

    private function profileNameExists(string $profileName): bool
    {
        return KioskProfile::where('profile_name', $profileName)->exists();
    }

    /** Legacy `get_profile_info_by_name()`, parameterized instead of string-interpolated. */
    public function showProfile(string $profileName): array
    {
        return DB::connection('mysuncash')->table('kiosk_profiles as kp')
            ->join('kiosk_products as kpt', 'kpt.id', '=', 'kp.kiosk_product_id')
            ->where('kp.profile_name', $profileName)
            ->orderBy('kpt.product_name')
            ->get([
                'kp.id', 'kp.kiosk_product_id', 'kp.manager_id', 'kp.profile_name',
                'kpt.product_name', 'kpt.product_code',
                'kp.provider_percentage', 'kp.cap_amount', 'kp.minimum_amount', 'kp.frequency_in_limit_days',
                'kp.agent_percentage', 'kp.suncash_percentage', 'kp.owner_percentage',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Legacy `add_profile()`, seeding one row per real `kiosk_products` row
     * instead of legacy's hardcoded label map (see class docblock).
     *
     * @throws ValidationException
     */
    public function createProfile(string $profileName, string $createdBy): void
    {
        if ($this->profileNameExists($profileName)) {
            throw ValidationException::withMessages(['profile_name' => ["A profile named \"{$profileName}\" already exists."]]);
        }

        $now = now();
        $products = KioskProduct::all(['id', 'product_code']);

        foreach ($products as $product) {
            $defaults = self::DEFAULT_PERCENTAGES[$product->product_code] ?? self::ZERO_DEFAULTS;

            KioskProfile::create([
                'profile_name' => $profileName,
                'kiosk_product_id' => $product->id,
                'manager_id' => -1,
                'provider_percentage' => $defaults['provider_percentage'],
                'cap_amount' => $defaults['cap_amount'],
                'minimum_amount' => $defaults['minimum_amount'],
                'frequency_in_limit_days' => $defaults['frequency_in_limit_days'],
                'agent_percentage' => '0.00',
                'suncash_percentage' => '0.00',
                'owner_percentage' => '0.00',
                'create_date' => $now,
                'create_by' => $createdBy,
                'update_date' => $now,
                'update_by' => $createdBy,
            ]);
        }
    }

    /**
     * Legacy `copy_existing_manager_info()` — clones every row of the source
     * profile under a new name. `manager_id` is hardcoded to `-1` matching
     * legacy (the modal field that would set a real manager is commented
     * out of the legacy UI, so it's never anything else in practice).
     *
     * @throws ValidationException
     */
    public function copyProfile(string $sourceProfileName, string $newProfileName, string $createdBy): void
    {
        if ($this->profileNameExists($newProfileName)) {
            throw ValidationException::withMessages(['profile_name' => ["A profile named \"{$newProfileName}\" already exists."]]);
        }

        $sourceRows = KioskProfile::where('profile_name', $sourceProfileName)->get();
        if ($sourceRows->isEmpty()) {
            throw ValidationException::withMessages(['profile_name' => ['The source profile was not found.']]);
        }

        $now = now();
        foreach ($sourceRows as $row) {
            KioskProfile::create([
                'profile_name' => $newProfileName,
                'kiosk_product_id' => $row->kiosk_product_id,
                'manager_id' => -1,
                'provider_percentage' => $row->provider_percentage,
                'cap_amount' => $row->cap_amount,
                'minimum_amount' => $row->minimum_amount,
                'frequency_in_limit_days' => $row->frequency_in_limit_days,
                'agent_percentage' => $row->agent_percentage,
                'suncash_percentage' => $row->suncash_percentage,
                'owner_percentage' => $row->owner_percentage,
                'create_date' => $now,
                'create_by' => $createdBy,
                'update_date' => $now,
                'update_by' => $createdBy,
            ]);
        }
    }

    /**
     * Legacy `edit_commission_profile()` — updates a single (profile,
     * product) row by its numeric id.
     *
     * @throws ValidationException
     */
    public function updateRow(int $id, array $data, string $updatedBy): void
    {
        $row = KioskProfile::find($id);
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['This commission row was not found.']]);
        }

        $row->update([
            'provider_percentage' => $data['provider_percentage'],
            'cap_amount' => $data['cap_amount'],
            'minimum_amount' => $data['minimum_amount'],
            'frequency_in_limit_days' => $data['frequency_in_limit_days'],
            'agent_percentage' => $data['agent_percentage'],
            'suncash_percentage' => $data['suncash_percentage'],
            'owner_percentage' => $data['owner_percentage'],
            'update_date' => now(),
            'update_by' => $updatedBy,
        ]);
    }

    /**
     * Legacy `delete_kiosk_profile()`, hardened with a reference-integrity
     * guard legacy never had (see class docblock).
     *
     * @throws ValidationException
     */
    public function deleteProfile(string $profileName): void
    {
        $terminals = DB::connection('mysuncash')->table('kiosk_terminal')
            ->where('profile_id', $profileName)
            ->pluck('name');

        if ($terminals->isNotEmpty()) {
            $list = $terminals->implode(', ');
            throw ValidationException::withMessages([
                'profile_name' => ["This profile is still assigned to {$terminals->count()} terminal(s) ({$list}) and can't be deleted. Reassign those terminals to a different profile first."],
            ]);
        }

        KioskProfile::where('profile_name', $profileName)->delete();
    }
}
