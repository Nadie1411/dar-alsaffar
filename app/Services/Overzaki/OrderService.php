<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Orders;
use App\Support\Loc;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Order placement.
 *
 * Orders are created by Overzaki, which prices them itself and — for card,
 * KNET and Apple Pay — returns a hosted payment URL to redirect to. This class
 * builds the payload and interprets the response; it never computes a total
 * and never touches card data, which stays entirely on the gateway.
 */
class OrderService implements Orders
{
    public function __construct(
        protected OverzakiClient $client,
        protected CartService $cart,
    ) {}

    /** Kuwait governorates with their areas, for the address form. */
    public function deliveryLocations(): array
    {
        // Only the raw response is cached. Caching the localised shape meant
        // whichever language warmed the cache first was served to everyone —
        // Arabic shoppers were picking their governorate from an English list.
        $cities = Cache::remember('ovz:areas:v2', config('overzaki.cache.taxonomy'), function () {
            $response = $this->client->get(
                config('overzaki.endpoints.citiesWithAreas').config('overzaki.country_id')
            );

            return $response['cities'] ?? [];
        });

        return collect(is_array($cities) ? $cities : [])
            ->map(fn ($city) => [
                'id' => (string) ($city['cityId'] ?? ''),
                'name' => Loc::text($city['cityName'] ?? null),
                'areas' => collect($city['areas'] ?? [])
                    ->filter(fn ($area) => $area['isActive'] ?? true)
                    ->map(fn ($area) => [
                        'id' => (string) ($area['_id'] ?? ''),
                        'name' => Loc::text($area['name'] ?? null),
                    ])
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($city) => $city['id'] !== '' && $city['areas'] !== [])
            ->values()
            ->all();
    }

    /**
     * Optional extras the store offers at checkout — gift wrapping is the one
     * this shop wants. They are created in the Overzaki dashboard under
     * service add-ons and priced there, so nothing is hardcoded here.
     *
     * Returns an empty list until the shop creates one and switches on
     * "display in delivery", which is why the checkout section simply does not
     * appear yet rather than showing an empty box.
     *
     * @return array<int,array{id:string,name:string,description:string,price:float,image:?string}>
     */
    public function serviceAddons(): array
    {
        $rows = Cache::remember('ovz:addons', config('overzaki.cache.taxonomy'), function () {
            $response = $this->client->get('/service-addons/active');

            return $response['data'] ?? (is_array($response) ? $response : []);
        });

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($a) => is_array($a) && ($a['isActive'] ?? true) && ! ($a['isDelete'] ?? false))
            ->map(fn ($a) => [
                'id' => (string) ($a['_id'] ?? ''),
                'name' => Loc::text($a['name'] ?? null),
                'description' => Loc::plain($a['description'] ?? null, 120),
                'price' => (float) ($a['price'] ?? 0),
                'image' => $a['image'] ?? null,
            ])
            ->filter(fn ($a) => $a['id'] !== '' && $a['name'] !== '')
            ->values()
            ->all();
    }

    /** Whether the shop wants add-ons shown on a delivery order at all. */
    public function addonsEnabled(): bool
    {
        $settings = Cache::remember('ovz:order-settings', config('overzaki.cache.taxonomy'), function () {
            return $this->client->get('/order-settings/get');
        });

        return (bool) ($settings['serviceAddonsDisplayInDelivery'] ?? false)
            && $this->serviceAddons() !== [];
    }

    public function addonsRequired(): bool
    {
        $settings = Cache::remember('ovz:order-settings', config('overzaki.cache.taxonomy'), function () {
            return $this->client->get('/order-settings/get');
        });

        return (bool) ($settings['serviceAddonsRequiredInDelivery'] ?? false);
    }

    /** @return array<int,array<string,mixed>> */
    public function paymentMethods(): array
    {
        $locale = app()->getLocale();

        return collect(config('overzaki.payment.methods'))
            ->map(fn ($method) => [
                'id' => $method['id'],
                'type' => $method['type'],
                'label' => $method['label'][$locale] ?? $method['label']['en'],
            ])
            ->all();
    }

