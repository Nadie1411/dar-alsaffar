@extends('panel.layout')

@section('title', $message->name)

@section('content')
    @php use App\Support\PanelFormat; @endphp

    <x-panel.page-head :title="$message->name" :back="route('panel.inbox.index')" :backLabel="__('panel.modules.inbox')"
                       :sub="PanelFormat::dateTime($message->created_at)">
        <form method="POST" action="{{ route('panel.inbox.read', $message) }}">
            @csrf
            <button class="btn-p btn-p--ghost" type="submit">{{ __('panel.inbox.markUnread') }}</button>
        </form>
    </x-panel.page-head>

    <div class="grid grid--main">
        <section class="card">
            <div class="card__body">
                <p style="white-space: pre-line; line-height: 1.8">{{ $message->message }}</p>
            </div>
        </section>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.inbox.reply') }}</h2></div>
                <div class="card__body stack" style="--gap:12px">
                    <a class="btn-p btn-p--block" href="mailto:{{ $message->email }}?subject={{ rawurlencode(__('panel.inbox.replySubject')) }}">{{ __('panel.inbox.replyByEmail') }}</a>
                    @if ($message->phone)
                        <a class="btn-p btn-p--ghost btn-p--block" href="https://wa.me/{{ preg_replace('/\D+/', '', $message->phone) }}" target="_blank" rel="noopener">{{ __('panel.inbox.replyOnWhatsapp') }}</a>
                    @endif
                    <dl class="kv">
                        <div><dt>{{ __('panel.orders.email') }}</dt><dd><span class="ltr">{{ $message->email }}</span></dd></div>
                        @if ($message->phone)<div><dt>{{ __('panel.orders.phone') }}</dt><dd><span class="ltr">{{ $message->phone }}</span></dd></div>@endif
                    </dl>
                </div>
            </section>

            <form method="POST" action="{{ route('panel.inbox.destroy', $message) }}">
                @csrf @method('DELETE')
                <button class="btn-p btn-p--danger btn-p--block" type="submit" data-danger data-confirm="{{ __('panel.inbox.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
            </form>
        </div>
    </div>
@endsection
