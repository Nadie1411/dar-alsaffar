@extends('panel.layout')

@section('title', __('panel.modules.inbox'))

@section('content')
    @php use App\Support\PanelFormat; @endphp

    <x-panel.page-head :title="__('panel.modules.inbox')" :sub="__('panel.inbox.sub')">
        <a class="btn-p btn-p--ghost" href="{{ route('panel.inbox.subscribers.export') }}"><x-panel.icon name="download" :size="18"/>{{ __('panel.common.export') }}</a>
    </x-panel.page-head>

    <section class="card">
        @include('panel.inbox._tabs', ['unread' => $unread, 'subscriberCount' => $active])

        <form class="toolbar" method="GET" action="{{ route('panel.inbox.subscribers') }}">
            <div class="search">
                <x-panel.icon name="search" :size="18"/>
                <input class="in" type="search" name="q" value="{{ $q }}" placeholder="{{ __('panel.orders.email') }}" aria-label="{{ __('panel.common.search') }}">
            </div>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.search') }}</button>
        </form>

        @if ($subscribers->isEmpty())
            <x-panel.empty icon="inbox" :title="__('panel.inbox.noSubscribers')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.orders.email') }}</th>
                        <th>{{ __('panel.inbox.language') }}</th>
                        <th>{{ __('panel.customers.joined') }}</th>
                        <th>{{ __('panel.common.status') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($subscribers as $subscriber)
                        <tr>
                            <td><span class="ltr">{{ $subscriber->email }}</span></td>
                            <td>{{ $subscriber->locale === 'en' ? __('panel.common.english') : __('panel.common.arabic') }}</td>
                            <td>{{ PanelFormat::date($subscriber->created_at) }}</td>
                            <td>
                                @if ($subscriber->unsubscribed_at)
                                    <x-panel.pill tone="grey">{{ __('panel.inbox.unsubscribed') }}</x-panel.pill>
                                @else
                                    <x-panel.pill tone="green">{{ __('panel.inbox.subscribed') }}</x-panel.pill>
                                @endif
                            </td>
                            <td class="col-actions">
                                <form method="POST" action="{{ route('panel.inbox.subscribers.destroy', $subscriber) }}" style="display:inline">
                                    @csrf @method('DELETE')
                                    <button class="btn-p btn-p--danger btn-p--sm" type="submit" data-danger data-confirm="{{ __('panel.inbox.confirmRemoveSubscriber') }}">{{ __('panel.common.remove') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$subscribers"/>
        @endif
    </section>
@endsection
