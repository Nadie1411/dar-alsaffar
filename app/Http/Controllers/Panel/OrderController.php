<?php

namespace App\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Store\ActivityLogger;
use App\Services\Store\OrderLifecycle;
use App\Support\Csv;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    protected const PER_PAGE = 20;

    public function __construct(
        protected OrderLifecycle $lifecycle,
        protected ActivityLogger $log,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        // The tab counts follow the other filters, so each tab says how many
        // orders it would show with the search and dates as they are.
        $base = $this->filtered($filters);
        $counts = (clone $base)->toBase()
            ->selectRaw('status, COUNT(*) AS orders')
            ->groupBy('status')
            ->pluck('orders', 'status');

        $orders = (clone $base)
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('panel.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'counts' => $counts,
            'allCount' => $counts->sum(),
            'statuses' => OrderStatus::cases(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $zone = PanelFormat::timezone();

        $rows = $this->filtered($filters)
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->oldest('id')
            ->lazy()
            ->map(fn (Order $order) => [
                $order->number,
                ($order->placed_at ?? $order->created_at)->copy()->timezone($zone)->format('Y-m-d H:i'),
                $order->status->panelLabel(),
                __('panel.orders.methods.'.$order->payment_method),
                $order->payment_status->label(),
                $order->customer_name,
                $order->customer_phone,
                $order->customer_email,
                $order->city_name_ar ?? $order->city_name_en,
                $order->area_name_ar ?? $order->area_name_en,
                $order->localizedAddress('ar'),
                $this->dinars($order->subtotal_fils),
                $this->dinars($order->discount_fils),
                $this->dinars($order->delivery_fee_fils),
                $this->dinars($order->addons_total_fils),
                $this->dinars($order->cod_fee_fils),
                $this->dinars($order->total_fils),
                $order->voucher_code,
                $order->notes,
            ]);

        return Csv::download('orders-'.now($zone)->format('Y-m-d').'.csv', [
            __('panel.orders.number'), __('panel.orders.placed'), __('panel.common.status'),
            __('panel.orders.payment'), __('panel.orders.paymentStatus'), __('panel.orders.customer'),
            __('panel.orders.phone'), __('panel.orders.email'), __('panel.orders.city'), __('panel.orders.area'),
            __('panel.orders.address'), __('panel.orders.subtotal'), __('panel.orders.discount'),
            __('panel.orders.delivery'), __('panel.orders.addons'), __('panel.orders.codFee'),
            __('panel.common.total'), __('panel.orders.voucher'), __('panel.orders.customerNotes'),
        ], $rows);
    }

    public function show(Order $order): View
    {
        $order->load(['items', 'payments', 'statusChanges.staff', 'customer']);

        return view('panel.orders.show', [
            'order' => $order,
            'transitions' => $order->status->transitions(),
            'customerOrders' => $order->customer_id === null
                ? 0
                : Order::query()->where('customer_id', $order->customer_id)->count(),
        ]);
    }

    public function print(Order $order): View
    {
        return view('panel.orders.print', ['order' => $order->load('items')]);
    }

    public function status(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $staff = $request->user('staff');
        $from = $order->status;
        $to = OrderStatus::from($data['status']);

        if (! $this->lifecycle->moveByStaff($order, $to, $staff, $data['note'] ?? null)) {
            return back()->with('warning', __('panel.orders.cannotMove'));
        }

        $this->log->record($staff, 'order.status_changed', $order, ['from' => $from->value, 'to' => $to->value]);

        return back()->with('status', __('panel.orders.moved', ['status' => $to->panelLabel()]));
    }

    public function note(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:2000']]);

        $order->update(['admin_notes' => trim((string) ($data['admin_notes'] ?? '')) ?: null]);

        $this->log->record($request->user('staff'), 'order.note_saved', $order);

        return back()->with('status', __('panel.orders.noteSaved'));
    }

    /** Cash handed over at the door: the shop records it, there is no gateway to ask. */
    public function markPaid(Request $request, Order $order): RedirectResponse
    {
        $order->refresh();

        if (! $order->isCashOnDelivery()
            || $order->payment_status !== PaymentStatus::Unpaid
            || $order->status === OrderStatus::Cancelled) {
            return back()->with('warning', __('panel.orders.cannotMarkPaid'));
        }

        $order->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->log->record($request->user('staff'), 'order.marked_paid', $order);

        return back()->with('status', __('panel.orders.markedPaid'));
    }

    /**
     * The refund itself is made in MyFatoorah's own dashboard; this records
     * that it has been, so the order stops reading as paid.
     */
    public function markRefunded(Request $request, Order $order): RedirectResponse
    {
        $order->refresh();

        if ($order->payment_status !== PaymentStatus::Paid || $order->status !== OrderStatus::Cancelled) {
            return back()->with('warning', __('panel.orders.cannotMarkRefunded'));
        }

        $order->update(['payment_status' => PaymentStatus::Refunded]);

        $this->log->record($request->user('staff'), 'order.marked_refunded', $order);

        return back()->with('status', __('panel.orders.markedRefunded'));
    }

    /**
     * What the list was asked for, with anything unrecognised ignored rather
     * than rejected: a stale bookmark should still open a list.
     *
     * @return array{status:string,q:string,payment:string,from:string,to:string}
     */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $payment = (string) $request->query('payment', '');

        return [
            'status' => OrderStatus::tryFrom($status) === null ? 'all' : $status,
            'q' => trim(str_replace(['%', '_', '\\'], ' ', (string) $request->query('q', ''))),
            'payment' => in_array($payment, [Order::PAYMENT_COD, Order::PAYMENT_ONLINE], true) ? $payment : '',
            'from' => $this->date((string) $request->query('from', '')),
            'to' => $this->date((string) $request->query('to', '')),
        ];
    }

    /**
     * Everything but the status tab: search, payment method and dates.
     *
     * @param  array{status:string,q:string,payment:string,from:string,to:string}  $filters
     * @return Builder<Order>
     */
    protected function filtered(array $filters): Builder
    {
        $zone = PanelFormat::timezone();

        return Order::query()
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';

                $query->where(fn (Builder $query) => $query
                    ->where('number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('customer_phone', 'like', $term)
                    ->orWhere('customer_email', 'like', $term));
            })
            ->when($filters['payment'] !== '', fn (Builder $query) => $query->where('payment_method', $filters['payment']))
            ->when($filters['from'] !== '', fn (Builder $query) => $query->where(
                'placed_at', '>=', Carbon::parse($filters['from'], $zone)->startOfDay()->timezone(config('app.timezone'))
            ))
            ->when($filters['to'] !== '', fn (Builder $query) => $query->where(
                'placed_at', '<=', Carbon::parse($filters['to'], $zone)->endOfDay()->timezone(config('app.timezone'))
            ));
    }

    protected function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : '';
    }

    protected function dinars(int $fils): string
    {
        return number_format($fils / Money::FILS, 3, '.', '');
    }
}
