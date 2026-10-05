<?php

namespace Tests\Concerns;

use App\Services\Store\Uploads;
use Illuminate\Support\Facades\File;

/**
 * Gives each test its own uploads folder, so a picture a test uploads never
 * lands in the real public/uploads and is gone when the test ends.
 */
trait IsolatesUploads
{
    protected string $uploadRoot;

    protected function setUpIsolatesUploads(): void
    {
        $this->uploadRoot = sys_get_temp_dir().'/store-uploads-test-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->uploadRoot);
        $this->app->instance(Uploads::class, new Uploads($this->uploadRoot));
    }

    protected function tearDownIsolatesUploads(): void
    {
        File::deleteDirectory($this->uploadRoot);
    }

    /** Whether a stored path (uploads/catalog/x.jpg) exists in the test's folder. */
    protected function storedFileExists(?string $path): bool
    {
        return $path !== null && File::exists($this->uploadRoot.'/'.$path);
    }

    /** A file that really is there already, as if uploaded earlier. */
    protected function existingUpload(string $path = 'uploads/catalog/existing.jpg'): string
    {
        File::ensureDirectoryExists(dirname($this->uploadRoot.'/'.$path));
        File::put($this->uploadRoot.'/'.$path, 'image bytes');

        return $path;
    }
}
