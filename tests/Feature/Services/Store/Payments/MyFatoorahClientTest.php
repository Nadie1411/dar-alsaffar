<?php

namespace Tests\Feature\Services\Store\Payments;

use App\Services\Store\Payments\MyFatoorahClient;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\MyFatoorahUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

class MyFatoorahClientTest extends TestCase
{
    use FakesMyFatoorah;

    private function client(): MyFatoorahClient
    {
        return $this->app->make(MyFatoorahClient::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMyFatoorah();
    }

    public function test_it_is_configured_only_with_the_switch_on_and_a_key_and_an_address(): void
    {
        $this->assertTrue($this->client()->isConfigured());

        config(['myfatoorah.enabled' => false]);
        $this->assertFalse($this->client()->isConfigured());

        config(['myfatoorah.enabled' => true, 'myfatoorah.api_key' => null]);
        $this->assertFalse($this->client()->isConfigured());

        config(['myfatoorah.api_key' => 'k', 'myfatoorah.api_url' => '']);
        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_nothing_is_sent_while_it_is_not_configured(): void
    {
        config(['myfatoorah.api_key' => null]);
        Http::preventStrayRequests();

        $this->expectException(MyFatoorahException::class);
        $this->expectExceptionMessage('Online payment is not configured.');

        $this->client()->createPayment(['Order' => ['Amount' => 1]]);
    }

    public function test_creating_a_payment_posts_to_v3_with_the_key_as_a_bearer_token_and_returns_the_invoice_and_page(): void
    {
        $this->fakeMyFatoorah();

        $created = $this->client()->createPayment(['Order' => ['Amount' => 40, 'Currency' => 'KWD']]);

        $this->assertSame(['invoiceId' => '6148108', 'paymentUrl' => 'https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf'], $created);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://apitest.myfatoorah.com/v3/payments'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::MF_KEY)
            && $request->data() === ['Order' => ['Amount' => 40, 'Currency' => 'KWD']]);
    }

    public function test_creating_a_payment_is_never_retried_because_a_repeat_could_open_a_second_invoice(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['Message' => 'oops'], 502)]);

        try {
            $this->client()->createPayment(['Order' => ['Amount' => 40]]);
            $this->fail('A server error should have raised MyFatoorahUnavailable.');
        } catch (MyFatoorahUnavailable) {
            Http::assertSentCount(1);
        }
    }

    public function test_a_refused_creation_reports_the_reason_my_fatoorah_gave(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response([
            'IsSuccess' => false,
            'Message' => 'Invalid data',
            'ValidationErrors' => [['Name' => 'Order.Amount', 'Error' => 'must be greater than 0']],
            'Data' => null,
        ], 400)]);

        $this->expectException(MyFatoorahException::class);
        $this->expectExceptionMessage('Order.Amount: must be greater than 0');

        $this->client()->createPayment(['Order' => ['Amount' => 0]]);
    }

    public function test_a_connection_failure_is_unavailable_and_so_is_a_server_error(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::failedConnection()]);

        $this->expectException(MyFatoorahUnavailable::class);

        $this->client()->createPayment(['Order' => ['Amount' => 1]]);
    }

    /**
     * @return array<string, array{array<string,mixed>}>
     */
    public static function unusableInvoices(): array
    {
        return [
            'no payment page' => [['IsSuccess' => true, 'Data' => ['InvoiceId' => '1', 'PaymentURL' => null]]],
            'a page that is not https' => [['IsSuccess' => true, 'Data' => ['InvoiceId' => '1', 'PaymentURL' => 'http://evil.example/pay']]],
            'no invoice id' => [['IsSuccess' => true, 'Data' => ['PaymentURL' => 'https://demo.myfatoorah.com/x']]],
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     */
    #[DataProvider('unusableInvoices')]
    public function test_an_answer_without_a_usable_invoice_and_secure_page_is_refused(array $body): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response($body, 201)]);

        $this->expectException(MyFatoorahException::class);

        $this->client()->createPayment(['Order' => ['Amount' => 1]]);
    }

    public function test_the_key_never_appears_in_what_an_exception_says(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401)]);

        try {
            $this->client()->createPayment(['Order' => ['Amount' => 1]]);
            $this->fail('Should have been refused.');
        } catch (MyFatoorahException $e) {
            $this->assertStringNotContainsString(self::MF_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::MF_KEY, (string) $e);
        }
    }

    public function test_payment_details_come_back_as_the_data_object(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/07076389491322460173' => Http::response($this->paymentDetails('REF-1'))]);

        $details = $this->client()->paymentDetails('07076389491322460173');

        $this->assertSame('PAID', $details['Invoice']['Status']);
        $this->assertSame('REF-1', $details['Invoice']['ExternalIdentifier']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer '.self::MF_KEY));
    }

    public function test_a_payment_my_fatoorah_does_not_know_is_null_not_an_error(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::response(['IsSuccess' => false, 'Message' => 'Invalid data', 'Data' => null], 400)]);

        $this->assertNull($this->client()->paymentDetails('0000000000'));
    }

    public function test_the_payment_id_is_encoded_into_the_address(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::response(['IsSuccess' => false], 400)]);

        $this->client()->paymentDetails('../../v3/refunds?x=1');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://apitest.myfatoorah.com/v3/payments/..%2F..%2Fv3%2Frefunds%3Fx%3D1');
    }

    public function test_looking_up_details_is_retried_through_a_brief_outage_because_it_is_safe_to_repeat(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::MF.'/v3/payments/abc123456' => Http::sequence()
            ->push('down', 503)
            ->push($this->paymentDetails('REF-1'), 200)]);

        $details = $this->client()->paymentDetails('abc123456');

        $this->assertSame('PAID', $details['Invoice']['Status']);
        Http::assertSentCount(2);
    }

    public function test_a_key_that_is_not_allowed_to_read_payments_is_refused_not_unavailable(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/payments/*' => Http::response(['IsSuccess' => false, 'Message' => 'The token does not have the required permissions!'], 401)]);

        $this->expectException(MyFatoorahException::class);
        $this->expectExceptionMessage('The token does not have the required permissions!');

        $this->client()->paymentDetails('abc123456');
    }

    public function test_the_enabled_payment_methods_are_listed_with_the_name_to_create_a_payment_with(): void
    {
        $this->fakeMyFatoorah();

        $methods = $this->client()->paymentMethods();

        $this->assertSame(['CARD', 'KNET', 'APPLE_PAY', 'MADA'], array_column($methods, 'apiName'));
        $this->assertSame('KNET', $methods[1]['name']);
    }

    public function test_the_invoice_lookup_finds_the_payments_made_against_an_invoice(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => [
            'InvoiceStatus' => 'Paid',
            'InvoiceTransactions' => [
                ['PaymentId' => '111', 'TransactionStatus' => 'Failed'],
                ['PaymentId' => '222', 'TransactionStatus' => 'Succss'],
                ['TransactionStatus' => 'InProgress'],
            ],
        ]])]);

        $transactions = $this->client()->invoiceTransactions('6148108');

        $this->assertSame([['paymentId' => '111', 'status' => 'Failed'], ['paymentId' => '222', 'status' => 'Succss']], $transactions);
        Http::assertSent(fn (Request $request) => $request->data() === ['Key' => '6148108', 'KeyType' => 'InvoiceId']);
    }

    public function test_an_invoice_my_fatoorah_does_not_know_has_no_transactions(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v2/GetPaymentStatus' => Http::response(['IsSuccess' => false, 'Message' => 'Invalid data'], 400)]);

        $this->assertSame([], $this->client()->invoiceTransactions('0'));
    }
}
