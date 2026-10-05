@props(['name', 'label', 'value' => null, 'hint' => null, 'required' => false, 'rows' => 4])

@php
    $dot = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id = 'f-'.trim(preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $error = $errors->first($dot);
@endphp

<div class="field-p">
    <label for="{{ $id }}">{{ $label }}@if ($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    <textarea {{ $attributes->class(['ta']) }} id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @required($required)
              @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif>{{ $value }}</textarea>
    @if ($error)<span class="field-p__error" id="{{ $id }}-error">{{ $error }}</span>
    @elseif ($hint)<span class="field-p__hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
</div>
