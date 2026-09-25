@props([
    'eyebrow' => null,
    'title',
    'lede' => null,
    'href' => null,
    'linkLabel' => null,
    'level' => 'h2',
])

<div {{ $attributes->class('section-heading') }}>
    <div class="section-heading__text">
        @if ($eyebrow)
            <p class="section-heading__eyebrow">{{ $eyebrow }}</p>
        @endif

        <{{ $level }} class="section-heading__title">{{ $title }}</{{ $level }}>

        @if ($lede)
            <p class="section-heading__lede">{{ $lede }}</p>
        @endif
    </div>

    @if ($href)
        <a class="link-underline" href="{{ $href }}">
            {{ $linkLabel ?? __('storefront.nav.all') }}
            <x-icon name="arrow" size="16" class="icon-arrow"/>
        </a>
    @endif
</div>
