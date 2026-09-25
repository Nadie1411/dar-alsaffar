<?php

namespace App\Http\Controllers;

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
            'popup_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
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
