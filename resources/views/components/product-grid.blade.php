@props([
    'products' => [],
    'rail' => false,
    'eagerCount' => 4,
])

@if (count($products))
    <div {{ $attributes->class(['product-grid' => ! $rail, 'rail' => $rail]) }}>
        @foreach ($products as $i => $product)
            <x-product-card :product="$product" :eager="$i < $eagerCount"/>
        @endforeach
    </div>
@endif
