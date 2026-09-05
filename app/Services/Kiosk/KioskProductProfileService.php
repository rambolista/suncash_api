<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskModule;
use App\Models\Mysuncash\KioskTerminal;
use App\Models\Mysuncash\SystemSetting;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Product Profiles" (legacy `fastpay::kiosk_product_profiles()` /
 * `display_default_modules()` / `display_products_profile()` /
 * `get_kiosk_not_included_services()` / `update_kiosk_services()` /
 * `update_kiosk_modules()` / `disable_kiosk_terminal()` /
 * `enable_kiosk_terminal()` / `kiosk_view_settings()`). Unrelated to
 * "Commission Profiles" (`kiosk_profiles` table) — this feature is about
 * which product **modules** (`kiosk_modules`, referenced by
 * `kiosk_terminal.access_modules` as a comma-separated id list) a terminal
 * exposes, plus a couple of global feature toggles in `system_settings`.
 *
 * One legacy quirk deliberately NOT replicated: legacy computes a
 * terminal's "currently enabled" modules by looking up each id in
 * `access_modules` and silently dropping any whose `kiosk_modules.status`
 * is no longer `ACTIVE` — so once a module is globally deactivated, any
 * terminal still referencing it shows neither as "enabled" nor as
 * "available to add", i.e. it becomes invisible in the legacy UI while
 * still lingering in the raw CSV column. This port instead resolves
 * `access_modules` against the FULL module catalog (active or not) and
 * exposes an explicit `included` flag per module, so a terminal's actual
 * enabled state is always visible and editable regardless of a module's
 * current global status.
 */
class KioskProductProfileService
{
    /**
     * @throws ValidationException
     */
    private function findTerminal(int $id): KioskTerminal
    {
        $terminal = KioskTerminal::find($id);
        if (! $terminal) {
            throw ValidationException::withMessages(['id' => ['This kiosk terminal was not found.']]);
        }

        return $terminal;
    }

    private function accessModuleIds(KioskTerminal $terminal): array
    {
        $raw = trim((string) $terminal->access_modules);
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_map('intval', explode(',', $raw))));
    }

    /** Legacy `getKioskTerminalListV2()` — every terminal, any branch, any status. */
    public function listTerminals(): array
    {
        return KioskTerminal::orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(fn (KioskTerminal $t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status])
            ->all();
    }

    /** Legacy `get_kiosk_modules()` with no id — the full catalog, active or not. */
    public function listModules(): array
    {
        return KioskModule::orderBy('description')
            ->get(['id', 'code', 'description', 'status'])
            ->all();
    }

    /**
     * A terminal's full module catalog, each flagged `included` if referenced
     * in that terminal's `access_modules`. Powers both the "Services" (add
     * missing) and "Product Profile" (view/replace all) modals.
     *
     * @throws ValidationException
     */
    public function terminalModules(int $terminalId): array
    {
        $terminal = $this->findTerminal($terminalId);
        $includedIds = $this->accessModuleIds($terminal);

        return KioskModule::orderBy('description')
            ->get(['id', 'code', 'description', 'status'])
            ->map(fn (KioskModule $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'description' => $m->description,
                'status' => $m->status,
                'included' => in_array($m->id, $includedIds, true),
            ])
            ->all();
    }

    /**
     * Legacy `update_kiosk_services('add_services', ...)` — appends module
     * ids to a terminal's `access_modules`, de-duplicated against what's
     * already there (legacy has no such de-dup guard server-side).
     *
     * @throws ValidationException
     */
    public function addServices(int $terminalId, array $moduleIds, string $updatedBy): void
    {
        $terminal = $this->findTerminal($terminalId);
        $ids = array_values(array_unique(array_map('intval', $moduleIds)));
        if (! $ids) {
            throw ValidationException::withMessages(['module_ids' => ['Please select at least one service to add.']]);
        }

        $merged = array_values(array_unique(array_merge($this->accessModuleIds($terminal), $ids)));

        $terminal->update([
            'access_modules' => implode(',', $merged),
            'update_by' => $updatedBy,
            'update_date' => now(),
        ]);
    }

    /**
     * Legacy `update_kiosk_services('edit_services', ...)` — wholesale
     * replaces a terminal's `access_modules` with exactly the given ids
     * (can also remove modules, unlike `addServices`).
     *
     * @throws ValidationException
     */
    public function replaceServices(int $terminalId, array $moduleIds, string $updatedBy): void
    {
        $terminal = $this->findTerminal($terminalId);
        $ids = array_values(array_unique(array_map('intval', $moduleIds)));

        $terminal->update([
            'access_modules' => implode(',', $ids),
            'update_by' => $updatedBy,
            'update_date' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function disableTerminal(int $id, string $updatedBy): void
    {
        $terminal = $this->findTerminal($id);
        $terminal->update(['status' => KioskTerminal::STATUS_DELETED, 'update_by' => $updatedBy, 'update_date' => now()]);
    }

    /**
     * @throws ValidationException
     */
    public function enableTerminal(int $id, string $updatedBy): void
    {
        $terminal = $this->findTerminal($id);
        $terminal->update(['status' => KioskTerminal::STATUS_ACTIVE, 'update_by' => $updatedBy, 'update_date' => now()]);
    }

    /**
     * Legacy `update_kiosk_modules()` — global toggle, affects every
     * terminal referencing this module.
     *
     * @throws ValidationException
     */
    public function updateModuleStatus(int $id, string $status): void
    {
        if (! in_array($status, [KioskModule::STATUS_ACTIVE, KioskModule::STATUS_INACTIVE], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid module status.']]);
        }

        $module = KioskModule::find($id);
        if (! $module) {
            throw ValidationException::withMessages(['id' => ['This module was not found.']]);
        }

        $module->update(['status' => $status]);
    }

    /** Legacy `get_features()` — `system_settings` rows of type `kiosk_setting`. */
    public function listFeatureSettings(): array
    {
        return SystemSetting::where('setting_type', 'kiosk_setting')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'set_value', 'is_active', 'is_enable'])
            ->all();
    }

    /**
     * Legacy `update_setting_status()` — toggles `system_settings.is_active`.
     *
     * @throws ValidationException
     */
    public function updateFeatureSetting(int $id, bool $active): void
    {
        $setting = SystemSetting::where('setting_type', 'kiosk_setting')->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['This feature setting was not found.']]);
        }

        $setting->update(['is_active' => $active ? 1 : 0]);
    }
}
