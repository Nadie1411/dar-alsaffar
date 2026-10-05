<?php

namespace App\Contracts\Store;

/**
 * The offers the store has published for the storefront.
 */
interface Promotions
{
    /** @return array<int,array<string,mixed>> */
    public function public(): array;

    /** The single offer worth putting in the announcement bar, if any. */
    public function headline(): ?array;
}
