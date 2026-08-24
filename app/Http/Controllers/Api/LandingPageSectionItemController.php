<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ManagesLandingPages;
use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\LandingPageSection;
use App\Models\LandingPageSectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LandingPageSectionItemController extends Controller
{
    use ManagesLandingPages;

    public function index(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureSectionBelongsToPage($landingPage, $section);
        $items = $section->items->map(fn (LandingPageSectionItem $item) => $this->serializeItem($item));

        return response()->json(['items' => $items]);
    }

    public function store(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureSectionBelongsToPage($landingPage, $section);
        $this->decodeSettings($request);
        $this->ensureValidUpload($request, 'image', 'image');
        $data = $this->validated($request);
        $newPath = $this->storeUpload($request, $data);

        try {
            $item = DB::transaction(fn () => $section->items()->create($data));
        } catch (Throwable $error) {
            Storage::disk('public')->delete(array_filter([$newPath]));
            throw $error;
        }

        return response()->json(['item' => $this->serializeItem($item)], 201);
    }

    public function show(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section,
        LandingPageSectionItem $item
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureNested($landingPage, $section, $item);

        return response()->json(['item' => $this->serializeItem($item)]);
    }

    public function update(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section,
        LandingPageSectionItem $item
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureNested($landingPage, $section, $item);
        $this->decodeSettings($request);
        $this->ensureValidUpload($request, 'image', 'image');
        $data = $this->validated($request, true);
        $oldPath = $request->hasFile('image') || $request->boolean('clear_image') ? $item->image_path : null;
        $newPath = $this->storeUpload($request, $data);

        try {
            DB::transaction(fn () => $item->update($data));
        } catch (Throwable $error) {
            Storage::disk('public')->delete(array_filter([$newPath]));
            throw $error;
        }

        Storage::disk('public')->delete(array_filter([$oldPath]));

        return response()->json(['item' => $this->serializeItem($item->fresh())]);
    }

    public function destroy(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section,
        LandingPageSectionItem $item
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureNested($landingPage, $section, $item);
        $path = $item->image_path;
        $item->delete();
        Storage::disk('public')->delete(array_filter([$path]));

        return response()->json(['message' => 'Landing page section item deleted.']);
    }

    public function reorder(
        Request $request,
        LandingPage $landingPage,
        LandingPageSection $section
    ): JsonResponse {
        $this->authorizeSuperAdmin($request);
        $this->ensureSectionBelongsToPage($landingPage, $section);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $ids = collect($data['items'])->pluck('id');
        if ($section->items()->whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => ['Every item must belong to the landing page section.'],
            ]);
        }

        DB::transaction(function () use ($data, $section) {
            foreach ($data['items'] as $item) {
                $section->items()
                    ->whereKey($item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        $items = $section->items()->get()
            ->map(fn (LandingPageSectionItem $item) => $this->serializeItem($item));

        return response()->json(['items' => $items]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $optional = $updating ? 'sometimes' : 'nullable';

        $data = $request->validate([
            'title' => [$optional, 'nullable', 'string', 'max:255'],
            'subtitle' => [$optional, 'nullable', 'string', 'max:500'],
            'content' => [$optional, 'nullable', 'string', 'max:20000'],
            'link_label' => [$optional, 'nullable', 'string', 'max:255'],
            'link_url' => [$optional, 'nullable', 'string', 'max:2048'],
            'icon' => [$optional, 'nullable', 'string', 'max:255'],
            'settings' => [$optional, 'nullable', 'array'],
            'sort_order' => [$optional, 'integer', 'min:0'],
            'is_enabled' => [$optional, 'boolean'],
            'image' => $this->imageRules(),
            'clear_image' => [$optional, 'boolean'],
        ]);

        unset($data['image'], $data['clear_image']);

        return $data;
    }

    private function storeUpload(Request $request, array &$data): ?string
    {
        if (! $request->hasFile('image')) {
            if ($request->boolean('clear_image')) {
                $data['image_path'] = null;
            }
            return null;
        }

        $data['image_path'] = $request->file('image')->store('landing-pages/items', 'public');

        return $data['image_path'];
    }

    private function ensureSectionBelongsToPage(LandingPage $page, LandingPageSection $section): void
    {
        abort_unless($section->landing_page_id === $page->id, 404);
    }

    private function ensureNested(
        LandingPage $page,
        LandingPageSection $section,
        LandingPageSectionItem $item
    ): void {
        $this->ensureSectionBelongsToPage($page, $section);
        abort_unless($item->landing_page_section_id === $section->id, 404);
    }
}
