@php use App\Support\Nav; @endphp

<nav class="account-nav" aria-label="{{ __('storefront.account.title') }}">
    @foreach ([
        ['account',           'grid',    __('storefront.account.overview')],
        ['account/orders',    'package', __('storefront.account.orders')],
        ['account/addresses', 'pin',     __('storefront.account.addresses')],
        ['wishlist',          'heart',   __('storefront.wishlist.title')],
        ['account/settings',  'user',    __('storefront.account.settings')],
    ] as [$path, $icon, $label])
        <a href="{{ Nav::url($path) }}"
           @if (request()->path() === Nav::code().'/'.$path) aria-current="page" @endif>
            <x-icon :name="$icon" size="16"/> {{ $label }}
        </a>
    @endforeach

    <form method="POST" action="{{ Nav::url('logout') }}">
        @csrf
        <button type="submit" style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) var(--space-4);font-size:var(--step-small);color:var(--text-secondary);width:100%">
            <x-icon name="logout" size="16"/> {{ __('storefront.auth.logout') }}
        </button>
    </form>
</nav>
