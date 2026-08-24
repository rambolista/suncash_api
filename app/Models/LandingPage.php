<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'header_sign_in_label', 'header_sign_in_url', 'header_sign_up_label', 'header_sign_up_url', 'is_navigation_fixed', 'status', 'is_active'])]
class LandingPage extends Model
{
    public function sections(): HasMany
    {
        return $this->hasMany(LandingPageSection::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'is_navigation_fixed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
