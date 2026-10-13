@extends('panel.layout')

@section('title', __('panel.gateway.title'))

@section('content')
    @php use App\Services\Store\Payments\GatewayConfig; @endphp

    <x-panel.page-head :title="__('panel.gateway.title')" :sub="__('panel.gateway.sub')"
                       :back="route('panel.payments.index')" :backLabel="__('panel.modules.payments')"/>

    @if ($unreadable)
        <div class="alert-p alert-p--err" role="alert">
            <x-panel.icon name="alert" :size="18"/><span>{{ __('panel.gateway.unreadable') }}</span>
        </div>
    @endif

    <form class="grid grid--main" method="POST" action="{{ route('panel.payments.gateway.update') }}" autocomplete="off">
        @csrf @method('PUT')

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.gateway.keys') }}</h2></div>
                <div class="card__body stack" style="--gap:18px">
                    <p class="alert-p alert-p--info">
                        <x-panel.icon name="lock" :size="18"/>
                        <span>{{ __('panel.gateway.notice') }}</span>
                    </p>

                    <x-panel.select name="environment" :label="__('panel.gateway.environment')" :selected="old('environment', $environment)"
                                    :blank="__('panel.gateway.useServer', ['url' => $serverUrl])"
                                    :options="collect($endpoints)->mapWithKeys(fn ($name) => [$name => __('panel.gateway.endpoints.'.$name)])->all()"
                                    :hint="__('panel.gateway.environmentHint')"/>

                    <div class="field-p">
                        <label for="f-api_key">{{ __('panel.payments.apiKey') }}</label>
                        <input class="in in--ltr" id="f-api_key" name="api_key" type="password" value="" autocomplete="new-password"
                               autocapitalize="off" spellcheck="false" placeholder="{{ $keySource === GatewayConfig::SOURCE_PANEL ? __('panel.gateway.keepSaved') : '' }}"
                               @error('api_key') aria-invalid="true" @enderror>
                        @error('api_key')<span class="field-p__error">{{ $message }}</span>@enderror
                        <span class="field-p__hint">{{ __('panel.gateway.status', ['state' => __('panel.gateway.sources.'.$keySource)]) }} · {{ __('panel.gateway.keyHint') }}</span>
                        @if ($keySource === GatewayConfig::SOURCE_PANEL)
                            <label class="check"><input type="checkbox" name="clear_api_key" value="1"> {{ __('panel.gateway.removeSaved') }}</label>
                        @endif
                    </div>

                    <div class="field-p">
                        <label for="f-webhook_secret">{{ __('panel.payments.webhookSecret') }}</label>
                        <input class="in in--ltr" id="f-webhook_secret" name="webhook_secret" type="password" value="" autocomplete="new-password"
                               autocapitalize="off" spellcheck="false" placeholder="{{ $secretSource === GatewayConfig::SOURCE_PANEL ? __('panel.gateway.keepSaved') : '' }}"
                               @error('webhook_secret') aria-invalid="true" @enderror>
                        @error('webhook_secret')<span class="field-p__error">{{ $message }}</span>@enderror
                        <span class="field-p__hint">{{ __('panel.gateway.status', ['state' => __('panel.gateway.sources.'.$secretSource)]) }} · {{ __('panel.gateway.secretHint') }}</span>
                        @if ($secretSource === GatewayConfig::SOURCE_PANEL)
                            <label class="check"><input type="checkbox" name="clear_webhook_secret" value="1"> {{ __('panel.gateway.removeSaved') }}</label>
                        @endif
                    </div>

                    <x-panel.switch name="enabled" :label="__('panel.gateway.enabled')" :checked="old('enabled', $enabled)" :hint="__('panel.gateway.enabledHint')"/>
                </div>
            </section>

            <section class="card" id="apple-pay">
                <div class="card__head"><h2>{{ __('panel.gateway.applePay.title') }}</h2></div>
                <div class="card__body stack" style="--gap:18px">
                    <p class="alert-p alert-p--info">
                        <x-panel.icon name="payments" :size="18"/>
                        <span>{{ __('panel.gateway.applePay.intro') }}</span>
                    </p>

                    <ol class="steps-p">
                        <li>{{ __('panel.gateway.applePay.step1') }}</li>
                        <li>{{ __('panel.gateway.applePay.step2', ['url' => $applePay['fileUrl']]) }}</li>
                        <li>{{ __('panel.gateway.applePay.step3', ['host' => $applePay['host']]) }}</li>
                        <li>{{ __('panel.gateway.applePay.step4') }}</li>
                    </ol>

                    <x-panel.textarea name="apple_pay_domain_file" :label="__('panel.gateway.applePay.file')"
                                      :value="old('apple_pay_domain_file', $applePay['file'])" rows="4" dir="ltr" spellcheck="false" autocapitalize="off"
                                      :hint="__('panel.gateway.applePay.fileHint')"/>

                    <dl class="kv">
                        <div><dt>{{ __('panel.gateway.applePay.fileStatus') }}</dt><dd>{{ $applePay['file'] !== '' ? __('panel.gateway.applePay.fileSaved') : __('panel.gateway.applePay.fileMissing') }}</dd></div>
                        <div><dt>{{ __('panel.gateway.applePay.registeredStatus') }}</dt>
                            <dd>
                                @if ($applePay['registeredAt'])
                                    <span class="ltr">{{ $applePay['registeredDomain'] }}</span> · {{ \App\Support\PanelFormat::dateTime($applePay['registeredAt']) }}
                                @else
                                    {{ __('panel.gateway.applePay.notRegistered') }}
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="row">
                        <button class="btn-p btn-p--ghost" type="submit" form="apple-pay-register" @disabled($applePay['file'] === '')>{{ __('panel.gateway.applePay.register', ['host' => $applePay['host']]) }}</button>
                    </div>
                    <span class="field-p__hint">{{ __('panel.gateway.applePay.registerHint') }}</span>

                    @unless ($applePay['scriptKnown'])
                        <p class="alert-p alert-p--warn"><x-panel.icon name="alert" :size="18"/><span>{{ __('panel.gateway.applePay.scriptUnknown') }}</span></p>
                    @endunless

                    <x-panel.switch name="apple_pay_on_site" :label="__('panel.gateway.applePay.switch')" :checked="old('apple_pay_on_site', $applePay['onSite'])" :hint="__('panel.gateway.applePay.switchHint')"/>
                    @error('apple_pay_on_site')<span class="field-p__error">{{ $message }}</span>@enderror
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.gateway.confirm') }}</h2></div>
                <div class="card__body stack" style="--gap:12px">
                    <x-panel.input name="current_password" type="password" :label="__('panel.profile.currentPassword')" required ltr autocomplete="current-password"
                                   :hint="__('panel.gateway.confirmHint')"/>
                    <button class="btn-p btn-p--block" type="submit">{{ __('panel.common.saveChanges') }}</button>
                    <a class="btn-p btn-p--ghost btn-p--block" href="{{ route('panel.payments.index') }}">{{ __('panel.common.cancel') }}</a>
                </div>
            </section>

            @if ($savedAt)
                <p class="muted small">{{ __('panel.gateway.lastSaved', ['date' => \App\Support\PanelFormat::dateTime($savedAt)]) }}@if ($savedBy) · {{ __('panel.common.by', ['name' => $savedBy]) }}@endif</p>
            @endif
        </div>
    </form>

    {{-- Its own form, so registering does not also send the keys and the password. --}}
    <form id="apple-pay-register" method="POST" action="{{ route('panel.payments.gateway.apple-pay.register') }}">
        @csrf
    </form>
@endsection
