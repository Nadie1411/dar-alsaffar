<?php

namespace App\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Support\PanelFormat;
use Illuminate\Http\JsonResponse;

/**
 * What the new-order alert polls. The cursor is the latest move of an order
 * into "new" rather than the latest order, so an online order that is paid
 * minutes after it was placed still rings when its payment is confirmed.
 */
class OrderFeedController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $latest = OrderStatusChange::query()
            ->where('to_status', OrderStatus::New->value)
            ->with('order')
            ->latest('id')
            ->first();

        return response()
            ->json([
                'latestId' => $latest?->id ?? 0,
                'awaiting' => Order::query()->where('status', OrderStatus::New->value)->count(),
                'message' => $latest?->order === null ? '' : __('panel.orders.alert', [
                    'number' => $latest->order->number,
                    'total' => PanelFormat::money($latest->order->total_fils),
                ]),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
