<?php

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_the_moves_staff_may_make_from_each_status(): void
    {
        $everythingElse = fn (OrderStatus $from) => array_values(array_filter(
            [OrderStatus::New, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Cancelled],
            fn (OrderStatus $to) => $to !== $from
        ));

        $this->assertSame([OrderStatus::Cancelled], OrderStatus::PendingPayment->transitions());
        $this->assertSame($everythingElse(OrderStatus::New), OrderStatus::New->transitions());
        $this->assertSame($everythingElse(OrderStatus::Accepted), OrderStatus::Accepted->transitions());
        $this->assertSame($everythingElse(OrderStatus::Preparing), OrderStatus::Preparing->transitions());
        $this->assertSame($everythingElse(OrderStatus::OutForDelivery), OrderStatus::OutForDelivery->transitions());
        $this->assertSame([], OrderStatus::Delivered->transitions());
        $this->assertSame([], OrderStatus::Cancelled->transitions());
    }

    public function test_nobody_can_move_an_order_into_pending_payment_or_to_the_status_it_is_already_in(): void
    {
        foreach (OrderStatus::cases() as $from) {
            $this->assertNotContains(OrderStatus::PendingPayment, $from->transitions(), $from->value);
            $this->assertNotContains($from, $from->transitions(), $from->value);
            $this->assertFalse($from->canMoveTo($from));
        }
    }

    public function test_only_the_two_final_statuses_are_dead_ends(): void
    {
        $deadEnds = array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->transitions() === []);

        $this->assertEqualsCanonicalizing([OrderStatus::Delivered, OrderStatus::Cancelled], array_values($deadEnds));
    }

    public function test_an_order_waiting_for_payment_cannot_be_prepared_or_delivered_by_hand(): void
    {
        $this->assertFalse(OrderStatus::PendingPayment->canMoveTo(OrderStatus::New));
        $this->assertFalse(OrderStatus::PendingPayment->canMoveTo(OrderStatus::Preparing));
        $this->assertFalse(OrderStatus::PendingPayment->canMoveTo(OrderStatus::Delivered));
        $this->assertTrue(OrderStatus::PendingPayment->canMoveTo(OrderStatus::Cancelled));
    }
}
