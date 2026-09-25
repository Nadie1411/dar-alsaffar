<?php

namespace App\Services\Overzaki;

use App\Services\Settings;
use App\Support\Loc;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Watches the store's order feed so the shop can be alerted to a new order.
 *
 * Orders belong to Overzaki. This only reads them, normalises each one down to
 * the handful of fields the counter needs, and remembers which ones have been
 * acknowledged so the alarm knows when to stop.
 *
 * The "seen" marker is stored server side rather than per browser, so
 * acknowledging an order on the phone also silences the laptop.
 */
class OrdersMonitor
{
    public function __construct(
        protected OverzakiClient $client,
        protected Settings $settings,
    ) {}

    /**
     * The most recent orders, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        $response = $this->client->get('/orders/search', [
            'pageSize' => $limit,
            'pageNumber' => 1,
            'sort' => '-createdAt',
        ]);

        $rows = $response['data'] ?? (is_array($response) ? $response : []);

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => $this->present($row))
            ->sortByDesc('createdAt')
            ->values()
            ->all();
    }

    /**
     * Orders placed since the shop last acknowledged the alert.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unseen(int $limit = 20): array
    {
        $since = $this->lastSeenAt();

        return collect($this->recent($limit))
            ->filter(fn ($order) => $since === null || ($order['createdAt'] ?? 0) > $since)
            ->values()
            ->all();
    }

    /** Silence the alarm: everything up to now counts as read. */
    public function acknowledge(): void
    {
        $newest = $this->recent(1)[0]['createdAt'] ?? null;

        $this->settings->save([
            'orders.last_seen_at' => $newest ?? time(),
        ]);
    }

    public function lastSeenAt(): ?int
    {
        $stored = $this->settings->int('orders.last_seen_at', 0);

        return $stored > 0 ? $stored : null;
    }

    public function alertEnabled(): bool
    {
        return $this->settings->bool('orders.alert', true);
    }

    public function pollSeconds(): int
    {
        return max(10, $this->settings->int('orders.poll', 30));
    }

    /**
     * Reduce an order document to what the alert screen shows. Deliberately
     * narrow — the shop's own staff see their customer's name and number, and
     * nothing beyond that leaves the API.
     *
     * @return array<string,mixed>
     */
    protected function present(array $row): array
    {
        $createdAt = $this->timestamp($row['createdAt'] ?? $row['date'] ?? null);

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'number' => (string) ($row['orderNumber'] ?? $row['serial'] ?? $row['_id'] ?? ''),
            'createdAt' => $createdAt,
            'placedAt' => $createdAt ? Carbon::createFromTimestamp($createdAt)->toIso8601String() : null,
            // Several of these come back as localised maps or nested objects
            // depending on how the order was created, so none are cast blind.
            'customer' => $this->text($row['customerName'] ?? null),
            'phone' => $this->text($row['customerPhone'] ?? null),
            'total' => $this->number($row['total'] ?? $row['amountToPay'] ?? 0),
            // Falling back to a literal 'KWD' would print the English symbol
            // on an Arabic screen; Money knows the locale's own.
            'symbol' => Loc::text($row['symbol'] ?? null) ?: Money::symbol(),
            'status' => $this->text($row['status'] ?? null) ?? '',
            'itemCount' => is_array($row['items'] ?? null) ? count($row['items']) : 0,
            'isCash' => (bool) ($row['isCashOnDelivery'] ?? false),
            'isPickup' => (bool) ($row['isStorePickup'] ?? false),
        ];
    }

    /** A display string from a value that may be a string, a localised map, or neither. */
    protected function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = Loc::text($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value) ?: null;
        }

        return null;
    }

    protected function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function timestamp(mixed $value): ?int
    {
        if (empty($value) || ! is_string($value)) {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }
}
