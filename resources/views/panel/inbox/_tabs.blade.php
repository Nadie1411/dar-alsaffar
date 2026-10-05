<nav class="tabs" aria-label="{{ __('panel.modules.inbox') }}">
    <a class="tab {{ request()->routeIs('panel.inbox.index', 'panel.inbox.show') ? 'is-active' : '' }}" href="{{ route('panel.inbox.index') }}">
        {{ __('panel.inbox.messages') }} @if ($unread > 0)<span class="tab__n">{{ $unread }}</span>@endif
    </a>
    <a class="tab {{ request()->routeIs('panel.inbox.subscribers') ? 'is-active' : '' }}" href="{{ route('panel.inbox.subscribers') }}">
        {{ __('panel.inbox.newsletter') }} <span class="tab__n">{{ $subscriberCount }}</span>
    </a>
</nav>
