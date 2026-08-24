<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'description', 'key_responsibilities', 'icon'];

    /** Users that have this role */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** Menu permission records for this role */
    public function menuPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'role_menu_permissions', 'role_id', 'menu_id')
            ->withPivot(
                'can_view',
                'can_add',
                'can_edit',
                'can_delete',
                'can_approve',
                'can_execute',
                'can_cancel',
                'can_reverse',
                'can_export',
                'can_print',
            )
            ->withTimestamps();
    }

    public function menuTabPermissions(): BelongsToMany
    {
        return $this->belongsToMany(MenuTab::class, 'role_menu_tab_permissions')
            ->withPivot(...\App\Support\MenuPermissions::ACTIONS)
            ->withTimestamps();
    }
}