    /**
     * Place the order.
     *
     * @param  array  $input  validated checkout input
     * @return array{ok:bool,kind:string,paymentUrl:?string,orderId:?string,message:?string}
     */
    public function place(array $input): array
    {
        // Option choices travel with the line: a gift set priced by what the
        // shopper picked is a different order line from the same set empty.
        $items = array_values(array_map(fn ($item) => array_filter([
            'productId' => $item['productId'],
            'quantity' => $item['quantity'],
            'varientId' => $item['varientId'] ?: null,
            'options' => $item['options'] ?: null,
        ], fn ($value) => $value !== null), $this->cart->items()));

        if ($items === []) {
            return $this->failure(__('storefront.cart.empty'));
        }

        $isCod = ($input['payment'] ?? null) === 'cod';

        // Email is optional, and the key has to be absent rather than empty:
        // Overzaki answers an empty string with "email cannot be empty".
        $customer = array_filter([
            'fullName' => $input['fullName'],
            'email' => $input['email'] ?? null,
            // Overzaki validates E.164; the form collects 8 local digits.
            'phoneNumber' => $this->e164($input['phone']),
            'countryPrefixNumber' => config('brand.country.dial'),
        ], fn ($value) => $value !== null && $value !== '');

        // The order DTO rejects any property it does not know with a 422, so
        // this payload carries nothing beyond the keys it whitelists.
        $payload = array_filter([
            'customer' => $customer,
            'items' => $items,
            'address' => array_filter([
                'type' => 'home',
                'country' => config('overzaki.country_id'),
                'city' => $input['city'],
                'area' => $input['area'],
                'block' => $input['block'] ?? null,
                'street' => $input['street'] ?? null,
                'avenue' => $input['avenue'] ?? null,
                'building' => $input['building'] ?? null,
                'floor' => $input['floor'] ?? null,
                'apartment' => $input['apartment'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'isCashOnDelivery' => $isCod,
            'isStorePickup' => false,
            'note' => $input['notes'] ?? null,
            'voucher' => $input['voucher'] ?? null,
            'selectedServiceAddonIds' => $input['addons'] ?? null,
        ] + ($isCod ? [] : [
            'paymentIntegrationId' => config('overzaki.payment.integration_id'),
            'paymentSrcId' => $input['paymentMethod'] ?? null,
        ]), fn ($value) => $value !== null && $value !== '' && $value !== []);

        $endpoint = AuthService::check()
            ? config('overzaki.endpoints.orderForCustomer')
            : config('overzaki.endpoints.orderForGuest');

        $response = $this->client
            ->withToken(AuthService::token())
            ->postRaw($endpoint, $payload);

        if (! $response['ok']) {
            Log::warning('Order creation rejected', [
                'status' => $response['status'],
                'message' => $response['message'],
            ]);

            return $this->failure($response['message'] ?? __('storefront.checkout.failed'));
        }

        return $this->interpret($response['data'] ?? []);
    }

    /**
     * Read the outcome. A card order comes back with a payment URL to send the
     * shopper to; cash on delivery is confirmed immediately.
     */
    protected function interpret(mixed $data): array
    {
        $data = is_array($data) ? $data : [];

        $paymentUrl = $data['paymentUrl']
            ?? $data['url']
            ?? ($data['payment']['url'] ?? null);

        $orderId = $data['_id']
            ?? $data['orderId']
            ?? ($data['order']['_id'] ?? null);

        if (is_string($paymentUrl) && str_starts_with($paymentUrl, 'http')) {
            return [
                'ok' => true,
                'kind' => 'redirect',
                'paymentUrl' => $paymentUrl,
                'orderId' => $orderId,
                'message' => null,
            ];
        }

        if ($orderId) {
            return ['ok' => true, 'kind' => 'confirmed', 'paymentUrl' => null, 'orderId' => $orderId, 'message' => null];
        }

        // An accepted call that names neither an order nor a payment page is
        // not something to treat as success — the shopper must not be told
        // their order went through when we cannot show it to them.
        return $this->failure(__('storefront.checkout.failed'));
    }

    protected function failure(?string $message): array
    {
        return ['ok' => false, 'kind' => 'failed', 'paymentUrl' => null, 'orderId' => null, 'message' => $message];
    }

    /** Local 8-digit Kuwaiti numbers become +965XXXXXXXX. */
    public function e164(string $phone): string
    {
        return Phone::e164($phone);
    }

    /** @return array<int,array<string,mixed>> */
    public function myOrders(): array
    {
        if (! AuthService::check()) {
            return [];
        }

        $response = $this->client->withToken(AuthService::token())
            ->get(config('overzaki.endpoints.myOrders'), ['pageSize' => 50, 'pageNumber' => 1]);

        return $response['data'] ?? [];
    }

    public function findOrder(string $id): ?array
    {
        if (! AuthService::check()) {
            return null;
        }

        $response = $this->client->withToken(AuthService::token())->get('/orders/'.$id);

        return $response === [] ? null : $response;
    }
}
