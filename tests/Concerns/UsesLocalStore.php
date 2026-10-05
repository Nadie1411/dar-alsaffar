<?php

namespace Tests\Concerns;

use App\Providers\StoreServiceProvider;

/**
 * Runs a test with the storefront on this application's own database instead
 * of Overzaki, as it will run after the switch-over.
 */
trait UsesLocalStore
{
    protected function setUpUsesLocalStore(): void
    {
        config(['store.backend' => 'local']);

        $this->app->getProvider(StoreServiceProvider::class)->useBackend('local');
    }
}
