<?php

namespace Tests\Feature\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Mail\NewOrderAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusMail;
use App\Models\Order;
use App\Models\User;
use App\Services\Store\OrderLifecycle;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase;

    // ------------------------------------------------------------ placed

    public function test_a_placed_order_is_confirmed_to_the_customer_who_gave_an_address(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['customer_email' => 'sara@example.com']);

        OrderPlaced::dispatch($order);

        Mail::assertQueued(OrderConfirmationMail::class, fn (OrderConfirmationMail $mail) => $mail->hasTo('sara@example.com') && $mail->order->is($order));
        Mail::assertQueuedCount(1);
    }

    public function test_a_customer_who_gave_no_address_is_not_emailed(): void
    {
        Mail::fake();

        OrderPlaced::dispatch(Order::factory()->create(['customer_email' => null]));
        OrderPlaced::dispatch(Order::factory()->create(['customer_email' => '']));

        Mail::assertNothingQueued();
    }

    public function test_the_shop_is_alerted_only_when_it_has_said_where_to_send_one(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['customer_email' => null]);

        OrderPlaced::dispatch($order);
        Mail::assertNothingQueued();

        $this->setting(['orders.notify_email' => ' shop@example.org ']);
        OrderPlaced::dispatch($order);

        Mail::assertQueued(NewOrderAlertMail::class, fn (NewOrderAlertMail $mail) => $mail->hasTo('shop@example.org'));
        Mail::assertQueuedCount(1);
    }

    public function test_both_go_out_when_both_are_wanted(): void
    {
        Mail::fake();
        $this->setting(['orders.notify_email' => 'shop@example.org']);

        OrderPlaced::dispatch(Order::factory()->create(['customer_email' => 'sara@example.com']));

        Mail::assertQueued(OrderConfirmationMail::class);
        Mail::assertQueued(NewOrderAlertMail::class);
    }

    public function test_the_mails_are_sent_through_the_queue_not_while_the_shopper_waits(): void
    {
        Mail::fake();

        OrderPlaced::dispatch(Order::factory()->create(['customer_email' => 'sara@example.com']));

        Mail::assertQueued(OrderConfirmationMail::class);
        Mail::assertNotSent(OrderConfirmationMail::class);
    }

    public function test_an_email_that_cannot_be_sent_never_undoes_the_order(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->twice()->with(Mockery::type(RuntimeException::class));
        $this->app->instance(ExceptionHandler::class, $handler);
        Mail::shouldReceive('to')->twice()->andThrow(new RuntimeException('the mail server is down'));
        $this->setting(['orders.notify_email' => 'shop@example.org']);
        $order = Order::factory()->create(['customer_email' => 'sara@example.com']);

        OrderPlaced::dispatch($order);

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    // ------------------------------------------------------------ status

    /**
     * @return array<string,array{0:OrderStatus,1:bool}>
     */
    public static function staffMoves(): array
    {
        return [
            'out for delivery' => [OrderStatus::OutForDelivery, true],
            'delivered' => [OrderStatus::Delivered, true],
            'cancelled' => [OrderStatus::Cancelled, true],
            'accepted' => [OrderStatus::Accepted, false],
            'preparing' => [OrderStatus::Preparing, false],
            'back to new' => [OrderStatus::New, false],
        ];
    }

    #[DataProvider('staffMoves')]
    public function test_a_customer_is_told_about_the_moves_worth_telling(OrderStatus $to, bool $emailed): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->create(['customer_email' => 'sara@example.com', 'status' => $to === OrderStatus::New ? OrderStatus::Accepted : OrderStatus::New]);

        $this->assertTrue(app(OrderLifecycle::class)->moveByStaff($order, $to, $staff));

        if ($emailed) {
            Mail::assertQueued(OrderStatusMail::class, fn (OrderStatusMail $mail) => $mail->hasTo('sara@example.com') && $mail->status === $to);
        } else {
            Mail::assertNothingQueued();
        }
    }

    public function test_a_cancellation_the_system_makes_is_not_announced(): void
    {
        Mail::fake();
        $order = Order::factory()->online()->pendingPayment()->create(['customer_email' => 'sara@example.com']);

        app(OrderLifecycle::class)->moveTo($order, OrderStatus::Cancelled, null, 'Payment not completed in time');

        Mail::assertNothingQueued();
    }

    public function test_a_customer_with_no_address_is_not_emailed_about_a_move(): void
    {
        Mail::fake();
        $order = Order::factory()->create(['customer_email' => null]);

        app(OrderLifecycle::class)->moveByStaff($order, OrderStatus::Delivered, User::factory()->staff()->create());

        Mail::assertNothingQueued();
    }

    public function test_a_move_that_changes_nothing_or_is_refused_announces_nothing(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $staff = User::factory()->staff()->create();
        $delivered = Order::factory()->create(['status' => OrderStatus::Delivered]);
        $open = Order::factory()->create();

        $this->assertFalse(app(OrderLifecycle::class)->moveByStaff($delivered, OrderStatus::Preparing, $staff));
        app(OrderLifecycle::class)->moveTo($open, OrderStatus::New);

        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_a_real_move_announces_what_changed_and_who_made_it(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $staff = User::factory()->staff()->create();
        $order = Order::factory()->create();

        app(OrderLifecycle::class)->moveByStaff($order, OrderStatus::Accepted, $staff);

        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event) => $event->order->is($order)
            && $event->from === OrderStatus::New && $event->to === OrderStatus::Accepted && $event->staffId === $staff->id);
    }

    public function test_an_email_that_cannot_be_sent_never_undoes_a_status_move(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('the mail server is down'));
        $order = Order::factory()->create(['customer_email' => 'sara@example.com']);

        $this->assertTrue(app(OrderLifecycle::class)->moveByStaff($order, OrderStatus::Delivered, User::factory()->staff()->create()));

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    // ------------------------------------------------------- end to end

    public function test_moving_an_order_in_the_panel_emails_the_customer(): void
    {
        Mail::fake();
        config(['store.backend' => 'local']);
        $staff = User::factory()->staff()->create(['locale' => 'en']);
        $order = Order::factory()->create(['customer_email' => 'sara@example.com', 'locale' => 'ar']);

        $this->actingAs($staff, 'staff')
            ->post(route('panel.orders.status', $order), ['status' => 'out_for_delivery'])
            ->assertSessionHas('status');

        Mail::assertQueued(OrderStatusMail::class, fn (OrderStatusMail $mail) => $mail->hasTo('sara@example.com') && $mail->locale === 'ar');
    }
}
