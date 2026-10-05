<?php

namespace Tests\Feature\Mail;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\NewOrderAlertMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class OrderEmailsTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase;

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        $order = Order::factory()->create($attributes + [
            'customer_name' => 'Sara Al-Ahmad', 'customer_email' => 'sara@example.com', 'customer_phone' => '+96551234567',
            'subtotal_fils' => 25_000, 'discount_fils' => 2_500, 'delivery_fee_fils' => 2_000, 'total_fils' => 24_500,
            'voucher_code' => 'WELCOME10', 'city_name_ar' => 'حولي', 'city_name_en' => 'Hawalli', 'area_name_ar' => 'السالمية',
            'area_name_en' => 'Salmiya', 'block' => '4', 'street' => 'Street 12', 'building' => '7', 'floor' => '3', 'apartment' => '9',
            'notes' => 'Ring twice', 'admin_notes' => 'INTERNAL: probably a reseller', 'locale' => 'en',
        ]);

        OrderItem::factory()->for($order)->create([
            'name_en' => 'Albustan', 'name_ar' => 'البستان', 'quantity' => 2, 'unit_price_fils' => 12_500, 'total_fils' => 25_000,
            'options' => [['group' => ['ar' => 'الحجم', 'en' => 'Size'], 'values' => [['name' => ['ar' => '50 مل', 'en' => '50 ml'], 'quantity' => 1, 'price_fils' => 0]]]],
        ]);

        return $order->refresh();
    }

    // ------------------------------------------------------- confirmation

    public function test_the_confirmation_says_what_was_bought_what_it_cost_and_where_it_goes_in_english(): void
    {
        $mail = new OrderConfirmationMail($this->order());

        $mail->assertHasSubject('We received your order DS-100001')
            ->assertSeeInHtml('Hello Sara Al-Ahmad,')
            ->assertSeeInHtml('Thank you for your order')
            ->assertSeeInHtml('We have received your order.')
            ->assertSeeInHtml('DS-100001')
            ->assertSeeInHtml('Albustan')
            ->assertSeeInHtml('× 2')
            ->assertSeeInHtml('Size:')
            ->assertSeeInHtml('50 ml')
            ->assertSeeInHtml('25 KWD')
            ->assertSeeInHtml('WELCOME10')
            ->assertSeeInHtml('2.5 KWD')
            ->assertSeeInHtml('24.5 KWD')
            ->assertSeeInHtml('Hawalli')
            ->assertSeeInHtml('Salmiya')
            ->assertSeeInHtml('Street 12')
            ->assertSeeInHtml('Ring twice')
            ->assertSeeInHtml('You will pay 24.5 KWD in cash on delivery.')
            ->assertSeeInHtml('dir="ltr"', false)
            ->assertSeeInHtml('lang="en"', false);
    }

    public function test_the_confirmation_is_written_in_arabic_right_to_left_for_an_arabic_order(): void
    {
        $mail = new OrderConfirmationMail($this->order(['locale' => 'ar', 'customer_name' => 'سارة الأحمد']));

        $mail->assertHasSubject('وصلنا طلبك DS-100001')
            ->assertSeeInHtml('مرحبًا سارة الأحمد،')
            ->assertSeeInHtml('شكرًا لطلبك')
            ->assertSeeInHtml('البستان')
            ->assertSeeInHtml('الحجم:')
            ->assertSeeInHtml('50 مل')
            ->assertSeeInHtml('24.5 د.ك')
            ->assertSeeInHtml('حولي')
            ->assertSeeInHtml('ستدفع 24.5 د.ك نقدًا عند الاستلام.')
            ->assertSeeInHtml('dir="rtl"', false)
            ->assertSeeInHtml('lang="ar"', false)
            ->assertDontSeeInHtml('Albustan');
    }

    public function test_an_order_paid_online_says_so_instead_of_asking_for_cash(): void
    {
        $order = Order::factory()->paid()->create(['customer_email' => 'a@example.com', 'locale' => 'en']);

        (new OrderConfirmationMail($order))
            ->assertSeeInHtml('Your payment was received online.')
            ->assertDontSeeInHtml('in cash on delivery');
    }

    public function test_the_confirmation_never_carries_what_only_staff_should_see(): void
    {
        $mail = new OrderConfirmationMail($this->order());

        $mail->assertDontSeeInHtml('INTERNAL')
            ->assertDontSeeInHtml('reseller')
            ->assertDontSeeInHtml('/panel');
    }

    public function test_a_customer_with_an_account_gets_a_link_to_their_order_and_a_guest_does_not(): void
    {
        $account = $this->order(['customer_id' => Customer::factory()->create()->id]);
        $guest = $this->order(['customer_email' => 'guest@example.com']);

        (new OrderConfirmationMail($account))->assertSeeInHtml(url('/en-KW/account/orders/'.$account->number))->assertSeeInHtml('View your order');
        (new OrderConfirmationMail($guest))->assertDontSeeInHtml('View your order')->assertDontSeeInHtml('/account/orders/');
    }

    public function test_what_a_customer_typed_is_escaped_never_run(): void
    {
        $order = $this->order(['customer_name' => '<script>alert(1)</script>', 'notes' => '<img src=x onerror=alert(2)>', 'street' => '"><b>x</b>']);

        $html = (new OrderConfirmationMail($order))->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_footer_shows_the_shops_published_contact_points(): void
    {
        $this->setting(['contact.phone' => '+96511112222', 'contact.whatsapp' => '96555560002']);

        (new OrderConfirmationMail($this->order()))
            ->assertSeeInHtml('+96511112222')
            ->assertSeeInHtml('https://wa.me/96555560002')
            ->assertSeeInHtml('This email was sent to sara@example.com');
    }

    public function test_free_delivery_and_a_cash_fee_are_shown_as_they_were_charged(): void
    {
        $order = $this->order(['delivery_fee_fils' => 0, 'cod_fee_fils' => 500, 'discount_fils' => 0, 'voucher_code' => null, 'total_fils' => 25_500]);

        (new OrderConfirmationMail($order))
            ->assertSeeInHtml('Free')
            ->assertSeeInHtml('Cash on delivery fee')
            ->assertSeeInHtml('0.5 KWD')
            ->assertDontSeeInHtml('Discount');
    }

    public function test_rendering_an_arabic_mail_leaves_the_current_language_alone(): void
    {
        app()->setLocale('en');

        (new OrderConfirmationMail($this->order(['locale' => 'ar'])))->render();

        $this->assertSame('en', app()->getLocale());
    }

    // ------------------------------------------------------------- status

    public function test_each_notified_status_has_its_own_subject_headline_and_wording_in_both_languages(): void
    {
        $expected = [
            'out_for_delivery' => ['Your order :n is out for delivery', 'Your order is on its way', 'طلبك :n في الطريق إليك'],
            'delivered' => ['Your order :n was delivered', 'Thank you for choosing Dar Alsaffar', 'تم توصيل طلبك :n'],
            'cancelled' => ['Your order :n was cancelled', 'Your order was cancelled', 'تم إلغاء طلبك :n'],
        ];

        foreach ($expected as $status => [$subject, $text, $arabicSubject]) {
            $english = $this->order();
            (new OrderStatusMail($english, OrderStatus::from($status)))
                ->assertHasSubject(str_replace(':n', $english->number, $subject))
                ->assertSeeInHtml($text);

            $arabic = $this->order(['locale' => 'ar']);
            (new OrderStatusMail($arabic, OrderStatus::from($status)))
                ->assertHasSubject(str_replace(':n', $arabic->number, $arabicSubject));
        }
    }

    public function test_a_cancelled_order_that_was_paid_online_asks_the_customer_to_get_in_touch_and_promises_nothing(): void
    {
        $paid = Order::factory()->paid()->create(['customer_email' => 'a@example.com', 'locale' => 'en', 'status' => OrderStatus::Cancelled]);
        $cash = Order::factory()->create(['customer_email' => 'b@example.com', 'locale' => 'en', 'status' => OrderStatus::Cancelled]);

        $paidHtml = (new OrderStatusMail($paid, OrderStatus::Cancelled))->render();

        $this->assertStringContainsString('Please contact us about your payment.', $paidHtml);
        $this->assertStringNotContainsString('refund', mb_strtolower($paidHtml), 'no refund is promised');
        (new OrderStatusMail($cash, OrderStatus::Cancelled))->assertDontSeeInHtml('contact us about your payment');

        // Cash that was collected at the door is not an online payment, whatever the books say.
        $cashCollected = Order::factory()->create(['customer_email' => 'c@example.com', 'locale' => 'en', 'status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Paid]);
        (new OrderStatusMail($cashCollected, OrderStatus::Cancelled))->assertDontSeeInHtml('contact us about your payment');
    }

    public function test_only_the_moves_worth_an_interruption_are_notified(): void
    {
        $this->assertEqualsCanonicalizing(
            [OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Cancelled],
            OrderStatusMail::NOTIFIED
        );
    }

    // ---------------------------------------------------------- staff alert

    public function test_the_staff_alert_is_in_arabic_names_the_customer_and_links_into_the_panel(): void
    {
        $order = $this->order(['locale' => 'en']);

        (new NewOrderAlertMail($order))
            ->assertHasSubject('طلب جديد DS-100001 بقيمة 24.5 د.ك')
            ->assertSeeInHtml('dir="rtl"', false)
            ->assertSeeInHtml('طلب جديد')
            ->assertSeeInHtml('Sara Al-Ahmad')
            ->assertSeeInHtml('+96551234567')
            ->assertSeeInHtml('sara@example.com')
            ->assertSeeInHtml('الدفع عند الاستلام — يُحصَّل 24.5 د.ك نقداً.')
            ->assertSeeInHtml('ملاحظات العميل')
            ->assertDontSeeInHtml('منتجاتك')
            ->assertDontSeeInHtml('ملاحظاتك')
            ->assertSeeInHtml(route('panel.orders.show', $order))
            ->assertDontSeeInHtml('This email was sent to');
    }

    public function test_the_staff_alert_for_a_paid_order_says_nothing_needs_collecting(): void
    {
        $order = Order::factory()->paid()->create();

        (new NewOrderAlertMail($order))->assertSeeInHtml('دُفع إلكترونياً — لا حاجة إلى تحصيل مبلغ.');
    }
}
