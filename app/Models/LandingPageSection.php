<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'landing_page_id',
    'type',
    'title',
    'subtitle',
    'content',
    'primary_link_label',
    'primary_link_url',
    'secondary_link_label',
    'secondary_link_url',
    'image_path',
    'background_image_path',
    'settings',
    'sort_order',
    'is_enabled',
])]
class LandingPageSection extends Model
{
    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LandingPageSectionItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
