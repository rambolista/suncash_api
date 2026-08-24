<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class ProjectSettingController extends Controller
{
    private const LOGO_FIELDS = [
        'favicon' => 'favicon_path',
        'logo_sm' => 'logo_sm_path',
        'logo_dark' => 'logo_dark_path',
        'logo_light' => 'logo_light_path',
        'auth_background' => 'auth_background_path',
        'sidenav_image' => 'sidenav_image_path',
    ];

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => $this->serialize($this->settings()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->super_admin, 403, 'Only a SuperAdmin may update project settings.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'year' => ['sometimes', 'required', 'integer', 'between:1900,9999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'authentication_type' => ['required', Rule::in(['basic', 'card', 'split'])],
            'customer_authentication_type' => ['sometimes', 'nullable', Rule::in(['basic', 'card', 'split'])],
            'landing_page_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('landing_pages', 'id')->where(fn ($query) => $query
                    ->where('status', 'published')
                    ->where('is_active', true)),
            ],
            'sidenav_gradient_start' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sidenav_gradient_end' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'topbar_gradient_start' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'topbar_gradient_end' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'favicon' => ['sometimes', 'file', 'mimes:ico,png,jpg,jpeg,webp,svg', 'max:2048'],
            'logo_sm' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
            'logo_dark' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
            'logo_light' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
            'auth_background' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
            'sidenav_image' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $settings = $this->settings();
        $oldPaths = [];
        $newPaths = [];

        if (! array_key_exists('customer_authentication_type', $data)) {
            $data['customer_authentication_type'] = $settings->customer_authentication_type ?? 'basic';
        }

        foreach (self::LOGO_FIELDS as $fileField => $pathField) {
            if (! $request->hasFile($fileField)) {
                continue;
            }

            $oldPaths[] = $settings->getAttribute($pathField);
            $path = $request->file($fileField)->store('project-branding', 'public');
            $newPaths[] = $path;
            $data[$pathField] = $path;
        }

        unset($data['favicon'], $data['logo_sm'], $data['logo_dark'], $data['logo_light'], $data['auth_background'], $data['sidenav_image']);

        try {
            DB::transaction(fn () => $settings->update($data));
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPaths);
            throw $error;
        }

        Storage::disk('public')->delete(array_filter($oldPaths));

        return response()->json([
            'settings' => $this->serialize($settings->fresh()),
        ]);
    }

    private function settings(): ProjectSetting
    {
        return ProjectSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'AdminStarterKit',
                'author' => 'BYX',
                'description' => 'AdminStarterKit is a reusable responsive Bootstrap 5 admin foundation.',
                'authentication_type' => 'basic',
                'customer_authentication_type' => 'basic',
            ]
        );
    }

    private function serialize(ProjectSetting $settings): array
    {
        return [
            'name' => $settings->name,
            'author' => $settings->author,
            'year' => $settings->year,
            'description' => $settings->description,
            'authentication_type' => $settings->authentication_type,
            'customer_authentication_type' => $settings->customer_authentication_type,
            'landing_page_id' => $settings->landing_page_id,
            'sidenav_gradient_start' => $settings->sidenav_gradient_start,
            'sidenav_gradient_end' => $settings->sidenav_gradient_end,
            'topbar_gradient_start' => $settings->topbar_gradient_start,
            'topbar_gradient_end' => $settings->topbar_gradient_end,
            'favicon_url' => $this->storageUrl($settings->favicon_path),
            'logo_sm_url' => $this->storageUrl($settings->logo_sm_path),
            'logo_dark_url' => $this->storageUrl($settings->logo_dark_path),
            'logo_light_url' => $this->storageUrl($settings->logo_light_path),
            'auth_background_url' => $this->storageUrl($settings->auth_background_path),
            'sidenav_image_url' => $this->storageUrl($settings->sidenav_image_path),
            'updated_at' => optional($settings->updated_at)->toISOString(),
        ];
    }

    private function storageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
