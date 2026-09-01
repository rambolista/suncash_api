<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        $user = $request->user();
        $before = $user->getAttributes();

        $user->forceFill([
            'theme_preference' => $data['theme'],
        ])->save();

        ActivityLog::recordUpdated($request->user(), 'Theme Preference', $user, $before, ['theme_preference'], $request);

        return response()->json([
            'theme_preference' => $request->user()->theme_preference,
        ]);
    }
}
