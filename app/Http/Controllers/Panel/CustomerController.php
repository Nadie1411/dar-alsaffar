<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Csv;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The people who have accounts. Customers manage their own details from the
 * storefront; the panel only reads them, so there is nothing here to edit.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = $this->term($request);

        return view('panel.customers.index', [
            'customers' => $this->listing($q)->paginate(20)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $zone = PanelFormat::timezone();

        $rows = $this->listing($this->term($request))
            ->reorder('id')
            ->lazy()
            ->map(fn (Customer $customer) => [
                $customer->name,
                $customer->email,
                $customer->phone,
                (int) $customer->orders_count,
                number_format(((int) $customer->orders_sum_total_fils) / Money::FILS, 3, '.', ''),
                $customer->marketing_opt_in ? __('panel.common.yes') : __('panel.common.no'),
                $customer->created_at->copy()->timezone($zone)->format('Y-m-d'),
            ]);

        return Csv::download('customers-'.now($zone)->format('Y-m-d').'.csv', [
            __('panel.common.name'), __('panel.orders.email'), __('panel.orders.phone'), __('panel.customers.orders'),
            __('panel.customers.spent'), __('panel.customers.marketing'), __('panel.customers.joined'),
        ], $rows);
    }

    public function show(Customer $customer): View
    {
        $customer->load(['addresses.city', 'addresses.area']);

        $orders = $customer->orders()->latest('id')->get();
        $counted = $orders->filter(fn (Order $order) => ! in_array($order->status->value, ['pending_payment', 'cancelled'], true));

        return view('panel.customers.show', [
            'customer' => $customer,
            'orders' => $orders,
            'spent' => (int) $counted->sum('total_fils'),
            'countedOrders' => $counted->count(),
            'average' => $counted->isEmpty() ? 0 : intdiv((int) $counted->sum('total_fils') + intdiv($counted->count(), 2), $counted->count()),
            'lastOrder' => $counted->first()?->placed_at,
        ]);
    }

    /**
     * Customers with how many orders they have placed and what those came to —
     * orders that count as sales, so an abandoned payment or a cancellation
     * does not make someone look like a better customer than they are.
     *
     * @return Builder<Customer>
     */
    protected function listing(string $q): Builder
    {
        return Customer::query()
            ->withCount(['orders' => fn (Builder $query) => $query->counted()])
            ->withSum(['orders' => fn (Builder $query) => $query->counted()], 'total_fils')
            ->when($q !== '', function (Builder $query) use ($q): void {
                $term = '%'.$q.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->latest('id');
    }

    protected function term(Request $request): string
    {
        return trim(str_replace(['%', '_', '\\'], ' ', (string) $request->query('q', '')));
    }
}
