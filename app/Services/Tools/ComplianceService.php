<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BlockedListEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "Tools > Compliance" (legacy `tools/compliance` + `blocked_list` table) —
 * a sanctions/PEP/watch-list the Customer Management "Is PEP" flag also
 * reads from (see CustomerManagementService::isPep/setPep, type=PEP).
 *
 * Deviates from legacy on import: legacy's "Import from Excel" first WIPES
 * every row in `blocked_list` (`delete_blocked_list()` has no filter — it
 * deletes the whole table) before inserting the uploaded rows. That would
 * silently destroy PEP flags set through Customer Management along with
 * every other list type, on every import — not replicated. Import here only
 * adds rows; existing entries (including PEP flags) are left alone.
 */
class ComplianceService
{
    private const IMPORT_COLUMNS = ['Name' => 'name', 'Other Info' => 'other_info', 'Type' => 'type'];

    private const VALID_TYPES = ['PEP', 'WATCH', 'COUNTRY'];

    public function list(): array
    {
        return BlockedListEntry::where('status', 'ACTIVE')
            ->orderBy('created_at')
            ->get(['id', 'name', 'other_info', 'status', 'remarks', 'type', 'type_desc', 'created_at'])
            ->all();
    }

    public function update(int $id, string $name, string $otherInfo, User $actor): void
    {
        $entry = BlockedListEntry::findOrFail($id);
        $before = ['name' => $entry->name, 'other_info' => $entry->other_info];

        $entry->update(['name' => $name, 'other_info' => $otherInfo, 'updated_by' => $actor->name ?? $actor->email]);

        ActivityLog::recordAction(
            $actor,
            'Tools - Compliance',
            'updated',
            "Updated blocked list entry #{$id} (name: {$before['name']} -> {$name}, other info: {$before['other_info']} -> {$otherInfo})",
        );
    }

    /** Legacy's "Delete" is a soft delete — flips status to DELETED, doesn't remove the row. */
    public function delete(int $id, User $actor): void
    {
        $entry = BlockedListEntry::findOrFail($id);
        $entry->update(['status' => 'DELETED', 'updated_by' => $actor->name ?? $actor->email]);

        ActivityLog::recordAction($actor, 'Tools - Compliance', 'deleted', "Removed blocked list entry #{$id} ({$entry->name})");
    }

    /**
     * @return array{imported: int, skipped: int}
     *
     * @throws ValidationException
     */
    public function import(UploadedFile $file, User $actor): array
    {
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => ['Unable to read this file: '.$e->getMessage()]]);
        }

        $rows = $sheet->toArray(null, true, true, false);
        $header = array_shift($rows) ?? [];
        $columnIndex = array_flip(array_map('trim', $header));

        $imported = 0;
        $skipped = 0;
        $actorName = $actor->name ?? $actor->email;

        foreach ($rows as $row) {
            $data = [];
            foreach (self::IMPORT_COLUMNS as $excelHeader => $field) {
                $data[$field] = isset($columnIndex[$excelHeader]) ? trim((string) ($row[$columnIndex[$excelHeader]] ?? '')) : '';
            }

            $data['type'] = strtoupper($data['type']);
            if ($data['name'] === '' || ! in_array($data['type'], self::VALID_TYPES, true)) {
                $skipped++;

                continue;
            }

            BlockedListEntry::create([
                'name' => $data['name'],
                'other_info' => $data['other_info'],
                'type' => $data['type'],
                'type_desc' => $data['type'].' List',
                'status' => 'ACTIVE',
                'created_by' => $actorName,
            ]);
            $imported++;
        }

        ActivityLog::recordAction($actor, 'Tools - Compliance', 'imported', "Imported {$imported} blocked list entries (skipped {$skipped})");

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
