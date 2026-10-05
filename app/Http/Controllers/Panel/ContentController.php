<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Uploads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The words, pictures and contact details the storefront shows that are not
 * products: the offer strip, the pop-up, the home page hero, the About page and
 * the published contact points.
 */
class ContentController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected Uploads $uploads,
        protected ActivityLogger $log,
    ) {}

    public function edit(): View
    {
        return view('panel.content.edit', ['settings' => $this->settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
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
            'orders_alert' => ['nullable', 'boolean'],
            'orders_poll' => ['nullable', 'integer', 'min:10', 'max:600'],
            'orders_notify_email' => ['nullable', 'email:rfc', 'max:190'],
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
            'social_instagram' => ['nullable', 'url:http,https', 'max:200'],
            'social_tiktok' => ['nullable', 'url:http,https', 'max:200'],
        ]);

        $replaced = [];

        $popupImage = $this->upload($request, 'popup_image', 'remove_image', 'popup.image', $replaced);
        $heroImage = $this->upload($request, 'hero_image', 'remove_hero_image', 'hero.image', $replaced);
        $heroVideo = $this->upload($request, 'hero_video', 'remove_hero_video', 'hero.video', $replaced);

        $this->settings->save([
            'strip.enabled' => $request->boolean('strip_enabled'),
            'popup.enabled' => $request->boolean('popup_enabled'),
            'popup.title' => $data['popup_title'] ?? '',
            'popup.body' => $data['popup_body'] ?? '',
            'popup.image' => $popupImage,
            'popup.cta_label' => $data['popup_cta_label'] ?? '',
            'popup.cta_path' => trim((string) ($data['popup_cta_path'] ?? ''), '/'),
            'popup.delay' => $data['popup_delay'] ?? 6,
            'popup.snooze_days' => $data['popup_snooze_days'] ?? 7,
            'install.enabled' => $request->boolean('install_enabled'),
            'install.delay' => $data['install_delay'] ?? 12,
            'orders.alert' => $request->boolean('orders_alert'),
            'orders.poll' => $data['orders_poll'] ?? 30,
            'orders.notify_email' => $data['orders_notify_email'] ?? '',
            'hero.image' => $heroImage,
            'hero.video' => $heroVideo,
            'about.story_ar' => $data['about_story_ar'] ?? '',
            'about.story_en' => $data['about_story_en'] ?? '',
            'about.philosophy_ar' => $data['about_philosophy_ar'] ?? '',
            'about.philosophy_en' => $data['about_philosophy_en'] ?? '',
            'about.quality_ar' => $data['about_quality_ar'] ?? '',
            'about.quality_en' => $data['about_quality_en'] ?? '',
            'contact.phone' => $data['contact_phone'] ?? '',
            'contact.whatsapp' => $data['contact_whatsapp'] ?? '',
            'contact.email' => $data['contact_email'] ?? '',
            'social.instagram' => $data['social_instagram'] ?? '',
            'social.tiktok' => $data['social_tiktok'] ?? '',
        ]);

        // Only once the new settings are saved are the replaced files let go.
        foreach ($replaced as $path) {
            $this->uploads->delete($path);
        }

        $this->log->record($request->user('staff'), 'content.updated', null, [], __('panel.modules.content'));

        return back()->with('status', __('panel.content.saved'));
    }

    /**
     * The path a setting should hold after this form: a new upload, the
     * existing one, or nothing when it was removed.
     *
     * @param  array<int,string>  $replaced  collects the files the change leaves behind
     */
    protected function upload(Request $request, string $field, string $removeField, string $key, array &$replaced): string
    {
        $current = (string) $this->settings->get($key, '');

        if ($request->hasFile($field)) {
            $replaced[] = $current;

            return $this->uploads->store($request->file($field), 'site');
        }

        if ($request->boolean($removeField)) {
            $replaced[] = $current;

            return '';
        }

        return $current;
    }
}
