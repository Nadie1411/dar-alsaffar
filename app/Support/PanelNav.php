<?php

namespace App\Support;

use App\Enums\PanelModule;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The panel's sidebar: which links exist, in what groups, and which of them
 * the signed-in staff member's role lets them see.
 */
class PanelNav
{
    /**
     * @return array<string,array<int,array{module:PanelModule,route:string,icon:string,match:string}>>
     */
    protected static function definition(): array
    {
        return [
            'overview' => [
                ['module' => PanelModule::Dashboard, 'route' => 'panel.dashboard', 'icon' => 'dashboard', 'match' => 'panel.dashboard'],
                ['module' => PanelModule::Reports, 'route' => 'panel.reports.index', 'icon' => 'reports', 'match' => 'panel.reports.*'],
            ],
            'sales' => [
                ['module' => PanelModule::Orders, 'route' => 'panel.orders.index', 'icon' => 'orders', 'match' => 'panel.orders.*'],
                ['module' => PanelModule::Customers, 'route' => 'panel.customers.index', 'icon' => 'customers', 'match' => 'panel.customers.*'],
                ['module' => PanelModule::Inbox, 'route' => 'panel.inbox.index', 'icon' => 'inbox', 'match' => 'panel.inbox.*'],
            ],
            'catalogue' => [
                ['module' => PanelModule::Products, 'route' => 'panel.products.index', 'icon' => 'products', 'match' => 'panel.products.*'],
                ['module' => PanelModule::Categories, 'route' => 'panel.categories.index', 'icon' => 'categories', 'match' => 'panel.categories.*'],
                ['module' => PanelModule::Addons, 'route' => 'panel.addons.index', 'icon' => 'addons', 'match' => 'panel.addons.*'],
            ],
            'store' => [
                ['module' => PanelModule::Vouchers, 'route' => 'panel.vouchers.index', 'icon' => 'vouchers', 'match' => 'panel.vouchers.*'],
                ['module' => PanelModule::Delivery, 'route' => 'panel.delivery.index', 'icon' => 'delivery', 'match' => 'panel.delivery.*'],
                ['module' => PanelModule::Payments, 'route' => 'panel.payments.index', 'icon' => 'payments', 'match' => 'panel.payments.*'],
                ['module' => PanelModule::Content, 'route' => 'panel.content.edit', 'icon' => 'content', 'match' => 'panel.content.*'],
            ],
            'team' => [
                ['module' => PanelModule::Staff, 'route' => 'panel.staff.index', 'icon' => 'staff', 'match' => 'panel.staff.*'],
                ['module' => PanelModule::Log, 'route' => 'panel.log.index', 'icon' => 'log', 'match' => 'panel.log.*'],
            ],
        ];
    }

    /**
     * What this person can open, grouped, leaving out groups that end up empty.
     * A link whose route does not exist yet is left out too, so the sidebar
     * only ever offers pages that work.
     *
     * @return array<string,array<int,array{label:string,url:string,icon:string,active:bool,module:string}>>
     */
    public static function for(User $user): array
    {
        $groups = [];

        foreach (self::definition() as $group => $links) {
            $visible = [];

            foreach ($links as $link) {
                if (! $user->canAccess($link['module']) || ! Route::has($link['route'])) {
                    continue;
                }

                $visible[] = [
                    'label' => $link['module']->label(),
                    'url' => route($link['route']),
                    'icon' => $link['icon'],
                    'active' => request()->routeIs($link['match']),
                    'module' => $link['module']->value,
                ];
            }

            if ($visible !== []) {
                $groups[$group] = $visible;
            }
        }

        return $groups;
    }

    /** A panel URL, or null while that page does not exist — so a link is never offered that would 404. */
    public static function link(string $route, array $parameters = []): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }
}
