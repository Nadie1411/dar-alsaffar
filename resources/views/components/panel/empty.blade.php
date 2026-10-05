@props(['icon' => 'search', 'title', 'text' => null])

<div class="empty-p">
    <x-panel.icon :name="$icon" :size="44"/>
    <strong>{{ $title }}</strong>
    @if ($text)<p>{{ $text }}</p>@endif
    {{ $slot }}
</div>
