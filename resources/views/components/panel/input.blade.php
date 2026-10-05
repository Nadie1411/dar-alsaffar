@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null, 'required' => false, 'ltr' => false, 'suffix' => null])

@php
    $dot = trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    $id = 'f-'.trim(preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $error = $errors->first($dot);
@endphp

<div class="field-p">
    <label for="{{ $id }}">{{ $label }}@if ($required)<span class="req" aria-hidden="true">*</span>@endif</label>
    @if ($suffix)<div class="in-group">@endif
    <input {{ $attributes->class(['in', 'in--ltr' => $ltr]) }} id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $value }}" @required($required)
           @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif>
    @if ($suffix)<span class="in-group__tag">{{ $suffix }}</span></div>@endif
    @if ($error)<span class="field-p__error" id="{{ $id }}-error">{{ $error }}</span>
    @elseif ($hint)<span class="field-p__hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
</div>
