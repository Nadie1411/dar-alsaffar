<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Fakes the whole Overzaki surface the catalogue import touches, with fixtures
 * in the shape the real API returns.
 */
trait FakesOverzaki
{
    use LoadsFixtures;

    protected const API = 'production.overzaki.org/api';

    /**
     * A valid 1x1 PNG, so the image mirror's "do the bytes decode as an image" check passes.
     */
    protected function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /**
     * Anything passed in replaces the default for that URL; any request that
     * matches nothing fails the test instead of reaching the network.
     *
     * @param  array<string,mixed>  $overrides
     */
    protected function fakeOverzaki(array $overrides = []): void
    {
        Http::preventStrayRequests();

        $respond = fn (string $fixture) => Http::response($this->fixture('overzaki/'.$fixture));

        Http::fake($overrides + [
            self::API.'/products/get_products_customer*' => $respond('products-list'),
            self::API.'/products/v2/slug/ALBUSTAN' => $respond('product-albustan'),
            self::API.'/products/v2/slug/indian-jar' => $respond('product-jar'),
            self::API.'/products/v2/slug/choose-three-package' => $respond('product-package'),
            self::API.'/categories/all_slug' => $respond('categories'),
            self::API.'/delivery-pickup/domenstic-shipping/country/*' => $respond('locations'),
            self::API.'/service-addons/active' => $respond('addons'),
            self::API.'/carts/checker_customer/' => $respond('quote'),
            'cdn.example.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }
}
