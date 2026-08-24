<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ManagesLandingPages;
use App\Http\Controllers\Controller;
use App\Models\ProjectSetting;
use Illuminate\Http\JsonResponse;

class PublicLandingPageController extends Controller
{
    use ManagesLandingPages;

    public function show(): JsonResponse
    {
        $page = ProjectSetting::query()
            ->whereKey(1)
            ->first()
            ?->landingPage()
            ->where('status', 'published')
            ->where('is_active', true)
            ->with([
                'sections' => fn ($query) => $query
                    ->where('is_enabled', true)
                    ->with(['items' => fn ($items) => $items->where('is_enabled', true)]),
            ])
            ->first();

        return response()->json([
            'landing_page' => $page ? $this->serializePage($page, true) : null,
        ]);
    }
}
