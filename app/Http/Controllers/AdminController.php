<?php

namespace App\Http\Controllers;

use App\Services\Overzaki\OrdersMonitor;
use App\Services\Overzaki\PromotionService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected PromotionService $promotions,
        protected OrdersMonitor $orders,
    ) {}

    public function showLogin()
    {
        abort_unless(config('admin.password_hash'), 404);

        return session('admin.authenticated')
            ? redirect('/admin')
            : view('admin.login');
    }

    public function login(Request $request)
    {
        abort_unless(config('admin.password_hash'), 404);

        $request->validate(['password' => ['required', 'string']]);

        // Throttled per IP so the single password cannot be brute forced.
        $key = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, config('admin.throttle'))) {
            return back()->withErrors([
                'password' => __('storefront.admin.throttled', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        if (! Hash::check($request->input('password'), config('admin.password_hash'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['password' => __('storefront.admin.wrongPassword')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('admin.authenticated', true);

        return redirect($request->session()->pull('admin.intended', '/admin'));
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin.authenticated');
        $request->session()->regenerate();

        return redirect('/admin/login');
    }

    public function index()
    {
        return view('admin.index', [
            'settings' => $this->settings,
            'liveOffers' => $this->promotions->public(),
        ]);
    }

    /**
     * The live order board. Rings until someone acknowledges it.
     */
    public function orders()
    {
        return view('admin.orders', [
            'orders' => $this->orders->recent(20),
            'unseenCount' => count($this->orders->unseen(20)),
            'pollSeconds' => $this->orders->pollSeconds(),
            'alertEnabled' => $this->orders->alertEnabled(),
        ]);
    }

    /** Polled by the order board; deliberately uncacheable. */
    public function ordersFeed()
    {
        $recent = $this->orders->recent(20);
        $unseen = $this->orders->unseen(20);

        return response()
            ->json([
                'orders' => $recent,
                'unseen' => count($unseen),
                'unseenIds' => array_column($unseen, 'id'),
                'alert' => $this->orders->alertEnabled(),
                'serverTime' => time(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function acknowledgeOrders(Request $request)
    {
        $this->orders->acknowledge();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'unseen' => 0]);
        }

        return back()->with('status', __('storefront.admin.ordersAcknowledged'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'strip_enabled' => ['nullable', 'boolean'],
            'popup_enabled' => ['nullable', 'boolean'],
            'popup_title' => ['nullable', 'string', 'max:120'],
            'popup_body' => ['nullable', 'string', 'max:400'],
            'popup_cta_label' => ['nullable', 'string', 'max:60'],
            'popup_cta_path' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9\/-]*$/i'],
            'popup_delay' => ['nullable', 'integer', 'min:0', 'max:120'],
            'popup_snooze_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'install_enabled' => ['nullable', 'boolean'],
            'install_delay' => ['nullable', 'integer', 'min:0', 'max:300'],
            'cod_enabled' => ['nullable', 'boolean'],
            'orders_alert' => ['nullable', 'boolean'],
            'orders_poll' => ['nullable', 'integer', 'min:10', 'max:600'],
            'popup_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'remove_hero_image' => ['nullable', 'boolean'],
            'hero_video' => ['nullable', 'file', 'mimes:mp4', 'max:51200'],
            'remove_hero_video' => ['nullable', 'boolean'],
            'about_story_ar' => ['nullable', 'string', 'max:2000'],
            'about_story_en' => ['nullable', 'string', 'max:2000'],
            'about_philosophy_ar' => ['nullable', 'string', 'max:2000'],
            'about_philosophy_en' => ['nullable', 'string', 'max:2000'],
            'about_quality_ar' => ['nullable', 'string', 'max:2000'],
            'about_quality_en' => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'social_instagram' => ['nullable', 'url', 'max:200'],
            'social_tiktok' => ['nullable', 'url', 'max:200'],
        ]);

        $image = $this->settings->get('popup.image', '');

        if ($request->boolean('remove_image')) {
            $this->deleteUpload($image);
            $image = '';
        }

        if ($request->hasFile('popup_image')) {
            $this->deleteUpload($image);
            $image = $this->storeUpload($request->file('popup_image'));
        }

        $heroImage = $this->settings->get('hero.image', '');

        if ($request->boolean('remove_hero_image')) {
            $this->deleteUpload($heroImage);
            $heroImage = '';
        }

        if ($request->hasFile('hero_image')) {
            $this->deleteUpload($heroImage);
            $heroImage = $this->storeUpload($request->file('hero_image'));
        }

        $heroVideo = $this->settings->get('hero.video', '');

        if ($request->boolean('remove_hero_video')) {
            $this->deleteUpload($heroVideo);
            $heroVideo = '';
        }

        if ($request->hasFile('hero_video')) {
            $this->deleteUpload($heroVideo);
            $heroVideo = $this->storeUpload($request->file('hero_video'));
        }

        $this->settings->save([
            'strip.enabled' => $request->boolean('strip_enabled'),
            'popup.enabled' => $request->boolean('popup_enabled'),
            'popup.title' => $validated['popup_title'] ?? '',
            'popup.body' => $validated['popup_body'] ?? '',
            'popup.image' => $image,
            'popup.cta_label' => $validated['popup_cta_label'] ?? '',
            'popup.cta_path' => trim((string) ($validated['popup_cta_path'] ?? ''), '/'),
            'popup.delay' => $validated['popup_delay'] ?? 6,
            'popup.snooze_days' => $validated['popup_snooze_days'] ?? 7,
            'install.enabled' => $request->boolean('install_enabled'),
            'install.delay' => $validated['install_delay'] ?? 12,
            'checkout.cod' => $request->boolean('cod_enabled'),
            'orders.alert' => $request->boolean('orders_alert'),
            'orders.poll' => $validated['orders_poll'] ?? 30,
            'hero.image' => $heroImage,
            'hero.video' => $heroVideo,
            'about.story_ar' => $validated['about_story_ar'] ?? '',
            'about.story_en' => $validated['about_story_en'] ?? '',
            'about.philosophy_ar' => $validated['about_philosophy_ar'] ?? '',
            'about.philosophy_en' => $validated['about_philosophy_en'] ?? '',
            'about.quality_ar' => $validated['about_quality_ar'] ?? '',
            'about.quality_en' => $validated['about_quality_en'] ?? '',
            'contact.phone' => $validated['contact_phone'] ?? '',
            'contact.whatsapp' => $validated['contact_whatsapp'] ?? '',
            'contact.email' => $validated['contact_email'] ?? '',
            'social.instagram' => $validated['social_instagram'] ?? '',
            'social.tiktok' => $validated['social_tiktok'] ?? '',
        ]);

        return back()->with('status', __('storefront.admin.saved'));
    }

    /** Lets the team pick up a price or stock edit without waiting for the TTL. */
    public function clearCache()
    {
        Artisan::call('cache:clear');

        return back()->with('status', __('storefront.admin.cacheCleared'));
    }

    protected function storeUpload(UploadedFile $file): string
    {
        $dir = public_path('uploads');
        File::ensureDirectoryExists($dir);

        // A random name, and only the extension we validated — never the
        // client's own filename.
        $name = Str::random(24).'.'.$file->extension();
        $file->move($dir, $name);

        return 'uploads/'.$name;
    }

    protected function deleteUpload(mixed $path): void
    {
        if (! is_string($path) || ! str_starts_with($path, 'uploads/')) {
            return;
        }

        File::delete(public_path($path));
    }
}
