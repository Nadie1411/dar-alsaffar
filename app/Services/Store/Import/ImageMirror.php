<?php

namespace App\Services\Store\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies catalogue images from Overzaki's CDN onto this server.
 *
 * Once the store stops using Overzaki, that CDN is no longer the owner's to
 * rely on, so every product, category and option image is kept locally. A file
 * is named after a hash of its source URL, which makes re-running the import
 * free: an image that is already here is never downloaded twice.
 *
 * When a download fails the original URL is returned instead, so the site
 * keeps working off the CDN and a later run can try again.
 */
class ImageMirror
{
    /**
     * What we are willing to store, and the extension each gets. SVG is left
     * out on purpose: it can carry script.
     */
    protected const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    protected const MAX_BYTES = 8 * 1024 * 1024;

    /** @var array{downloaded:int,reused:int,failed:int} */
    protected array $stats = ['downloaded' => 0, 'reused' => 0, 'failed' => 0];

    public function __construct(
        protected ?string $directory = null,
        protected ?string $prefix = null,
    ) {}

    /** @return array{downloaded:int,reused:int,failed:int} */
    public function stats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = ['downloaded' => 0, 'reused' => 0, 'failed' => 0];
    }

    /**
     * @return string|null a path under public/ (uploads/catalog/...) once the image is
     *                     stored here, the untouched source when it could not be fetched,
     *                     null when there was no image
     */
    public function mirror(?string $source, string $folder): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        // Already a local path — nothing to fetch.
        if (! str_starts_with($source, 'http')) {
            return $source;
        }

        $name = substr(sha1($source), 0, 24);

        foreach (self::TYPES as $extension) {
            if (is_file($this->directory().'/'.$folder.'/'.$name.'.'.$extension)) {
                $this->stats['reused']++;

                return $this->prefix().'/'.$folder.'/'.$name.'.'.$extension;
            }
        }

        $extension = $this->download($source, $folder, $name);

        if ($extension === null) {
            $this->stats['failed']++;

            return $source;
        }

        $this->stats['downloaded']++;

        return $this->prefix().'/'.$folder.'/'.$name.'.'.$extension;
    }

    /** @return string|null the extension the file was saved with */
    protected function download(string $source, string $folder, string $name): ?string
    {
        if (! str_starts_with($source, 'https://')) {
            Log::warning('Image import skipped a non-https source', ['url' => $source]);

            return null;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->retry(
                    [300, 1500],
                    0,
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get($source);
        } catch (Throwable $e) {
            Log::warning('Image download failed', ['url' => $source, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Image download was refused', ['url' => $source, 'status' => $response->status()]);

            return null;
        }

        $body = $response->body();

        // The Content-Type header is not to be trusted: Overzaki's CDN labels
        // every file application/octet-stream. What the bytes actually are
        // decides — and a file that does not decode as a raster image (an SVG,
        // say, or HTML behind an image URL) is refused whatever the header says.
        $info = @getimagesizefromstring($body);
        $type = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if (! isset(self::TYPES[$type]) || strlen($body) > self::MAX_BYTES) {
            Log::warning('Image download was not an acceptable image', ['url' => $source, 'type' => $type ?: 'unrecognised']);

            return null;
        }

        $extension = self::TYPES[$type];
        $directory = $this->directory().'/'.$folder;

        File::ensureDirectoryExists($directory);
        File::put($directory.'/'.$name.'.'.$extension, $body);

        return $extension;
    }

    protected function directory(): string
    {
        return rtrim($this->directory ?? public_path(config('store.catalog_images')), '/');
    }

    protected function prefix(): string
    {
        return trim($this->prefix ?? config('store.catalog_images'), '/');
    }
}
