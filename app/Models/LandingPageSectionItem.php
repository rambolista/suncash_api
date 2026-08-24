<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'landing_page_section_id',
    'title',
    'subtitle',
    'content',
    'link_label',
    'link_url',
    'image_path',
    'icon',
    'settings',
    'sort_order',
    'is_enabled',
])]
class LandingPageSectionItem extends Model
{
    public function section(): BelongsTo
    {
        return $this->belongsTo(LandingPageSection::class, 'landing_page_section_id');
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
