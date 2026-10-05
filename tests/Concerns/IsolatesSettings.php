<?php

namespace Tests\Concerns;

use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Gives each test its own settings file, so a test that saves a setting can
 * never touch the real one on the machine running the suite.
 */
trait IsolatesSettings
{
    protected string $settingsFile;

    protected function setUpIsolatesSettings(): void
    {
        $this->settingsFile = sys_get_temp_dir().'/store-settings-test-'.bin2hex(random_bytes(6)).'.json';
        $file = $this->settingsFile;

        $this->app->instance(Settings::class, new class($file) extends Settings
        {
            public function __construct(protected string $file) {}

            protected function path(): string
            {
                return $this->file;
            }
        });

        Cache::forget('settings.store');
    }

    protected function tearDownIsolatesSettings(): void
    {
        File::delete($this->settingsFile);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    protected function setting(array $values): void
    {
        $this->app->make(Settings::class)->save($values);
    }
}
