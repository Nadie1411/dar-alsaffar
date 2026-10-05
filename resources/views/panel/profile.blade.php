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
@endsection
