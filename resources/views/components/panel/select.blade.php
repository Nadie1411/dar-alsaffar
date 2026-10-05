@props(['name', 'label', 'options' => [], 'selected' => null, 'hint' => null, 'required' => false, 'blank' => null])

@php
    $dot = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id = 'f-'.trim(preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $error = $errors->first($dot);
@endphp

<div class="field-p">
    <label for="{{ $id }}">{{ $label }}@if ($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    <select {{ $attributes->class(['sel']) }} id="{{ $id }}" name="{{ $name }}" @required($required)
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        @if ($blank !== null)<option value="">{{ $blank }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $value === (string) $selected)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($error)<span class="field-p__error" id="{{ $id }}-error">{{ $error }}</span>
    @elseif ($hint)<span class="field-p__hint">{{ $hint }}</span>@endif
</div>
