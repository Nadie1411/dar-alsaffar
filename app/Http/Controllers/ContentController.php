<?php

namespace App\Http\Controllers;

use App\Contracts\Store\Catalog;
use App\Contracts\Store\Inbox;
use App\Services\Settings;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(
        protected Inbox $inbox,
        protected Catalog $catalog,
        protected Settings $settings,
    ) {}

    public function about()
    {
        return view('pages.about', [
            'collections' => $this->catalog->categoriesWithCounts(),
            'settings' => $this->settings,
        ]);
    }

    public function contact()
    {
        return view('pages.contact');
    }

    public function sendContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $result = $this->inbox->sendContact([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'message' => $validated['message'],
        ]);

        if (! $result['ok']) {
            return back()->withInput()->withErrors([
                'message' => $result['message'] ?? __('storefront.content.messageFailed'),
            ]);
        }

        return back()->with('status', __('storefront.content.messageSent'));
    }

    public function newsletter(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc']]);

        $result = $this->inbox->subscribe($validated['email']);

        // Only say "subscribed" when the sign-up was actually accepted —
        // reporting a success we did not verify would leave people expecting
        // mail that never arrives.
        if (! $result['ok']) {
            return back()->withInput()->withErrors([
                'newsletter' => $result['message'] ?? __('storefront.errors.generic'),
            ]);
        }

        return back()->with('newsletter', __('storefront.footer.subscribed'));
    }

    public function shipping()
    {
        return view('pages.legal.shipping');
    }

    public function returns()
    {
        return view('pages.legal.returns');
    }

    public function privacy()
    {
        return view('pages.legal.privacy');
    }

    public function terms()
    {
        return view('pages.legal.terms');
    }
}
