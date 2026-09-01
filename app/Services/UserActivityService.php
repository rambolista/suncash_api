<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

/**
 * "Administration > User Activity" — lists every ActivityLog entry
 * (create/update/delete/other actions + menu visits) with filtering.
 * Unlike iBIMSKP, every action already lands in one table, so this is a
 * straight paginated query rather than a multi-source merge.
 */
class UserActivityService
{
    public const ACTION_LABELS = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'viewed' => 'Viewed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'blacklisted' => 'Blacklisted',
        'exported' => 'Exported',
        'processed' => 'Processed',
        'logged_in' => 'Logged In',
        'logged_out' => 'Logged Out',
    ];

    private function present(ActivityLog $log): array
    {
        $changedFields = is_array($log->changes) ? array_keys($log->changes) : [];

        return [
            'id' => $log->id,
            'actor_id' => $log->actor_id,
            'actor_name' => $log->actor?->name ?? 'System',
            'action' => $log->action,
            'action_label' => self::ACTION_LABELS[$log->action] ?? ucfirst(str_replace('_', ' ', $log->action)),
            'module' => $log->module,
            'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
            'auditable_id' => $log->auditable_id,
            'description' => $log->description ?: ($changedFields ? 'Changed: '.implode(', ', $changedFields) : self::ACTION_LABELS[$log->action] ?? $log->action),
            'changes' => $log->changes,
            'ip_address' => $log->ip_address,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }

    public function list(array $filters): array
    {
        $query = ActivityLog::query()->with('actor:id,name');

        if (! empty($filters['user_id'])) {
            $query->where('actor_id', $filters['user_id']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $rows = $query->latest('id')->limit(1000)->get();

        if (! empty($filters['search'])) {
            $needle = Str::lower($filters['search']);
            $rows = $rows->filter(function (ActivityLog $log) use ($needle) {
                return Str::contains(Str::lower($log->actor?->name ?? ''), $needle)
                    || Str::contains(Str::lower($log->description ?? ''), $needle)
                    || Str::contains(Str::lower($log->module ?? ''), $needle);
            });
        }

        return $rows->map(fn (ActivityLog $log) => $this->present($log))->values()->all();
    }

    public function modules(): array
    {
        return ActivityLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module')->all();
    }
}
