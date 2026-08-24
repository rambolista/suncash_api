<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        $request->user()->forceFill([
            'theme_preference' => $data['theme'],
        ])->save();

        return response()->json([
            'theme_preference' => $request->user()->theme_preference,
        ]);
    }
}
