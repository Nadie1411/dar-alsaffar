<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Switches MyFatoorah on for a test, with a made-up key against the sandbox
 * address, and fakes its API with responses shaped like the real ones.
 */
trait FakesMyFatoorah
{
    use LoadsFixtures;

    protected const MF = 'apitest.myfatoorah.com';

    protected const MF_KEY = 'test-api-key-not-a-real-secret';

    protected function configureMyFatoorah(?string $webhookSecret = 'test-secret-key'): void
    {
        config([
            'myfatoorah.enabled' => true,
            'myfatoorah.api_key' => self::MF_KEY,
            'myfatoorah.api_url' => 'https://'.self::MF,
            'myfatoorah.webhook_secret' => $webhookSecret,
            'myfatoorah.expiry_minutes' => 60,
        ]);
    }

    /**
     * Everything MyFatoorah is asked in a normal checkout, faked. Anything
     * passed in replaces the default for that URL; anything unfaked fails the test.
     *
     * @param  array<string,mixed>  $overrides
     */
    protected function fakeMyFatoorah(array $overrides = []): void
    {
        Http::preventStrayRequests();

        Http::fake($overrides + [
            self::MF.'/v3/payments' => Http::response($this->fixture('myfatoorah/create-payment'), 201),
            self::MF.'/v3/payment-methods' => Http::response($this->fixture('myfatoorah/payment-methods')),
        ]);
    }

    /**
     * MyFatoorah's answer to creating a payment, with its own invoice id: every
     * real invoice has a different one.
     *
     * @return array<string,mixed>
     */
    protected function createdInvoice(string $invoiceId): array
    {
        $created = $this->fixture('myfatoorah/create-payment');
        data_set($created, 'Data.InvoiceId', $invoiceId);
        data_set($created, 'Data.PaymentURL', 'https://demo.MyFatoorah.com/KWT/ie/invoice-'.$invoiceId);

        return $created;
    }

    /**
     * What "get payment details" answers for a payment, starting from a paid
     * KWD 40 one and changed as the test needs.
     *
     * @param  array<string,mixed>  $changes  dotted paths into the response's Data
     * @return array<string,mixed>
     */
    protected function paymentDetails(string $reference, array $changes = []): array
    {
        $details = $this->fixture('myfatoorah/payment-details-paid');
        data_set($details, 'Data.Invoice.ExternalIdentifier', $reference);

        foreach ($changes as $path => $value) {
            data_set($details, 'Data.'.$path, $value);
        }

        return $details;
    }

    /**
     * A "payment status changed" webhook for a payment, optionally with the
     * signature MyFatoorah would send for it.
     *
     * @param  array<string,mixed>  $changes  dotted paths into the event
     * @return array<string,mixed>
     */
    protected function webhookEvent(string $reference, array $changes = []): array
    {
        $event = $this->fixture('myfatoorah/webhook-payment-status');
        data_set($event, 'Data.Invoice.ExternalIdentifier', $reference);

        foreach ($changes as $path => $value) {
            data_set($event, $path, $value);
        }

        return $event;
    }
}
