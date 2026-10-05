<?php

namespace App\Contracts\Store;

/**
 * Checkout and order history.
 */
interface Orders
{
    /** @return array<int,array{id:string,name:string,areas:array<int,array{id:string,name:string}>}> */
    public function deliveryLocations(): array;

    /** @return array<int,array{id:string,name:string,description:string,price:float,image:?string}> */
    public function serviceAddons(): array;

    public function addonsEnabled(): bool;

    public function addonsRequired(): bool;

    /** @return array<int,array<string,mixed>> */
    public function paymentMethods(): array;

    /**
     * @param  array<string,mixed>  $input  validated checkout input
     * @return array{ok:bool,kind:string,paymentUrl:?string,orderId:?string,message:?string}
     */
    public function place(array $input): array;

    /** Local 8-digit Kuwaiti numbers become +965XXXXXXXX. */
    public function e164(string $phone): string;

    /** @return array<int,array<string,mixed>> */
    public function myOrders(): array;

    /** @return array<string,mixed>|null */
    public function findOrder(string $id): ?array;
}
