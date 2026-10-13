@props(['options' => []])

@php
    use App\Support\Loc;

    // What the shopper chose on this line — a size, the pieces of a set — as
    // "group: value، value", so the bag shows what they are about to buy.
    $separator = app()->getLocale() === 'ar' ? '، ' : ', ';

    $choices = collect($options)
        ->map(function ($selection) use ($separator) {
            $names = collect($selection['values'] ?? [])
                ->map(fn ($value) => Loc::text($value['value']['name'] ?? $value['name'] ?? null))
                ->filter()
                ->implode($separator);

            return [
                'group' => Loc::text($selection['option']['name'] ?? $selection['group'] ?? $selection['name'] ?? null),
                'names' => $names,
            ];
        })
        ->filter(fn (array $choice) => $choice['names'] !== '');
@endphp

@foreach ($choices as $choice)
    <p {{ $attributes->class('line-choices') }}>
        @if ($choice['group'] !== '')<span class="line-choices__group">{{ $choice['group'] }}:</span>@endif
        {{ $choice['names'] }}
    </p>
@endforeach
