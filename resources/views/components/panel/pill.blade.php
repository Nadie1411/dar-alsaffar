@props(['tone' => 'grey', 'plain' => false])

<span {{ $attributes->class(['pill', 'pill--'.$tone, 'pill--plain' => $plain]) }}>{{ $slot }}</span>
