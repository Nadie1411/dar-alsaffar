<?php

namespace App\Services\Store;

use App\Contracts\Store\Cart;
use App\Contracts\Store\Orders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\ServiceAddon;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\PaymentMethods;
use App\Services\Store\Payments\PaymentService;
use App\Services\Store\Pricing\PricedCart;
use App\Services\Store\Pricing\PricedLine;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use App\Support\Media;
use App\Support\Money;
use App\Support\Nav;
use App\Support\Phone;
use App\Support\Shopper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Checkout and order history, on this application's own database.
 */
class LocalOrders implements Orders
{
    public function __construct(
        protected Cart $cart,
        protected PricingEngine $engine,
        protected PaymentService $payments,
        protected PaymentMethods $methods,
        protected OrderLifecycle $lifecycle,
    ) {}

    /** @return array<int,array{id:string,name:string,areas:array<int,array{id:string,name:string}>}> */
    public function deliveryLocations(): array
    {
        return DeliveryCity::query()
            ->active()
            ->with(['areas' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (DeliveryCity $city) => [
                'id' => (string) $city->id,
                'name' => $city->localized('name'),
                'areas' => $city->areas
                    ->map(fn ($area) => ['id' => (string) $area->id, 'name' => $area->localized('name')])
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $city) => $city['areas'] !== [])
            ->values()
            ->all();
    }

    /** @return array<int,array{id:string,name:string,description:string,price:float,image:?string}> */
    public function serviceAddons(): array
    {
        return ServiceAddon::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceAddon $addon) => [
                'id' => (string) $addon->id,
                'name' => $addon->localized('name'),
                'description' => $addon->localized('description'),
                'price' => (float) Money::fromFils($addon->price_fils),
                'image' => Media::url($addon->image),
            ])
            ->all();
    }

    public function addonsEnabled(): bool
    {
        return ServiceAddon::query()->active()->exists();
    }

    public function addonsRequired(): bool
    {
        return false;
    }

    /**
     * The online ways to pay, when a payment gateway is connected and switched
     * on. Empty otherwise, which leaves cash on delivery as the only choice.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paymentMethods(): array
    {
        return $this->methods->enabled();
    }

    public function e164(string $phone): string
    {
        return Phone::e164($phone);
    }

    // ---------------------------------------------------------------- placing

    /**
     * @param  array<string,mixed>  $input  validated checkout input
     * @return array{ok:bool,kind:string,paymentUrl:?string,orderId:?string,message:?string,clearCart:bool}
     */
    public function place(array $input): array
    {
        $items = $this->cart->items();

        if ($items === []) {
            return $this->failure(__('storefront.cart.empty'));
        }

        $cashOnDelivery = ($input['payment'] ?? null) === Order::PAYMENT_COD;

        // Refused before anything is reserved, not after an order exists.
        if (! $cashOnDelivery && ! $this->methods->offers((string) ($input['paymentMethod'] ?? ''))) {
            return $this->failure(__('storefront.checkout.paymentUnavailable'));
        }

        $phone = Phone::e164($input['phone']);

        $context = new PricingContext(
            areaId: (int) $input['area'],
            voucherCode: $input['voucher'] ?? null,
            addonIds: array_map('intval', $input['addons'] ?? []),
            cashOnDelivery: $cashOnDelivery,
            customerId: Auth::guard(Shopper::GUARD)->id(),
            phone: $phone,
        );

        try {
            $order = DB::transaction(function () use ($items, $context, $input, $phone, $cashOnDelivery): Order {
                // Priced again here, inside the transaction, from what the
                // basket holds now: the figures the shopper saw a minute ago
                // are not what is charged — these are.
                $priced = $this->engine->price($items, $context);

                if (! $priced->canPlaceOrder()) {
                    throw new RuntimeException($priced->allProblems()[0] ?? $priced->voucher->message ?? __('storefront.checkout.failed'));
                }

                if ($cashOnDelivery && ! $priced->cashOnDeliveryAvailable) {
                    throw new RuntimeException(__('storefront.checkout.failed'));
                }

                $this->takeStock($priced);

                return $this->createOrder($priced, $input, $phone, $cashOnDelivery);
            });
        } catch (RuntimeException $e) {
            return $this->failure($e->getMessage());
        }

        if ($cashOnDelivery) {
            OrderPlaced::dispatch($order);

            return [
                'ok' => true, 'kind' => 'confirmed', 'paymentUrl' => null,
                'orderId' => $order->number, 'message' => null, 'clearCart' => true,
            ];
        }

        return $this->takeOnlinePayment($order, (string) ($input['paymentMethod'] ?? ''));
    }

    /**
     * Send an order that is to be paid online to MyFatoorah's payment page.
     * The basket is kept until the payment is confirmed, so a shopper who
     * closes the page can come back and pay without starting again.
     *
     * @return array{ok:bool,kind:string,paymentUrl:?string,orderId:?string,message:?string,clearCart:bool}
     */
    protected function takeOnlinePayment(Order $order, string $method): array
    {
        // A voucher can bring an order to nothing; there is then nothing to pay.
        if ($order->total_fils === 0) {
            $order->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);
            $this->lifecycle->moveTo($order, OrderStatus::New, null, 'Nothing to pay');
            OrderPlaced::dispatch($order->refresh());

            return [
                'ok' => true, 'kind' => 'confirmed', 'paymentUrl' => null,
                'orderId' => $order->number, 'message' => null, 'clearCart' => true,
            ];
        }

        try {
            $payment = $this->payments->start($order, $method === PaymentMethods::ANY ? null : $method);
        } catch (MyFatoorahException) {
            // Without a payment page the order cannot be paid: release it, and
            // let the shopper try again or choose cash on delivery.
            $this->lifecycle->moveTo($order, OrderStatus::Cancelled, null, 'The payment page could not be created');

            return $this->failure(__('storefront.checkout.paymentUnavailable'));
        }

        session()->put('payment.order', $order->number);

        return [
            'ok' => true, 'kind' => 'redirect', 'paymentUrl' => $payment->mf_payment_url,
            'orderId' => $order->number, 'message' => null, 'clearCart' => false,
        ];
    }

    /**
     * Take what the order needs off the shelf. Each product is decremented
     * with a conditional update, so two shoppers racing for the last bottle
     * cannot both get it: whoever loses finds no row to update.
     */
    protected function takeStock(PricedCart $priced): void
    {
        $needed = [];

        foreach ($priced->lines as $line) {
            if ($line->product !== null && $line->product->track_stock) {
                $needed[$line->product->id] = ($needed[$line->product->id] ?? 0) + $line->quantity;
            }
        }

        foreach ($needed as $productId => $quantity) {
            $taken = Product::query()
                ->whereKey($productId)
                ->where('stock', '>=', $quantity)
                ->decrement('stock', $quantity);

            if ($taken === 0) {
                throw new RuntimeException(__('storefront.cart.lineOutOfStock'));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $input
     */
    protected function createOrder(PricedCart $priced, array $input, string $phone, bool $cashOnDelivery): Order
    {
        $area = $priced->area;
        $city = $area->city;
        $customer = Auth::guard(Shopper::GUARD)->user();
        $status = $cashOnDelivery ? OrderStatus::New : OrderStatus::PendingPayment;

        $order = Order::query()->create([
            'customer_id' => $customer?->getKey(),
            'customer_name' => trim($input['fullName']),
            'customer_email' => ($input['email'] ?? null) ?: $customer?->email,
            'customer_phone' => $phone,
            'status' => $status,
            'payment_method' => $cashOnDelivery ? Order::PAYMENT_COD : Order::PAYMENT_ONLINE,
            'payment_status' => PaymentStatus::Unpaid,
            'currency' => 'KWD',
            'subtotal_fils' => $priced->subTotalFils,
            'discount_fils' => $priced->discountFils,
            'delivery_fee_fils' => $priced->deliveryFeeFils,
            'addons_total_fils' => $priced->addonsTotalFils,
            'cod_fee_fils' => $priced->codFeeFils,
            'total_fils' => $priced->totalFils(),
            'voucher_code' => $priced->voucher->accepted ? $priced->voucher->voucher?->code : null,
            'delivery_city_id' => $city->id,
            'delivery_area_id' => $area->id,
            'city_name_ar' => $city->name_ar,
            'city_name_en' => $city->name_en,
            'area_name_ar' => $area->name_ar,
            'area_name_en' => $area->name_en,
            'block' => $input['block'] ?? null,
            'street' => $input['street'] ?? null,
            'avenue' => $input['avenue'] ?? null,
            'building' => $input['building'] ?? null,
            'floor' => $input['floor'] ?? null,
            'apartment' => $input['apartment'] ?? null,
            'addons' => array_map(fn (ServiceAddon $addon) => [
                'id' => $addon->id,
                'name_ar' => $addon->name_ar,
                'name_en' => $addon->name_en,
                'price_fils' => $addon->price_fils,
            ], $priced->addons) ?: null,
            'notes' => $input['notes'] ?? null,
            'locale' => app()->getLocale() === 'en' ? 'en' : 'ar',
            'placed_at' => now(),
        ]);

        foreach ($priced->lines as $line) {
            $this->createItem($order, $line);
        }

        OrderStatusChange::query()->create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $status,
        ]);

        if ($customer instanceof Customer) {
            $this->rememberAddress($customer, $order);
        }

        return $order;
    }

    protected function createItem(Order $order, PricedLine $line): void
    {
        $product = $line->product;

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'sku' => $product->sku,
            'image' => $product->main_image,
            'quantity' => $line->quantity,
            'unit_price_fils' => $line->unitPriceFils,
            'total_fils' => $line->totalFils(),
            'options' => $this->optionSnapshot($line) ?: null,
            'is_gift' => false,
        ]);
    }

    /**
     * What was chosen, as names and prices, so the order still reads correctly
     * after the product's options are edited or removed.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function optionSnapshot(PricedLine $line): array
    {
        return array_map(fn (array $selection) => [
            'group' => $selection['option']['name'],
            'values' => array_map(fn (array $value) => [
                'name' => $value['value']['name'],
                'quantity' => $value['quantity'],
                'price_fils' => Money::toFils($value['unitPrice']),
            ], $selection['values']),
        ], $line->options);
    }

    /** A signed-in customer's delivery address is kept for next time. */
    protected function rememberAddress(Customer $customer, Order $order): void
    {
        CustomerAddress::query()->firstOrCreate([
            'customer_id' => $customer->id,
            'delivery_city_id' => $order->delivery_city_id,
            'delivery_area_id' => $order->delivery_area_id,
            'block' => $order->block,
            'street' => $order->street,
            'avenue' => $order->avenue,
            'building' => $order->building,
            'floor' => $order->floor,
            'apartment' => $order->apartment,
        ]);
    }

    /**
     * @return array{ok:bool,kind:string,paymentUrl:?string,orderId:?string,message:?string,clearCart:bool}
     */
    protected function failure(?string $message): array
    {
        return [
            'ok' => false, 'kind' => 'failed', 'paymentUrl' => null, 'orderId' => null,
            'message' => $message, 'clearCart' => false,
        ];
    }

    // ---------------------------------------------------------------- history

    /** @return array<int,array<string,mixed>> */
    public function myOrders(): array
    {
        $customer = Auth::guard(Shopper::GUARD)->user();

        if ($customer === null) {
            return [];
        }

        return Order::query()
            ->whereBelongsTo($customer)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Order $order) => $this->present($order))
            ->all();
    }

    /** @return array<string,mixed>|null */
    public function findOrder(string $id): ?array
    {
        $customer = Auth::guard(Shopper::GUARD)->user();

        if ($customer === null) {
            return null;
        }

        $order = Order::query()
            ->whereBelongsTo($customer)
            ->with('items')
            ->where(fn ($query) => $query->where('number', $id)->orWhere('id', ctype_digit($id) ? (int) $id : 0))
            ->first();

        return $order === null ? null : $this->present($order, withItems: true);
    }

    /**
     * An order as the account pages read it.
     *
     * @return array<string,mixed>
     */
    protected function present(Order $order, bool $withItems = false): array
    {
        $dinars = fn (int $fils) => Money::fromFils($fils);

        $data = [
            '_id' => $order->number,
            'orderNumber' => $order->number,
            'status' => $order->status->label(),
            'statusTone' => $order->status->tone(),
            'paymentStatus' => $order->payment_status->label(),
            'createdAt' => ($order->placed_at ?? $order->created_at)->toIso8601String(),
            'symbol' => Money::KWD,
            'subTotal' => $dinars($order->subtotal_fils),
            'discount' => $dinars($order->discount_fils),
            'deliveryFees' => $dinars($order->delivery_fee_fils),
            'serviceAddonsTotal' => $dinars($order->addons_total_fils),
            'cashOnDeliveryFee' => $dinars($order->cod_fee_fils),
            'total' => $dinars($order->total_fils),
            'address' => $order->localizedAddress(),
            'isCashOnDelivery' => $order->isCashOnDelivery(),
            // An order the shopper started paying for but did not finish.
            'payUrl' => $order->status === OrderStatus::PendingPayment ? Nav::url('checkout/pay/'.$order->number) : null,
        ];

        if ($withItems) {
            $data['items'] = $order->items->map(fn (OrderItem $item) => [
                'productId' => [
                    'mainImage' => Media::url($item->image),
                    'title' => $item->localizedMap('name'),
                ],
                'quantity' => $item->quantity,
                'totalPrice' => $dinars($item->total_fils),
                'totalPriceAfterDiscount' => $dinars($item->total_fils),
                'symbol' => Money::KWD,
            ])->all();
        }

        return $data;
    }
}
