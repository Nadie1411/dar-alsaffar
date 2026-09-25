@extends('pages.legal._layout', ['pageTitle' => __('storefront.content.shippingTitle')])

@section('policy')
    {{-- Delivery fees and areas are computed by the store's own system at
         checkout, so the only thing stated here is that fact — no invented
         prices or delivery promises. --}}
    <p>{{ __('storefront.values.deliveryText') }}</p>
    <p class="muted"><em>{{ __('storefront.content.contentPending') }}</em></p>
@endsection
