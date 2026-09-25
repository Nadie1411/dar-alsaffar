@props(['product'])

@php
    use App\Support\Money;

    // The bundle builder handles checkbox "choose N" groups on its own page.
    $groups = collect($product->options())
        ->reject(fn ($g) => $g['layout'] === 'checkbox' && $g['max'] > 1)
        ->values();
@endphp

@if ($groups->isNotEmpty())
    <div class="option-groups" data-options>
        @foreach ($groups as $group)
            <fieldset class="option-group" data-option-group="{{ $group['id'] }}"
                      data-required="{{ $group['required'] ? '1' : '0' }}">
                <legend class="option-group__legend">
                    {{ $group['name'] }}
                    @if ($group['required'])
                        <span class="field__required" aria-hidden="true">*</span>
                        <span class="visually-hidden">{{ __('storefront.errors.required') }}</span>
                    @endif
                </legend>

                <div class="option-group__values">
                    @foreach ($group['values'] as $value)
                        <label class="option-chip">
                            <input type="{{ $group['layout'] === 'checkbox' ? 'checkbox' : 'radio' }}"
                                   name="option-{{ $group['id'] }}"
                                   value="{{ $value['id'] }}"
                                   data-option-value
                                   data-price="{{ $value['price'] }}"
                                   class="visually-hidden">
                            <span class="option-chip__name">{{ $value['name'] }}</span>
                            @if ($value['price'] > 0)
                                <span class="option-chip__price">
                                    {{ Money::format($value['price'], $product->symbol()) }}
                                </span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>
@endif
