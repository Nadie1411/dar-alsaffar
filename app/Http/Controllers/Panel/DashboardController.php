<?php

namespace App\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Enums\PanelModule;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Store\Reports\SalesReport;
use App\Support\PanelFormat;
use App\Support\PanelNav;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesReport $report): View
    {
        $user = $request->user('staff');
        $now = now(PanelFormat::timezone());
        $today = $now->copy()->startOfDay();
        $thirtyDays = $now->copy()->subDays(29)->startOfDay();

        // Money figures are for the people who may open the reports; everyone
        // else sees the day's work.
        $canSeeSales = $user->canAccess(PanelModule::Reports);

        return view('panel.dashboard', [
            'user' => $user,
            'canSeeSales' => $canSeeSales,
            'today' => $report->summary($today, $now->copy()->endOfDay()),
            'month' => $canSeeSales ? $report->summary($thirtyDays, $now->copy()->endOfDay()) : null,
            'series' => $canSeeSales ? $report->daily($thirtyDays, $now->copy()->endOfDay()) : [],
            'topProducts' => $canSeeSales ? $report->topProducts($thirtyDays, $now->copy()->endOfDay(), 6) : [],
            'awaiting' => Order::query()->where('status', OrderStatus::New->value)->count(),
            'recentOrders' => Order::query()->latest('id')->limit(8)->get(),
            'attention' => $this->attention($user),
        ]);
    }

    /**
     * The things that want a person, each with how many and where to go —
     * only those the signed-in role can act on, and only while there are any.
     *
     * @return array<int,array{key:string,count:int,url:?string}>
     */
    protected function attention($user): array
    {
        $rows = [
            [PanelModule::Orders, 'newOrders', Order::query()->where('status', OrderStatus::New->value)->count(),
                PanelNav::link('panel.orders.index', ['status' => OrderStatus::New->value])],
            [PanelModule::Inbox, 'unreadMessages', ContactMessage::query()->unread()->count(),
                PanelNav::link('panel.inbox.index')],
            [PanelModule::Payments, 'paymentsToReview', Payment::query()->needsReview()->count(),
                PanelNav::link('panel.payments.index', ['review' => 1])],
            [PanelModule::Products, 'outOfStock', Product::query()->active()->where('track_stock', true)->where('stock', '<=', 0)->count(),
                PanelNav::link('panel.products.index', ['stock' => 'out'])],
            [PanelModule::Products, 'lowStock', Product::query()->active()->where('track_stock', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')->count(),
                PanelNav::link('panel.products.index', ['stock' => 'low'])],
        ];

        return collect($rows)
            ->filter(fn (array $row) => $row[2] > 0 && $user->canAccess($row[0]))
            ->map(fn (array $row) => ['key' => $row[1], 'count' => $row[2], 'url' => $row[3]])
            ->values()
            ->all();
    }
}
