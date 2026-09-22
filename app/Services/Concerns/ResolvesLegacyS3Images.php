<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Every `resolveImage()` in this codebase (KYC Upgrade, Card Verification,
 * Bank Loads, Customer Documents) inherited the same base64-vs-URL check:
 * an `http(s)://` value is passed straight through as `<img src>`. Those
 * URLs point at the legacy TPP `get_image` endpoint
 * (`S3api::get_image_get()`), which requires a `KEY-TOKEN` header no
 * browser `<img>` tag can send — and that endpoint's own API key check now
 * fails outright ("Invalid API key"), so these images are broken either way.
 *
 * This resolves them the way `S3api::get_image_get()` did server-side
 * (`s3_path_type` for the `type` -> path prefix, then
 * `{path}{user_id}/{file}` as the S3 key — mirrors `save_image_post()`'s
 * upload-time path too) but skips TPP and its API key entirely: presigns
 * the S3 object directly via the "s3_documents" disk (same bucket/
 * credentials as "s3", no `root` prefix — legacy's own keys were never
 * rooted under `AWS_CUSTOMER_FILES_PATH`). The link expires 2 minutes
 * after each page load; reopening the page re-signs it.
 */
trait ResolvesLegacyS3Images
{
    private static array $s3PathTypeCache = [];

    private function resolveImage(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $this->resolveLegacyS3Url($value) ?? $value;
        }

        if (str_starts_with($value, 'data:image/')) {
            return $value;
        }

        return 'data:image/jpeg;base64,'.$value;
    }

    private function resolveLegacyS3Url(string $value): ?string
    {
        parse_str((string) parse_url($value, PHP_URL_QUERY), $params);

        $file = $params['file'] ?? null;
        $type = $params['type'] ?? null;
        if (! $file || ! $type) {
            return null;
        }

        $path = $this->s3PathForType($type);
        if (! $path) {
            return null;
        }

        $key = $path.(filled($params['user_id'] ?? null) ? $params['user_id'].'/' : '').$file;

        try {
            return Storage::disk('s3_documents')->temporaryUrl($key, now()->addMinutes(2));
        } catch (\Throwable) {
            return null;
        }
    }

    private function s3PathForType(string $type): ?string
    {
        if (! array_key_exists($type, self::$s3PathTypeCache)) {
            self::$s3PathTypeCache[$type] = DB::connection('mysuncash')->table('s3_path_type')
                ->where('type', $type)
                ->where('status', 0)
                ->value('path');
        }

        return self::$s3PathTypeCache[$type];
    }
}
