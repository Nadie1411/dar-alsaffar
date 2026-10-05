<?php

namespace App\Services\Store\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The calls this shop makes to MyFatoorah's v3 API (and one v2 lookup).
 *
 * The API key is added as a bearer token on every request and appears nowhere
 * else: not in a log line, not in an exception message, not in a response.
 */
class MyFatoorahClient
{
    public function __construct(protected GatewayConfig $gateway) {}

    /** Whether online payment is switched on and has what it needs to run. */
    public function isConfigured(): bool
    {
        return $this->gateway->enabled()
            && $this->gateway->apiKey() !== ''
            && $this->gateway->apiUrl() !== '';
    }

    /**
     * Create an invoice and get the hosted page to send the shopper to.
     *
     * Not retried: a repeated attempt after a timeout could create a second
     * invoice for the same order, and this call has no idempotency key.
     *
     * @param  array<string,mixed>  $payload
     * @return array{invoiceId:string,paymentUrl:string}
     */
    public function createPayment(array $payload): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->post('/v3/payments', $payload));

        $data = $this->dataOf($response, 'create payment');
        $invoiceId = (string) ($data['InvoiceId'] ?? '');
        $url = (string) ($data['PaymentURL'] ?? '');

        if ($invoiceId === '' || ! str_starts_with($url, 'https://')) {
            throw new MyFatoorahException('MyFatoorah did not return an invoice and a payment page.');
        }

        return ['invoiceId' => $invoiceId, 'paymentUrl' => $url];
    }

    /**
     * The payment's details, straight from MyFatoorah — what a redirect or a
     * webhook claims is never taken on trust; this is what decides.
     *
     * Safe to repeat, so a brief outage is retried.
     *
     * @return array<string,mixed>|null null when MyFatoorah has no such payment
     */
    public function paymentDetails(string $paymentId): ?array
    {
        $response = $this->send(
            fn (PendingRequest $request) => $request->retry(
                [500, 1500],
                0,
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            )->get('/v3/payments/'.rawurlencode($paymentId))
        );

        // "Invalid data" is how MyFatoorah says it has never heard of this id.
        if ($response->status() === 400) {
            return null;
        }

        return $this->dataOf($response, 'payment details');
    }

    /**
     * The payment methods enabled on the account, with the name to use when
     * creating a payment. A key can be allowed to take payments yet not allowed
     * to list methods; that raises MyFatoorahException and the caller falls back.
     *
     * @return array<int,array{name:string,apiName:string}>
     */
    public function paymentMethods(): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/v3/payment-methods'));
        $methods = $this->dataOf($response, 'payment methods')['PaymentMethods'] ?? [];

        return collect(is_array($methods) ? $methods : [])
            ->filter(fn ($method) => is_array($method) && filled($method['ApiName'] ?? null))
            ->map(fn (array $method) => ['name' => (string) ($method['Name'] ?? ''), 'apiName' => (string) $method['ApiName']])
            ->values()
            ->all();
    }

    /**
     * The payments recorded against an invoice. Used only to find the
     * paymentId of an invoice whose shopper never came back and whose webhook
     * never arrived — the payment itself is then checked with paymentDetails().
     *
     * @return array<int,array{paymentId:string,status:string}>
     */
    public function invoiceTransactions(string $invoiceId): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->post('/v2/GetPaymentStatus', [
            'Key' => $invoiceId,
            'KeyType' => 'InvoiceId',
        ]));

        if ($response->status() === 400 || $response->status() === 404) {
            return [];
        }

        $transactions = $this->dataOf($response, 'invoice status')['InvoiceTransactions'] ?? [];

        return collect(is_array($transactions) ? $transactions : [])
            ->filter(fn ($transaction) => is_array($transaction) && filled($transaction['PaymentId'] ?? null))
            ->map(fn (array $transaction) => [
                'paymentId' => (string) $transaction['PaymentId'],
                'status' => (string) ($transaction['TransactionStatus'] ?? ''),
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------- plumbing

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->gateway->apiUrl(), '/'))
            ->withToken($this->gateway->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('myfatoorah.connect_timeout'))
            ->timeout((int) config('myfatoorah.timeout'));
    }

    /**
     * @param  callable(PendingRequest):Response  $call
     */
    protected function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw new MyFatoorahException('Online payment is not configured.');
        }

        try {
            return $call($this->request());
        } catch (ConnectionException $e) {
            // The message is the transport's own and carries no credentials.
            Log::warning('MyFatoorah could not be reached', ['error' => $e->getMessage()]);

            throw new MyFatoorahUnavailable('MyFatoorah could not be reached.', previous: $e);
        }
    }

    /**
     * The `Data` of a successful answer, or the right kind of exception.
     *
     * @return array<string,mixed>
     */
    protected function dataOf(Response $response, string $what): array
    {
        if ($response->serverError() || $response->status() === 429) {
            Log::warning('MyFatoorah failed on its side', ['what' => $what, 'status' => $response->status()]);

            throw new MyFatoorahUnavailable("MyFatoorah is unavailable ({$what}).");
        }

        $json = $response->json();
        $succeeded = $response->successful() && is_array($json) && filter_var($json['IsSuccess'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $succeeded) {
            $reason = is_array($json) ? $this->reasonFrom($json) : 'no readable answer';

            Log::warning('MyFatoorah refused a request', ['what' => $what, 'status' => $response->status(), 'reason' => $reason]);

            throw new MyFatoorahException("MyFatoorah refused the request ({$what}): {$reason}");
        }

        return is_array($json['Data'] ?? null) ? $json['Data'] : [];
    }

    /**
     * @param  array<string,mixed>  $json
     */
    protected function reasonFrom(array $json): string
    {
        $problems = collect($json['ValidationErrors'] ?? [])
            ->filter(fn ($error) => is_array($error))
            ->map(fn (array $error) => trim(($error['Name'] ?? '').': '.($error['Error'] ?? ''), ': '))
            ->filter()
            ->all();

        return mb_substr(implode('; ', $problems) ?: (string) ($json['Message'] ?? 'no reason given'), 0, 300);
    }
}
