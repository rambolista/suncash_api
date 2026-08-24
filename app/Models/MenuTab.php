<?php

namespace App\Models;

use App\Support\MenuPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MenuTab extends Model
{
    protected $fillable = [
        'menu_id',
        'key',
        'label',
        'icon',
        'sort_order',
        'is_active',
        'supports_view',
        'supports_add',
        'supports_edit',
        'supports_delete',
        'supports_approve',
        'supports_execute',
        'supports_cancel',
        'supports_reverse',
        'supports_export',
        'supports_print',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'supports_view' => 'boolean',
        'supports_add' => 'boolean',
        'supports_edit' => 'boolean',
        'supports_delete' => 'boolean',
        'supports_approve' => 'boolean',
        'supports_execute' => 'boolean',
        'supports_cancel' => 'boolean',
        'supports_reverse' => 'boolean',
        'supports_export' => 'boolean',
        'supports_print' => 'boolean',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_menu_tab_permissions')
            ->withPivot(...MenuPermissions::ACTIONS)
            ->withTimestamps();
    }

    public function supportsAction(string $action): bool
    {
        $capability = MenuPermissions::CAPABILITIES[$action] ?? null;

        return $capability !== null && (bool) $this->{$capability};
    }
}
