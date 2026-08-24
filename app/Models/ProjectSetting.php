<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'author',
    'year',
    'description',
    'authentication_type',
    'customer_authentication_type',
    'landing_page_id',
    'sidenav_gradient_start',
    'sidenav_gradient_end',
    'topbar_gradient_start',
    'topbar_gradient_end',
    'favicon_path',
    'logo_sm_path',
    'logo_dark_path',
    'logo_light_path',
    'auth_background_path',
    'sidenav_image_path',
])]
class ProjectSetting extends Model
{
    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }
}
