<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMenu extends Model
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
        'show_in_customer_page',
        'badge_text',
        'badge_class',
    ];

    protected $casts = [
        'is_title' => 'boolean',
        'is_active' => 'boolean',
        'is_disabled' => 'boolean',
        'is_special' => 'boolean',
        'show_in_customer_page' => 'boolean',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CustomerMenu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CustomerMenu::class, 'parent_id')->orderBy('sort_order');
    }
}
