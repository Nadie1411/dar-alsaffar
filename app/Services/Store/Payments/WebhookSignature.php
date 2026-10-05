<?php

namespace App\Services\Store\Payments;

/**
 * Checks that a webhook really came from MyFatoorah.
 *
 * MyFatoorah signs a "payment status changed" event with HMAC-SHA256, keyed
 * with the secret from the portal's Webhook Settings, over five of the event's
 * fields in a fixed order, written as `name=value` pairs joined by commas
 * (a missing value is an empty string). The result, base64-encoded, is sent
 * in the MyFatoorah-Signature header.
 */
class WebhookSignature
{
    /**
     * @param  array<string,mixed>  $event  the decoded webhook body
     */
    public function isValid(array $event, ?string $header, string $secret): bool
    {
        if ($header === null || trim($header) === '' || $secret === '') {
            return false;
        }

        return hash_equals($this->sign($event, $secret), trim($header));
    }

    /**
     * @param  array<string,mixed>  $event
     */
    public function sign(array $event, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $this->payloadOf($event), $secret, true));
    }

    /**
     * @param  array<string,mixed>  $event
     */
    protected function payloadOf(array $event): string
    {
        $fields = [
            'Invoice.Id' => data_get($event, 'Data.Invoice.Id'),
            'Invoice.Status' => data_get($event, 'Data.Invoice.Status'),
            'Transaction.Status' => data_get($event, 'Data.Transaction.Status'),
            'Transaction.PaymentId' => data_get($event, 'Data.Transaction.PaymentId'),
            'Invoice.ExternalIdentifier' => data_get($event, 'Data.Invoice.ExternalIdentifier'),
        ];

        return collect($fields)
            ->map(fn ($value, $name) => $name.'='.(is_scalar($value) ? (string) $value : ''))
            ->implode(',');
    }
}
