@props(['name', 'label', 'current' => null, 'removeName' => null, 'hint' => null])

@php
    use App\Support\Media;

    $id = 'f-'.trim(preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $error = $errors->first($name);
@endphp

<div class="field-p">
    <span class="field-p__label">{{ $label }}</span>
    <div class="thumbs" id="{{ $id }}-preview">
        @if ($current && ($url = Media::url($current)))
            <div class="thumbs__item">
                <img src="{{ $url }}" alt="" loading="lazy">
                @if ($removeName)
                    <label><input type="checkbox" name="{{ $removeName }}" value="1"> {{ __('panel.common.remove') }}</label>
                @endif
            </div>
        @endif
    </div>
    <input class="in" id="{{ $id }}" type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp"
           data-preview="#{{ $id }}-preview" @if ($error) aria-invalid="true" @endif>
    @if ($error)<span class="field-p__error">{{ $error }}</span>
    @elseif ($hint)<span class="field-p__hint">{{ $hint }}</span>@endif
</div>
