<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LayoutSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LayoutSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'settings' => $this->settings($this->resolveScope($request))->settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->super_admin) {
            return response()->json(['message' => 'Only a super administrator can update layout settings.'], 403);
        }

        $scope = $this->resolveScope($request);
        $data = $request->validate([
            'skin' => ['sometimes', 'string', 'in:default,minimal,modern,material,saas,flat,galaxy,luxe,retro,neon,pixel'],
            'theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'orientation' => ['sometimes', 'string', 'in:vertical,horizontal'],
            'sidenavSize' => ['sometimes', 'string', 'in:default,compact,condensed,on-hover,on-hover-active,offcanvas'],
            'sidenavColor' => ['sometimes', 'string', 'in:light,dark,gray,gradient,image'],
            'sidenavStyle' => ['sometimes', 'string', 'in:default,no-icons-with-lines,with-lines'],
            'topbarColor' => ['sometimes', 'string', 'in:light,dark,gray,gradient'],
            'width' => ['sometimes', 'string', 'in:fluid,boxed'],
            'position' => ['sometimes', 'string', 'in:fixed,scrollable'],
            'dir' => ['sometimes', 'string', 'in:ltr,rtl'],
        ]);

        $settings = $this->settings($scope);
        $settings->update([
            'settings' => [...$settings->settings, ...$data],
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'settings' => $settings->fresh()->settings,
        ]);
    }

    public function updateTheme(Request $request): JsonResponse
    {
        if (! $request->user()->super_admin) {
            return response()->json(['message' => 'Only a super administrator can update the global theme.'], 403);
        }

        $data = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        [$settings, $user] = DB::transaction(function () use ($request, $data): array {
            $settings = $this->settings('admin');
            $settings->update([
                'settings' => [...$settings->settings, 'theme' => $data['theme']],
                'updated_by' => $request->user()->id,
            ]);

            $user = $request->user();
            $user->forceFill(['theme_preference' => $data['theme']])->save();

            return [$settings->fresh(), $user->fresh()];
        });

        return response()->json([
            'settings' => $settings->settings,
            'theme_preference' => $user->theme_preference,
        ]);
    }

    private function settings(string $scope): LayoutSetting
    {
        return LayoutSetting::firstOrCreate(
            ['scope' => $scope],
            [
                'scope' => $scope,
                'settings' => $scope === 'customer'
                    ? [
                        'skin' => 'default',
                        'theme' => 'light',
                        'orientation' => 'vertical',
                        'sidenavSize' => 'default',
                        'sidenavColor' => 'dark',
                        'sidenavStyle' => 'default',
                        'topbarColor' => 'light',
                        'width' => 'fluid',
                        'position' => 'fixed',
                        'dir' => 'ltr',
                    ]
                    : [
                        'skin' => 'default',
                        'theme' => 'light',
                        'orientation' => 'vertical',
                        'sidenavSize' => 'default',
                        'sidenavColor' => 'dark',
                        'sidenavStyle' => 'default',
                        'topbarColor' => 'light',
                        'width' => 'fluid',
                        'position' => 'fixed',
                        'dir' => 'ltr',
                    ],
            ]
        );
    }

    private function resolveScope(Request $request): string
    {
        $scope = strtolower((string) $request->query('scope', $request->input('scope', 'admin')));

        return in_array($scope, ['admin', 'customer'], true) ? $scope : 'admin';
    }
}
