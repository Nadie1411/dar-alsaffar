@extends('panel.layout')

@section('title', __('panel.profile.title'))

@section('content')
    <x-panel.page-head :title="__('panel.profile.title')" :sub="$member->role->label().' · '.$member->email"/>

    <form class="stack" method="POST" action="{{ route('panel.profile.update') }}" style="max-inline-size: 640px" autocomplete="off">
        @csrf @method('PUT')

        <section class="card">
            <div class="card__body form-grid">
                <x-panel.input name="name" :label="__('panel.common.name')" :value="old('name', $member->name)" required maxlength="120"/>
                <x-panel.select name="locale" :label="__('panel.staff.language')" :selected="old('locale', $member->locale)"
                                :options="['ar' => __('panel.common.arabic'), 'en' => __('panel.common.english')]"/>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>{{ __('panel.staff.newPassword') }}</h2></div>
            <div class="card__body form-grid">
                <div class="span-2"><x-panel.input name="current_password" type="password" :label="__('panel.profile.currentPassword')" ltr autocomplete="current-password" :hint="__('panel.profile.currentHint')"/></div>
                <x-panel.input name="password" type="password" :label="__('panel.profile.newPassword')" ltr autocomplete="new-password" :hint="__('panel.staff.passwordRule')"/>
                <x-panel.input name="password_confirmation" type="password" :label="__('panel.staff.passwordConfirm')" ltr autocomplete="new-password"/>
            </div>
        </section>

        <div><button class="btn-p" type="submit">{{ __('panel.common.saveChanges') }}</button></div>
    </form>

    <section class="card" style="max-inline-size: 640px; margin-block-start: var(--space-5, 24px)"
             data-push
             data-key="{{ $pushKey }}"
             data-subscribe="{{ route('panel.push.subscribe') }}"
             data-unsubscribe="{{ route('panel.push.unsubscribe') }}"
             data-test="{{ route('panel.push.test') }}"
             data-on="{{ __('panel.push.on') }}"
             data-off="{{ __('panel.push.off') }}"
             data-denied="{{ __('panel.push.denied') }}"
             data-unsupported="{{ __('panel.push.unsupported') }}"
             data-needs-install="{{ __('panel.push.needsInstall') }}"
             data-failed="{{ __('panel.push.failed') }}"
             data-sent="{{ __('panel.push.sent') }}"
             data-none-sent="{{ __('panel.push.noneSent') }}">
        <div class="card__head"><h2>{{ __('panel.push.title') }}</h2></div>
        <div class="card__body stack">
            <p class="muted">{{ __('panel.push.intro') }}</p>

            <p class="alert-p" data-push-note hidden></p>

            <div class="row" style="gap: 8px; flex-wrap: wrap">
                <button class="btn-p" type="button" data-push-enable hidden>{{ __('panel.push.enable') }}</button>
                <button class="btn-p btn-p--ghost" type="button" data-push-test hidden>{{ __('panel.push.test') }}</button>
                <button class="btn-p btn-p--ghost" type="button" data-push-disable hidden>{{ __('panel.push.disable') }}</button>
            </div>

            <p class="muted" data-push-ios hidden>{{ __('panel.push.ios') }}</p>
        </div>
    </section>
@endsection
