<?php

namespace App\Services\Store;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order from one status to the next, and keeps the books straight
 * while doing it: every move is recorded, and cancelling an order puts its
 * stock back on the shelf.
 */
class OrderLifecycle
{
    public function moveTo(Order $order, OrderStatus $to, ?User $by = null, ?string $note = null): void
    {
        $from = DB::transaction(fn (): ?OrderStatus => $this->apply($order, $to, $by, $note));

        $this->announce($order, $from, $to, $by);
    }

    /**
     * A move made by a member of staff, held to the rules for what staff may
     * do: judged against the order as it is now, not as their page showed it
     * a minute ago, so a colleague's cancellation cannot be undone by accident.
     */
    public function moveByStaff(Order $order, OrderStatus $to, User $by, ?string $note = null): bool
    {
        $from = DB::transaction(function () use ($order, $to, $by, $note): OrderStatus|false {
            $order->refresh();

            return $order->status->canMoveTo($to) ? ($this->apply($order, $to, $by, $note) ?? false) : false;
        });

        if ($from === false) {
            return false;
        }

        $this->announce($order, $from, $to, $by);

        return true;
    }

    /**
     * Makes the move and records it, inside the caller's transaction.
     *
     * @return OrderStatus|null the status the order had before, or null when it was already in the one asked for
     */
    protected function apply(Order $order, OrderStatus $to, ?User $by, ?string $note): ?OrderStatus
    {
        $order->refresh();
        $from = $order->status;

        if ($from === $to) {
            return null;
        }

        $order->status = $to;

        if ($to === OrderStatus::Delivered) {
            $order->delivered_at = now();
        }

        if ($to === OrderStatus::Cancelled) {
            $order->cancelled_at = now();
            $this->restoreStock($order);
        }

        $order->save();

        OrderStatusChange::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        return $from;
    }

    /** Only once a move is committed does anyone else get to hear of it. */
    protected function announce(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $by): void
    {
        if ($from !== null) {
            OrderStatusChanged::dispatch($order, $from, $to, $by?->id);
        }
    }

    /**
     * Stock was taken when the order was placed; cancelling hands it back.
     * Only ever once: an order that is already cancelled never gets here.
     */
    protected function restoreStock(Order $order): void
    {
        $order->items()->whereNotNull('product_id')->get()->each(function (OrderItem $item): void {
            Product::query()
                ->whereKey($item->product_id)
                ->where('track_stock', true)
                ->increment('stock', $item->quantity);
        });
    }
}
