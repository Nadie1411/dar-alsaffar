@props([
    'icon' => 'bag',
    'title',
    'text' => null,
    'href' => null,
    'label' => null,
])

<div {{ $attributes->class('empty') }}>
    <x-icon :name="$icon" size="40" class="empty__icon"/>
    <p class="empty__title">{{ $title }}</p>
    @if ($text)<p class="empty__text">{{ $text }}</p>@endif
    @if ($href)
        <a class="btn" href="{{ $href }}">{{ $label ?? __('storefront.actions.shopNow') }}</a>
    @endif
</div>
