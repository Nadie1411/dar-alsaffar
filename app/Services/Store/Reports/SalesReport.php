<?php

namespace App\Services\Store\Reports;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\PanelFormat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads what has been sold. Every figure is a sum of totals the pricing engine
 * already fixed on the order — nothing here prices anything.
 *
 * A day is a day in Kuwait: orders are stored in UTC, so the boundaries are
 * converted on the way in and the days are bucketed on the way out.
 */
class SalesReport
{
    /**
     * @return array{orders:int,revenue:int,discounts:int,delivery:int,average:int}
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->counted($from, $to)
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total_fils), 0) AS revenue, COALESCE(SUM(discount_fils), 0) AS discounts, COALESCE(SUM(delivery_fee_fils), 0) AS delivery')
            ->toBase()
            ->first();

        $orders = (int) $row->orders;
        $revenue = (int) $row->revenue;

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'discounts' => (int) $row->discounts,
            'delivery' => (int) $row->delivery,
            'average' => $orders === 0 ? 0 : intdiv($revenue + intdiv($orders, 2), $orders),
        ];
    }

    /**
     * One entry for every day in the range, including the quiet ones.
     *
     * @return array<int,array{date:string,orders:int,revenue:int}>
     */
    public function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $zone = PanelFormat::timezone();
        $days = [];

        for ($day = $from->copy()->timezone($zone)->startOfDay(); $day->lte($to); $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'orders' => 0, 'revenue' => 0];
        }

        $this->counted($from, $to)
            ->toBase()
            ->select(['placed_at', 'total_fils'])
            ->orderBy('id')
            ->lazy()
            ->each(function (object $order) use (&$days, $zone): void {
                $key = Carbon::parse($order->placed_at, config('app.timezone'))
                    ->timezone($zone)
                    ->toDateString();

                if (isset($days[$key])) {
                    $days[$key]['orders']++;
                    $days[$key]['revenue'] += (int) $order->total_fils;
                }
            });

        return array_values($days);
    }

    /**
     * The best sellers by units, with what they brought in.
     *
     * @return array<int,object{product_id:?int,name_ar:?string,name_en:?string,units:int,revenue:int}>
     */
    public function topProducts(CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        $zone = config('app.timezone');

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', ['pending_payment', 'cancelled'])
            ->whereBetween('orders.placed_at', [$from->copy()->timezone($zone), $to->copy()->timezone($zone)])
            ->selectRaw('MAX(order_items.product_id) AS product_id, MAX(order_items.name_ar) AS name_ar, MAX(order_items.name_en) AS name_en, SUM(order_items.quantity) AS units, SUM(order_items.total_fils) AS revenue')
            ->groupByRaw('COALESCE(CAST(order_items.product_id AS TEXT), order_items.name_ar)')
            ->orderByDesc('units')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row) => (object) [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
                'units' => (int) $row->units,
                'revenue' => (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * Orders and revenue split by how they were paid.
     *
     * @return array<string,array{orders:int,revenue:int}> keyed `cod` / `online`
     */
    public function byPaymentMethod(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->grouped($from, $to, 'payment_method');
    }

    /**
     * Orders and revenue split by delivery governorate (as named on the order).
     *
     * @return array<string,array{orders:int,revenue:int}>
     */
    public function byCity(CarbonInterface $from, CarbonInterface $to): array
    {
        $column = app()->getLocale() === 'ar' ? 'city_name_ar' : 'city_name_en';

        return $this->grouped($from, $to, $column);
    }

    /**
     * How many orders in the range are in each status — cancelled and unpaid
     * ones included, since this is the picture of the pipeline, not of sales.
     *
     * @return array<string,int>
     */
    public function byStatus(CarbonInterface $from, CarbonInterface $to): array
    {
        return Order::query()
            ->placedBetween($from, $to)
            ->select('status', DB::raw('COUNT(*) AS orders'))
            ->groupBy('status')
            ->toBase()
            ->pluck('orders', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return Builder<Order> */
    protected function counted(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Order::query()->counted()->placedBetween($from, $to);
    }

    /**
     * @return array<string,array{orders:int,revenue:int}>
     */
    protected function grouped(CarbonInterface $from, CarbonInterface $to, string $column): array
    {
        return $this->counted($from, $to)
            ->select($column, DB::raw('COUNT(*) AS orders'), DB::raw('COALESCE(SUM(total_fils), 0) AS revenue'))
            ->groupBy($column)
            ->orderByDesc('revenue')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [
                (string) ($row->{$column} ?? '') => ['orders' => (int) $row->orders, 'revenue' => (int) $row->revenue],
            ])
            ->all();
    }
}
