<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ManagesLandingPages;
use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\LandingPageSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class LandingPageSectionController extends Controller
{
    use ManagesLandingPages;

    public function index(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $sections = $landingPage->sections()->with('items')->get()
            ->map(fn (LandingPageSection $section) => $this->serializeSection($section, true));

        return response()->json(['sections' => $sections]);
    }

    public function store(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->decodeSettings($request);
        $this->ensureValidUpload($request, 'image', 'image');
        $this->ensureValidUpload($request, 'background_image', 'background image');
        $data = $this->validated($request);
        $newPaths = $this->storeUploads($request, $data);

        try {
            $section = DB::transaction(fn () => $landingPage->sections()->create($data));
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPaths);
            throw $error;
        }

        return response()->json(['section' => $this->serializeSection($section)], 201);
    }

    public function show(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureBelongsToPage($landingPage, $section);
        $section->load('items');

        return response()->json(['section' => $this->serializeSection($section, true)]);
    }

    public function update(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureBelongsToPage($landingPage, $section);
        $this->decodeSettings($request);
        $this->ensureValidUpload($request, 'image', 'image');
        $this->ensureValidUpload($request, 'background_image', 'background image');
        $data = $this->validated($request, true);
        $oldPaths = [];

        foreach (['image' => 'image_path', 'background_image' => 'background_image_path'] as $file => $column) {
            if ($request->hasFile($file) || $request->boolean($file === 'image' ? 'clear_image' : 'clear_background_image')) {
                $oldPaths[] = $section->getAttribute($column);
            }
        }

        $newPaths = $this->storeUploads($request, $data);

        try {
            DB::transaction(fn () => $section->update($data));
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPaths);
            throw $error;
        }

        Storage::disk('public')->delete(array_filter($oldPaths));

        return response()->json(['section' => $this->serializeSection($section->fresh())]);
    }

    public function destroy(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureBelongsToPage($landingPage, $section);
        $section->load('items');
        $paths = [
            $section->image_path,
            $section->background_image_path,
            ...$section->items->pluck('image_path'),
        ];

        $section->delete();
        Storage::disk('public')->delete(array_filter($paths));

        return response()->json(['message' => 'Landing page section deleted.']);
    }

    public function reorder(Request $request, LandingPage $landingPage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['required', 'integer', 'distinct'],
            'sections.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $ids = collect($data['sections'])->pluck('id');
        if ($landingPage->sections()->whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'sections' => ['Every section must belong to the landing page.'],
            ]);
        }

        DB::transaction(function () use ($data, $landingPage) {
            foreach ($data['sections'] as $section) {
                $landingPage->sections()
                    ->whereKey($section['id'])
                    ->update(['sort_order' => $section['sort_order']]);
            }
        });

        $sections = $landingPage->sections()->with('items')->get()
            ->map(fn (LandingPageSection $section) => $this->serializeSection($section, true));

        return response()->json(['sections' => $sections]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $optional = $updating ? 'sometimes' : 'nullable';

        $data = $request->validate([
            'type' => [$updating ? 'sometimes' : 'required', 'required', Rule::in([
                'hero', 'carousel', 'features', 'about', 'gallery', 'pricing', 'testimonials',
                'blog', 'faq', 'contact', 'cta', 'footer',
            ])],
            'title' => [$optional, 'nullable', 'string', 'max:255'],
            'subtitle' => [$optional, 'nullable', 'string', 'max:500'],
            'content' => [$optional, 'nullable', 'string', 'max:20000'],
            'primary_link_label' => [$optional, 'nullable', 'string', 'max:255'],
            'primary_link_url' => [$optional, 'nullable', 'string', 'max:2048'],
            'secondary_link_label' => [$optional, 'nullable', 'string', 'max:255'],
            'secondary_link_url' => [$optional, 'nullable', 'string', 'max:2048'],
            'settings' => [$optional, 'nullable', 'array'],
            'sort_order' => [$optional, 'integer', 'min:0'],
            'is_enabled' => [$optional, 'boolean'],
            'image' => $this->imageRules(),
            'background_image' => $this->imageRules(),
            'clear_image' => [$optional, 'boolean'],
            'clear_background_image' => [$optional, 'boolean'],
        ]);

        unset($data['image'], $data['background_image'], $data['clear_image'], $data['clear_background_image']);

        return $data;
    }

    private function storeUploads(Request $request, array &$data): array
    {
        $paths = [];
        foreach (['image' => 'image_path', 'background_image' => 'background_image_path'] as $file => $column) {
            if ($request->hasFile($file)) {
                $data[$column] = $request->file($file)->store('landing-pages/sections', 'public');
                $paths[] = $data[$column];
            } elseif ($request->boolean($file === 'image' ? 'clear_image' : 'clear_background_image')) {
                $data[$column] = null;
            }
        }

        return $paths;
    }

    private function ensureBelongsToPage(LandingPage $page, LandingPageSection $section): void
    {
        abort_unless($section->landing_page_id === $page->id, 404);
    }
}
