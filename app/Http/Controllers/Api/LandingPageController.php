<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ManagesLandingPages;
use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\ProjectSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class LandingPageController extends Controller
{
    use ManagesLandingPages;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $pages = LandingPage::query()
            ->withCount('sections')
            ->orderBy('name')
            ->get()
            ->map(function (LandingPage $page) {
                return [
                    ...$this->serializePage($page),
                    'sections_count' => $page->sections_count,
                    'is_selected' => ProjectSetting::query()
                        ->where('landing_page_id', $page->id)
                        ->exists(),
                ];
            });

        return response()->json(['landing_pages' => $pages]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $page = LandingPage::query()->create($this->validated($request));

        return response()->json(['landing_page' => $this->serializePage($page)], 201);
    }

    public function show(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $landingPage->load('sections.items');

        return response()->json(['landing_page' => $this->serializePage($landingPage, true)]);
    }

    public function update(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $this->validated($request, $landingPage);

        DB::transaction(function () use ($landingPage, $data) {
            $landingPage->update($data);
            if ($landingPage->status !== 'published' || ! $landingPage->is_active) {
                ProjectSetting::query()
                    ->where('landing_page_id', $landingPage->id)
                    ->update(['landing_page_id' => null]);
            }
        });

        return response()->json(['landing_page' => $this->serializePage($landingPage->fresh())]);
    }

    public function duplicate(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('landing_pages', 'slug'),
            ],
        ]);
        $landingPage->load('sections.items');
        $newPaths = [];

        try {
            $copy = DB::transaction(function () use ($landingPage, $data, &$newPaths) {
                $copy = LandingPage::query()->create([
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'description' => $landingPage->description,
                    'header_sign_in_label' => $landingPage->header_sign_in_label,
                    'header_sign_in_url' => $landingPage->header_sign_in_url,
                    'header_sign_up_label' => $landingPage->header_sign_up_label,
                    'header_sign_up_url' => $landingPage->header_sign_up_url,
                    'is_navigation_fixed' => $landingPage->is_navigation_fixed,
                    'status' => 'draft',
                    'is_active' => false,
                ]);

                foreach ($landingPage->sections as $section) {
                    $sectionCopy = $copy->sections()->create([
                        'type' => $section->type,
                        'title' => $section->title,
                        'subtitle' => $section->subtitle,
                        'content' => $section->content,
                        'primary_link_label' => $section->primary_link_label,
                        'primary_link_url' => $section->primary_link_url,
                        'secondary_link_label' => $section->secondary_link_label,
                        'secondary_link_url' => $section->secondary_link_url,
                        'image_path' => $this->copyMedia($section->image_path, $newPaths),
                        'background_image_path' => $this->copyMedia($section->background_image_path, $newPaths),
                        'settings' => $section->settings,
                        'sort_order' => $section->sort_order,
                        'is_enabled' => $section->is_enabled,
                    ]);

                    foreach ($section->items as $item) {
                        $sectionCopy->items()->create([
                            'title' => $item->title,
                            'subtitle' => $item->subtitle,
                            'content' => $item->content,
                            'link_label' => $item->link_label,
                            'link_url' => $item->link_url,
                            'image_path' => $this->copyMedia($item->image_path, $newPaths),
                            'icon' => $item->icon,
                            'settings' => $item->settings,
                            'sort_order' => $item->sort_order,
                            'is_enabled' => $item->is_enabled,
                        ]);
                    }
                }

                return $copy;
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPaths);
            throw $error;
        }

        $copy->load('sections.items');

        return response()->json(['landing_page' => $this->serializePage($copy, true)], 201);
    }

    public function destroy(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $landingPage->load('sections.items');
        $paths = $landingPage->sections->flatMap(fn ($section) => [
            $section->image_path,
            $section->background_image_path,
            ...$section->items->pluck('image_path'),
        ])->filter()->all();

        DB::transaction(function () use ($landingPage) {
            ProjectSetting::query()
                ->where('landing_page_id', $landingPage->id)
                ->update(['landing_page_id' => null]);
            $landingPage->delete();
        });

        Storage::disk('public')->delete($paths);

        return response()->json(['message' => 'Landing page deleted.']);
    }

    private function validated(Request $request, ?LandingPage $page = null): array
    {
        return $request->validate([
            'name' => [$page ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'slug' => [
                $page ? 'sometimes' : 'required',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('landing_pages', 'slug')->ignore($page?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'header_sign_in_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'header_sign_in_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'header_sign_up_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'header_sign_up_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_navigation_fixed' => ['sometimes', 'boolean'],
            'status' => [$page ? 'sometimes' : 'required', 'required', Rule::in(['draft', 'published'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function copyMedia(?string $sourcePath, array &$newPaths): ?string
    {
        if (! $sourcePath) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Landing page media file is missing: $sourcePath");
        }

        $directory = trim(str_replace('\\', '/', pathinfo($sourcePath, PATHINFO_DIRNAME)), '/');
        if ($directory === '.') {
            $directory = '';
        }
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $targetPath = ($directory ? "$directory/" : '')
            .Str::uuid()
            .($extension ? ".$extension" : '');

        if (! $disk->copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Unable to copy landing page media file: $sourcePath");
        }

        $newPaths[] = $targetPath;

        return $targetPath;
    }
}
