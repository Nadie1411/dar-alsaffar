@props(['name', 'label', 'checked' => false, 'hint' => null, 'value' => '1'])

<div class="field-p">
    <label class="switch">
        <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
        <span class="switch__track" aria-hidden="true"></span>
        <span>{{ $label }}</span>
    </label>
    @if ($hint)<span class="field-p__hint">{{ $hint }}</span>@endif
</div>
