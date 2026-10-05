<?php

namespace App\Services\Store\Push;

use App\Enums\PanelModule;
use App\Models\PushSubscription;
use App\Models\User;
use Throwable;

/**
 * Wakes the phones of the staff who look after orders.
 */
class OrderPushNotifier
{
    public function __construct(protected WebPush $push) {}

    /** Every device of every active member of staff who may see orders. */
    public function newOrder(): int
    {
        $subscriptions = PushSubscription::query()
            ->whereHas('staff', fn ($staff) => $staff->where('is_active', true))
            ->with('staff')
            ->get()
            ->filter(fn (PushSubscription $subscription) => $subscription->staff->canAccess(PanelModule::Orders));

        return $this->sendTo($subscriptions->all());
    }

    /** The devices of one member of staff — what the "send a test" button uses. */
    public function test(User $member): int
    {
        return $this->sendTo($member->pushSubscriptions()->get()->all());
    }

    /**
     * @param  array<int,PushSubscription>  $subscriptions
     */
    protected function sendTo(array $subscriptions): int
    {
        $delivered = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $delivered += $this->push->send($subscription) ? 1 : 0;
            } catch (Throwable $e) {
                // One broken device must not stop the others, nor the order that caused this.
                report($e);
            }
        }

        return $delivered;
    }
}
