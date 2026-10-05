@extends('panel.layout')

@php
    use App\Enums\AdminRole;

    $editing = $member->exists;
    $me = auth('staff')->user();
@endphp

@section('title', $editing ? $member->name : __('panel.staff.add'))

@section('content')
    <x-panel.page-head :title="$editing ? $member->name : __('panel.staff.add')" :back="route('panel.staff.index')" :backLabel="__('panel.modules.staff')"/>

    <form class="grid grid--main" method="POST" action="{{ $editing ? route('panel.staff.update', $member) : route('panel.staff.store') }}" autocomplete="off">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="stack">
            <section class="card">
                <div class="card__body form-grid">
                    <x-panel.input name="name" :label="__('panel.common.name')" :value="old('name', $member->name)" required maxlength="120"/>
                    <x-panel.input name="email" type="email" :label="__('panel.login.email')" :value="old('email', $member->email)" required maxlength="190" ltr autocomplete="off"/>
                    <x-panel.select name="role" :label="__('panel.staff.role')" :selected="old('role', $member->role->value)"
                                    :options="collect(AdminRole::cases())->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all()"/>
                    <x-panel.select name="locale" :label="__('panel.staff.language')" :selected="old('locale', $member->locale)"
                                    :options="['ar' => __('panel.common.arabic'), 'en' => __('panel.common.english')]"/>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ $editing ? __('panel.staff.newPassword') : __('panel.login.password') }}</h2></div>
                <div class="card__body form-grid">
                    <x-panel.input name="password" type="password" :label="__('panel.login.password')" :required="! $editing" ltr autocomplete="new-password"
                                   :hint="$editing ? __('panel.staff.passwordKeep') : __('panel.staff.passwordRule')"/>
                    <x-panel.input name="password_confirmation" type="password" :label="__('panel.staff.passwordConfirm')" :required="! $editing" ltr autocomplete="new-password"/>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.common.status') }}</h2></div>
                <div class="card__body">
                    <x-panel.switch name="is_active" :label="__('panel.staff.canSignIn')" :checked="old('is_active', $member->is_active)" :hint="__('panel.staff.canSignInHint')"/>
                </div>
            </section>

            <div class="row">
                <button class="btn-p grow" type="submit">{{ __('panel.common.saveChanges') }}</button>
                <a class="btn-p btn-p--ghost" href="{{ route('panel.staff.index') }}">{{ __('panel.common.cancel') }}</a>
            </div>

            @if ($editing && ! $member->is($me))
                <button class="btn-p btn-p--danger btn-p--block" type="submit" form="delete-member" data-danger
                        data-confirm="{{ __('panel.staff.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
            @endif
        </div>
    </form>

    @if ($editing && ! $member->is($me))
        <form id="delete-member" method="POST" action="{{ route('panel.staff.destroy', $member) }}" hidden>@csrf @method('DELETE')</form>
    @endif
@endsection
