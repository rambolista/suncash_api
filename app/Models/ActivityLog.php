<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * "User Activity" audit trail — logs create/update/delete/approve/reject/
 * etc. actions across the admin panel, plus which menu a user visited.
 * Modeled after iBIMSKP's `AuditLog`, called explicitly from controllers
 * (no automatic model-observer magic, so every write action's log entry
 * says exactly what a human would call it, not a raw Eloquent event name).
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'actor_id', 'action', 'module', 'menu_id', 'auditable_type', 'auditable_id',
        'description', 'changes', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    private static function ignoredFields(): array
    {
        return ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password', 'pin', 'two_factor_secret'];
    }

    private static function diff(array $before, array $after, array $fields = []): array
    {
        $ignored = self::ignoredFields();
        $candidates = $fields === [] ? array_keys(array_merge($before, $after)) : $fields;

        $changes = [];
        foreach (array_unique($candidates) as $field) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ($from === $to) {
                continue;
            }
            $changes[$field] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    private static function write(?User $actor, ?Request $request, string $action, string $module, ?EloquentModel $model, ?array $changes, ?string $description = null): void
    {
        self::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'module' => $module,
            'auditable_type' => $model?->getMorphClass() ?? $model?->getTable(),
            'auditable_id' => $model?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    public static function recordCreated(?User $actor, string $module, EloquentModel $model, array $fields = [], ?Request $request = null, ?string $description = null): void
    {
        $after = $model->getAttributes();
        $changes = [];
        foreach (($fields === [] ? array_keys($after) : $fields) as $field) {
            if (in_array($field, self::ignoredFields(), true)) {
                continue;
            }
            if (($after[$field] ?? null) === null) {
                continue;
            }
            $changes[$field] = ['from' => null, 'to' => $after[$field]];
        }

        self::write($actor, $request, 'created', $module, $model, $changes === [] ? null : $changes, $description);
    }

    public static function recordUpdated(?User $actor, string $module, EloquentModel $model, array $before, array $fields = [], ?Request $request = null, ?string $description = null): void
    {
        $changes = self::diff($before, $model->getAttributes(), $fields);
        if ($changes === [] && $description === null) {
            return;
        }

        self::write($actor, $request, 'updated', $module, $model, $changes === [] ? null : $changes, $description);
    }

    public static function recordDeleted(?User $actor, string $module, EloquentModel $model, array $before, array $fields = [], ?Request $request = null, ?string $description = null): void
    {
        $changes = [];
        foreach (($fields === [] ? array_keys($before) : $fields) as $field) {
            if (in_array($field, self::ignoredFields(), true)) {
                continue;
            }
            if (($before[$field] ?? null) === null) {
                continue;
            }
            $changes[$field] = ['from' => $before[$field], 'to' => null];
        }

        self::write($actor, $request, 'deleted', $module, $model, $changes === [] ? null : $changes, $description);
    }

    /**
     * A non-CRUD action worth logging (approve/reject/blacklist/export/
     * password reset/etc.) that doesn't map cleanly to a field-level diff.
     */
    public static function recordAction(?User $actor, string $module, string $action, string $description, ?EloquentModel $model = null, ?Request $request = null): void
    {
        self::write($actor, $request, $action, $module, $model, null, $description);
    }

    public static function recordMenuVisit(?User $actor, Menu $menu, ?Request $request = null): void
    {
        self::create([
            'actor_id' => $actor?->id,
            'action' => 'viewed',
            'module' => $menu->label,
            'menu_id' => $menu->id,
            'description' => 'Visited '.$menu->label,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
