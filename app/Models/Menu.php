<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\MenuPermissions;

class Menu extends Model
{
    protected $fillable = [
        'label',
        'slug',
        'url',
        'icon',
        'parent_id',
        'sort_order',
        'is_title',
        'is_active',
        'is_disabled',
        'is_special',
        'badge_text',
        'badge_class',
        'tab_layout',
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
        'is_title'   => 'boolean',
        'is_active'  => 'boolean',
        'is_disabled' => 'boolean',
        'is_special' => 'boolean',
        'sort_order' => 'integer',
        'parent_id'  => 'integer',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(MenuTab::class)->orderBy('sort_order')->orderBy('id');
    }

    public function supportsAction(string $action): bool
    {
        $capability = MenuPermissions::CAPABILITIES[$action] ?? null;

        return $capability !== null && (bool) $this->{$capability};
    }
}
