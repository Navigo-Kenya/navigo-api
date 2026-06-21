<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * StorageService — single entry point for all file storage in the application.
 *
 * Centralises three responsibilities that were previously scattered across every
 * controller that touched a file:
 *
 *   1. Uploading to Cloudflare R2 and returning a ready-to-persist public URL.
 *   2. Safely deleting a stored URL regardless of which disk it came from.
 *   3. Converting between R2 paths and public URLs.
 *
 * WHY NOT USE THE BASE CONTROLLER HELPERS?
 * `r2Url()` and `r2RelativePath()` used to live on the base Controller, making
 * them unavailable to services, jobs, and artisan commands. This service is
 * injectable anywhere the Laravel container resolves dependencies.
 *
 * LEGACY SUPPORT
 * Before the R2 migration, files lived on two local disks:
 *   - "public" disk  → served as /storage/...
 *   - raw public/uploads directory → served as /uploads/...
 * The delete() method handles all three cases transparently, so callers never
 * need to know where a historic file was stored.
 */
class StorageService
{
    /**
     * The R2 public base URL (e.g. https://files.navigo.co.ke).
     * Read from config so it changes automatically per environment.
     */
    private string $r2BaseUrl;

    public function __construct()
    {
        $this->r2BaseUrl = rtrim(
            config('filesystems.disks.r2.url', 'https://files.navigo.co.ke'),
            '/',
        );
    }

    // ── Upload ────────────────────────────────────────────────────────────────────

    /**
     * Store an uploaded file on Cloudflare R2 and return its public HTTPS URL.
     *
     * The returned URL is what you persist in the database. It is ready for use
     * without any further transformation.
     *
     * @param  UploadedFile  $file    The incoming file from the HTTP request.
     * @param  string        $folder  R2 path prefix, e.g. "avatars/42" or "agency-logos/ke-001".
     *                                Laravel appends a unique filename automatically.
     * @return string                 Fully-qualified public URL, e.g.
     *                                "https://files.navigo.co.ke/avatars/42/8f3a...jpg"
     *
     * @throws RuntimeException  If the underlying R2 write fails.
     */
    public function upload(UploadedFile $file, string $folder): string
    {
        $path = $file->store($folder, 'r2');

        if ($path === false) {
            throw new RuntimeException(
                "StorageService: failed to upload file to R2 folder \"{$folder}\"."
            );
        }

        return $this->url($path);
    }

    // ── Delete ────────────────────────────────────────────────────────────────────

    /**
     * Safely delete any stored file URL, regardless of which disk it came from.
     *
     * Three disk types are handled transparently:
     *
     *   • R2 file (files.navigo.co.ke or r2.cloudflarestorage.com)
     *       → Storage::disk('r2')->delete()
     *
     *   • Legacy "public" disk (/storage/... URLs, pre-R2 migration)
     *       → Storage::disk('public')->delete()
     *
     *   • Legacy raw public/uploads directory (/uploads/... URLs)
     *       → unlink() from the public directory
     *
     *   • External URL (Google, Apple OAuth avatars, etc.)
     *       → skipped silently — we don't own those files
     *
     * Passing null or an empty string is intentionally a no-op, so callers can
     * skip the null guard:
     *
     *   $this->storage->delete($user->getRawOriginal('avatar'));  // safe even if null
     *
     * @param  string|null  $url  The URL or path stored in the database column.
     */
    public function delete(?string $url): void
    {
        if (empty($url)) {
            return;
        }

        // ── R2 (primary disk) ─────────────────────────────────────────────────────
        // Match both the vanity domain and the raw Cloudflare endpoint so the check
        // works even if the config URL is ever swapped between the two.
        if (
            str_contains($url, 'files.navigo.co.ke') ||
            str_contains($url, 'r2.cloudflarestorage.com')
        ) {
            Storage::disk('r2')->delete($this->relativePath($url));
            return;
        }

        // ── Legacy public disk (/storage/... URLs) ───────────────────────────────
        // Files served through the Laravel storage symlink before the R2 migration.
        if (preg_match('#/storage/(.+)$#', $url, $m)) {
            Storage::disk('public')->delete($m[1]);
            return;
        }

        // ── Legacy raw uploads directory (/uploads/... URLs) ─────────────────────
        // Even older files written directly to public/uploads/ via the filesystem.
        if (preg_match('#/uploads/(.+)$#', $url, $m)) {
            @unlink(public_path('uploads/' . $m[1]));
            return;
        }

        // ── External / OAuth URL ─────────────────────────────────────────────────
        // e.g. https://lh3.googleusercontent.com/... — not hosted by us, nothing to
        // delete. Log at debug level so it is visible when tracing file lifecycles.
        Log::debug('StorageService::delete — skipping external URL.', ['url' => $url]);
    }

    // ── URL helpers ───────────────────────────────────────────────────────────────

    /**
     * Convert a bucket-relative R2 path into a fully-qualified public URL.
     *
     * Use this when you already have a path (e.g. from a bulk import) and need
     * the URL to persist in the database. For regular HTTP uploads, prefer
     * upload() which calls this internally.
     *
     * @param  string  $path  Bucket-relative path, e.g. "avatars/42/photo.jpg"
     * @return string         Public URL,            e.g. "https://files.navigo.co.ke/avatars/42/photo.jpg"
     */
    public function url(string $path): string
    {
        return $this->r2BaseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Strip the R2 base URL to get the bucket-relative path.
     *
     * Needed when passing a stored URL to low-level Storage::disk('r2') calls,
     * for example when renaming or copying objects within the bucket. For
     * straightforward deletions, prefer delete() which calls this internally.
     *
     * @param  string  $url  Full public URL, e.g. "https://files.navigo.co.ke/avatars/42/photo.jpg"
     * @return string        Relative path,   e.g. "avatars/42/photo.jpg"
     */
    public function relativePath(string $url): string
    {
        return ltrim(str_replace($this->r2BaseUrl, '', $url), '/');
    }
}
