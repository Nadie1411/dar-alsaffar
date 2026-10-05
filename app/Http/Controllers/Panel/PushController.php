<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Store\Push\OrderPushNotifier;
use App\Services\Store\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A member of staff allowing, or stopping, order notifications on a device —
 * and checking that they work. Open to everyone who can sign in: what they are
 * sent is decided by their role when an order arrives, not when they subscribe.
 */
class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        abort_unless(WebPush::isAllowedEndpoint($data['endpoint']), 422, __('panel.push.refused'));

        // The same device signing in as someone else becomes theirs, not a second copy.
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])],
            [
                'user_id' => $request->user('staff')->id,
                'endpoint' => $data['endpoint'],
                'device' => Str::limit((string) $request->userAgent(), 120, ''),
                'failures' => 0,
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        $request->user('staff')->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashOf($data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, OrderPushNotifier $notifier): JsonResponse
    {
        return response()->json(['delivered' => $notifier->test($request->user('staff'))]);
    }
}
