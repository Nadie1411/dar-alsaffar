@extends('panel.layout')

@section('title', __('panel.modules.inbox'))

@section('content')
    @php use App\Support\PanelFormat; @endphp

    <x-panel.page-head :title="__('panel.modules.inbox')" :sub="__('panel.inbox.sub')"/>

    <section class="card">
        @include('panel.inbox._tabs', ['unread' => $unread, 'subscriberCount' => $subscribers])

        <form class="toolbar" method="GET" action="{{ route('panel.inbox.index') }}">
            <div class="search">
                <x-panel.icon name="search" :size="18"/>
                <input class="in" type="search" name="q" value="{{ $q }}" placeholder="{{ __('panel.inbox.searchHint') }}" aria-label="{{ __('panel.common.search') }}">
            </div>
            <select class="sel" name="filter" data-autosubmit aria-label="{{ __('panel.common.status') }}">
                <option value="all" @selected($filter === 'all')>{{ __('panel.common.all') }} ({{ $total }})</option>
                <option value="unread" @selected($filter === 'unread')>{{ __('panel.inbox.unread') }} ({{ $unread }})</option>
            </select>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.filter') }}</button>
        </form>

        @if ($messages->isEmpty())
            <x-panel.empty icon="inbox" :title="__('panel.inbox.none')" :text="__('panel.inbox.noneHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.inbox.from') }}</th>
                        <th>{{ __('panel.inbox.message') }}</th>
                        <th>{{ __('panel.common.date') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($messages as $message)
                        <tr>
                            <td>
                                <a class="cell-main" href="{{ route('panel.inbox.show', $message) }}" @if (! $message->read_at) style="font-weight:700" @endif>{{ $message->name }}</a>
                                <span class="cell-sub"><span class="ltr">{{ $message->email }}</span></span>
                            </td>
                            <td>
                                @if (! $message->read_at)<x-panel.pill tone="blue" plain>{{ __('panel.inbox.new') }}</x-panel.pill>@endif
                                {{ \Illuminate\Support\Str::limit($message->message, 110) }}
                            </td>
                            <td class="nowrap">{{ PanelFormat::dateTime($message->created_at) }}</td>
                            <td class="col-actions"><a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.inbox.show', $message) }}">{{ __('panel.common.view') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$messages"/>
        @endif
    </section>
@endsection
