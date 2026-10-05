<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Services\Store\ActivityLogger;
use App\Support\Csv;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What shoppers send the shop: messages from the contact form, and newsletter
 * sign-ups.
 */
class InboxController extends Controller
{
    public function __construct(protected ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';
        $q = $this->term($request);

        return view('panel.inbox.index', [
            'messages' => ContactMessage::query()
                ->when($filter === 'unread', fn (Builder $query) => $query->unread())
                ->when($q !== '', function (Builder $query) use ($q): void {
                    $term = '%'.$q.'%';

                    $query->where(fn (Builder $query) => $query
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('message', 'like', $term));
                })
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'filter' => $filter,
            'q' => $q,
            'unread' => ContactMessage::query()->unread()->count(),
            'total' => ContactMessage::query()->count(),
            'subscribers' => NewsletterSubscriber::query()->subscribed()->count(),
        ]);
    }

    /** Opening a message marks it as read. */
    public function show(ContactMessage $message): View
    {
        if ($message->read_at === null) {
            $message->update(['read_at' => now()]);
        }

        return view('panel.inbox.show', ['message' => $message]);
    }

    public function toggleRead(Request $request, ContactMessage $message): RedirectResponse
    {
        $message->update(['read_at' => $message->read_at === null ? now() : null]);

        return redirect()->route('panel.inbox.index', $request->only('filter'))
            ->with('status', $message->read_at === null ? __('panel.inbox.markedUnread') : __('panel.inbox.markedRead'));
    }

    public function destroy(Request $request, ContactMessage $message): RedirectResponse
    {
        $label = $message->name;

        $message->delete();

        $this->log->record($request->user('staff'), 'inbox.message_deleted', null, [], $label);

        return redirect()->route('panel.inbox.index')->with('status', __('panel.inbox.deleted'));
    }

    public function subscribers(Request $request): View
    {
        $q = $this->term($request);

        return view('panel.inbox.subscribers', [
            'subscribers' => NewsletterSubscriber::query()
                ->when($q !== '', fn (Builder $query) => $query->where('email', 'like', '%'.$q.'%'))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'q' => $q,
            'active' => NewsletterSubscriber::query()->subscribed()->count(),
            'unread' => ContactMessage::query()->unread()->count(),
        ]);
    }

    public function exportSubscribers(): StreamedResponse
    {
        $zone = PanelFormat::timezone();

        $rows = NewsletterSubscriber::query()
            ->subscribed()
            ->oldest('id')
            ->lazy()
            ->map(fn (NewsletterSubscriber $subscriber) => [
                $subscriber->email,
                $subscriber->locale === 'en' ? __('panel.common.english') : __('panel.common.arabic'),
                $subscriber->created_at->copy()->timezone($zone)->format('Y-m-d'),
            ]);

        return Csv::download('newsletter-'.now($zone)->format('Y-m-d').'.csv', [
            __('panel.orders.email'), __('panel.inbox.language'), __('panel.customers.joined'),
        ], $rows);
    }

    public function destroySubscriber(Request $request, NewsletterSubscriber $subscriber): RedirectResponse
    {
        $label = $subscriber->email;

        $subscriber->delete();

        $this->log->record($request->user('staff'), 'inbox.subscriber_removed', null, [], $label);

        return back()->with('status', __('panel.inbox.subscriberRemoved'));
    }

    protected function term(Request $request): string
    {
        return trim(str_replace(['%', '_', '\\'], ' ', (string) $request->query('q', '')));
    }
}
