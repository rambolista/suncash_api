<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\LandingPage;
use App\Models\LandingPageSection;
use App\Models\LandingPageSectionItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

trait ManagesLandingPages
{
    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->super_admin, 403, 'Only a SuperAdmin may manage landing pages.');
    }

    private function decodeSettings(Request $request): void
    {
        if (! is_string($request->input('settings'))) {
            return;
        }

        $settings = json_decode($request->input('settings'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $request->merge(['settings' => $settings]);
        }
    }

    private function imageRules(int $maxKilobytes = 10240): array
    {
        return ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp,svg', "max:$maxKilobytes"];
    }

    private function ensureValidUpload(Request $request, string $field, string $label): void
    {
        $file = $request->file($field);
        if (! $file instanceof UploadedFile || $file->isValid()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [$this->uploadErrorMessage($file, $label)],
        ]);
    }

    private function uploadErrorMessage(UploadedFile $file, string $label): string
    {
        $code = $file->getError();
        $codeName = match ($code) {
            UPLOAD_ERR_INI_SIZE => 'UPLOAD_ERR_INI_SIZE',
            UPLOAD_ERR_FORM_SIZE => 'UPLOAD_ERR_FORM_SIZE',
            UPLOAD_ERR_PARTIAL => 'UPLOAD_ERR_PARTIAL',
            UPLOAD_ERR_NO_FILE => 'UPLOAD_ERR_NO_FILE',
            UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
            UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
            UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
            default => 'UPLOAD_ERR_UNKNOWN',
        };

        $reason = method_exists($file, 'getErrorMessage')
            ? $file->getErrorMessage()
            : 'The file could not be uploaded.';

        return sprintf(
            'The %s upload failed (PHP %s, code %d): %s',
            $label,
            $codeName,
            $code,
            $reason,
        );
    }

    private function storageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function serializePage(LandingPage $page, bool $withSections = false): array
    {
        $data = [
            'id' => $page->id,
            'name' => $page->name,
            'slug' => $page->slug,
            'description' => $page->description,
            'header_sign_in_label' => $page->header_sign_in_label,
            'header_sign_in_url' => $page->header_sign_in_url,
            'header_sign_up_label' => $page->header_sign_up_label,
            'header_sign_up_url' => $page->header_sign_up_url,
            'is_navigation_fixed' => $page->is_navigation_fixed,
            'status' => $page->status,
            'is_active' => $page->is_active,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];

        if ($withSections) {
            $data['sections'] = $page->sections
                ->map(fn (LandingPageSection $section) => $this->serializeSection($section, true))
                ->values();
        }

        return $data;
    }

    private function serializeSection(LandingPageSection $section, bool $withItems = false): array
    {
        $data = [
            'id' => $section->id,
            'landing_page_id' => $section->landing_page_id,
            'type' => $section->type,
            'title' => $section->title,
            'subtitle' => $section->subtitle,
            'content' => $section->content,
            'primary_link_label' => $section->primary_link_label,
            'primary_link_url' => $section->primary_link_url,
            'secondary_link_label' => $section->secondary_link_label,
            'secondary_link_url' => $section->secondary_link_url,
            'image_path' => $section->image_path,
            'image_url' => $this->storageUrl($section->image_path),
            'background_image_path' => $section->background_image_path,
            'background_image_url' => $this->storageUrl($section->background_image_path),
            'settings' => $section->settings,
            'sort_order' => $section->sort_order,
            'is_enabled' => $section->is_enabled,
            'created_at' => $section->created_at?->toISOString(),
            'updated_at' => $section->updated_at?->toISOString(),
        ];

        if ($withItems) {
            $data['items'] = $section->items
                ->map(fn (LandingPageSectionItem $item) => $this->serializeItem($item))
                ->values();
        }

        return $data;
    }

    private function serializeItem(LandingPageSectionItem $item): array
    {
        return [
            'id' => $item->id,
            'landing_page_section_id' => $item->landing_page_section_id,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'content' => $item->content,
            'link_label' => $item->link_label,
            'link_url' => $item->link_url,
            'image_path' => $item->image_path,
            'image_url' => $this->storageUrl($item->image_path),
            'icon' => $item->icon,
            'settings' => $item->settings,
            'sort_order' => $item->sort_order,
            'is_enabled' => $item->is_enabled,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}
