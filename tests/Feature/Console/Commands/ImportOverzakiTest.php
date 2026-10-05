<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Product;
use App\Services\Store\Import\ImageMirror;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOverzaki;
use Tests\TestCase;

class ImportOverzakiTest extends TestCase
{
    use FakesOverzaki, LazilyRefreshDatabase;

    private string $imageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageDirectory = sys_get_temp_dir().'/store-import-command-'.bin2hex(random_bytes(4));
        $this->app->instance(ImageMirror::class, new ImageMirror($this->imageDirectory, 'uploads/catalog'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->imageDirectory);

        parent::tearDown();
    }

    public function test_imports_the_store_and_prints_what_it_created(): void
    {
        $this->fakeOverzaki();

        $this->artisan('store:import-overzaki')
            ->expectsTable(['Imported', 'Created', 'Updated'], [
                ['categories', 3, 0],
                ['products', 3, 0],
                ['option groups', 2, 0],
                ['option values', 4, 0],
                ['delivery cities', 1, 0],
                ['delivery areas', 2, 0],
                ['service addons', 1, 0],
            ])
            ->expectsOutputToContain('5 downloaded')
            ->assertExitCode(0);

        $this->assertDatabaseCount('products', 3);
    }

    public function test_refuses_to_overwrite_admin_edits_once_the_store_runs_on_its_own_backend(): void
    {
        config(['store.backend' => 'local']);
        Http::preventStrayRequests();

        $this->artisan('store:import-overzaki')
            ->expectsOutputToContain('runs on its own backend')
            ->assertExitCode(1);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_force_runs_the_import_on_the_own_backend_anyway(): void
    {
        config(['store.backend' => 'local']);
        $this->fakeOverzaki();

        $this->artisan('store:import-overzaki', ['--force' => true, '--skip-images' => true, '--skip-fees' => true])
            ->assertExitCode(0);

        $this->assertSame(3, Product::query()->count());
    }

    public function test_reports_a_feed_with_no_products_instead_of_succeeding(): void
    {
        $this->fakeOverzaki([self::API.'/products/get_products_customer*' => Http::response(['data' => ['data' => [], 'count' => 0]])]);

        $this->artisan('store:import-overzaki')
            ->expectsOutputToContain('Overzaki returned no products, so nothing was changed.')
            ->assertExitCode(1);
    }
}
