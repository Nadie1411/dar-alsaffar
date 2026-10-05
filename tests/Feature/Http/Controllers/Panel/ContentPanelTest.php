<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Services\Settings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\IsolatesUploads;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class ContentPanelTest extends TestCase
{
    use IsolatesUploads, LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('manager');
    }

    private function settings(): Settings
    {
        return $this->app->make(Settings::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'strip_enabled' => '1', 'popup_enabled' => '1', 'install_enabled' => '1', 'orders_alert' => '1',
            'popup_delay' => '6', 'popup_snooze_days' => '7', 'install_delay' => '12', 'orders_poll' => '30',
        ], $overrides);
    }

    public function test_the_page_opens_with_the_shops_current_values(): void
    {
        $this->settings()->save(['popup.title' => 'Eid offers', 'contact.phone' => '+96511112222', 'about.story_en' => 'Our story so far']);

        $this->get(route('panel.content.edit'))
            ->assertOk()
            ->assertSee('value="Eid offers"', false)
            ->assertSee('value="+96511112222"', false)
            ->assertSee('Our story so far');
    }

    public function test_everything_the_form_describes_is_saved(): void
    {
        $this->put(route('panel.content.update'), $this->form([
            'strip_enabled' => null, 'popup_title' => 'Eid offers', 'popup_body' => 'Up to 20% off', 'popup_cta_label' => 'See offers',
            'popup_cta_path' => '/offers/', 'popup_delay' => '9', 'popup_snooze_days' => '3', 'install_enabled' => null, 'install_delay' => '20',
            'orders_alert' => null, 'orders_poll' => '60',
            'about_story_ar' => 'قصتنا', 'about_story_en' => 'Our story', 'about_philosophy_en' => 'Our philosophy', 'about_quality_ar' => 'الجودة',
            'contact_phone' => '+96599785642', 'contact_whatsapp' => '96555560002', 'contact_email' => 'hello@dar-alsaffar.net',
            'social_instagram' => 'https://instagram.com/dar', 'social_tiktok' => 'https://tiktok.com/@dar',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status');

        $settings = $this->settings();

        $this->assertFalse($settings->bool('strip.enabled'));
        $this->assertTrue($settings->bool('popup.enabled'));
        $this->assertFalse($settings->bool('install.enabled'));
        $this->assertFalse($settings->bool('orders.alert'));
        $this->assertSame('Eid offers', $settings->get('popup.title'));
        $this->assertSame('Up to 20% off', $settings->get('popup.body'));
        $this->assertSame('offers', $settings->get('popup.cta_path'), 'slashes trimmed');
        $this->assertSame(9, $settings->int('popup.delay'));
        $this->assertSame(3, $settings->int('popup.snooze_days'));
        $this->assertSame(20, $settings->int('install.delay'));
        $this->assertSame(60, $settings->int('orders.poll'));
        $this->assertSame('قصتنا', $settings->get('about.story_ar'));
        $this->assertSame('96555560002', $settings->get('contact.whatsapp'));
        $this->assertSame('https://tiktok.com/@dar', $settings->get('social.tiktok'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'content.updated']);
    }

    public function test_what_is_saved_reaches_the_storefront(): void
    {
        $this->put(route('panel.content.update'), $this->form([
            'about_story_en' => 'Three generations of perfumers.', 'contact_phone' => '+96511112222', 'contact_email' => 'shop@example.org',
        ]));
        $this->startNewRequest();

        $this->get('/en-KW/about-us')->assertOk()->assertSee('Three generations of perfumers.');
        $this->get('/en-KW/contact-us')->assertOk()->assertSee('shop@example.org');
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalidForms(): array
    {
        return [
            'a button destination with odd characters' => [['popup_cta_path' => 'offers?x=<script>'], 'popup_cta_path'],
            'a pop-up delay that is too long' => [['popup_delay' => '500'], 'popup_delay'],
            'a negative snooze' => [['popup_snooze_days' => '-1'], 'popup_snooze_days'],
            'an order alert checked too often' => [['orders_poll' => '2'], 'orders_poll'],
            'a WhatsApp number with a plus and spaces' => [['contact_whatsapp' => '+965 5556'], 'contact_whatsapp'],
            'an email that is not one' => [['contact_email' => 'not-an-email'], 'contact_email'],
            'a script as the Instagram link' => [['social_instagram' => 'javascript:alert(1)'], 'social_instagram'],
            'a file link as the TikTok link' => [['social_tiktok' => 'ftp://example.org/x'], 'social_tiktok'],
            'an about section that is far too long' => [['about_story_en' => str_repeat('x', 2_001)], 'about_story_en'],
            'a pop-up title that is far too long' => [['popup_title' => str_repeat('x', 121)], 'popup_title'],
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    #[DataProvider('invalidForms')]
    public function test_a_form_that_does_not_add_up_is_refused_and_nothing_changes(array $override, string $field): void
    {
        $this->settings()->save(['popup.title' => 'Before']);

        $this->put(route('panel.content.update'), $this->form($override + ['popup_title' => 'After']))->assertSessionHasErrors($field);

        $this->assertSame('Before', $this->settings()->get('popup.title'));
    }

    public function test_pictures_and_a_video_are_stored_under_random_names(): void
    {
        $this->put(route('panel.content.update'), $this->form([
            'popup_image' => UploadedFile::fake()->image('popup.jpg', 600, 400),
            'hero_image' => UploadedFile::fake()->image('hero.png', 1200, 800),
            'hero_video' => UploadedFile::fake()->create('story.mp4', 2_000, 'video/mp4'),
        ]))->assertSessionHasNoErrors();

        foreach (['popup.image' => 'jpg', 'hero.image' => 'png', 'hero.video' => 'mp4'] as $key => $extension) {
            $path = $this->settings()->get($key);

            $this->assertMatchesRegularExpression('#^uploads/site/[A-Za-z0-9]{32}\.'.$extension.'$#', $path, $key);
            $this->assertTrue($this->storedFileExists($path), $key);
        }
    }

    public function test_replacing_or_removing_a_picture_deletes_the_old_file(): void
    {
        $old = $this->existingUpload('uploads/site/old-popup.jpg');
        $oldHero = $this->existingUpload('uploads/site/old-hero.jpg');
        $oldVideo = $this->existingUpload('uploads/site/old-video.mp4');
        $this->settings()->save(['popup.image' => $old, 'hero.image' => $oldHero, 'hero.video' => $oldVideo]);

        $this->put(route('panel.content.update'), $this->form([
            'popup_image' => UploadedFile::fake()->image('new.jpg', 400, 400),
            'remove_hero_image' => '1',
            'remove_hero_video' => '1',
        ]));

        $this->assertNotSame($old, $this->settings()->get('popup.image'));
        $this->assertFalse($this->storedFileExists($old));
        $this->assertFalse($this->storedFileExists($oldHero));
        $this->assertFalse($this->storedFileExists($oldVideo));
        $this->assertSame('', $this->settings()->get('hero.image', ''));
        $this->assertSame('', $this->settings()->get('hero.video', ''));
    }

    public function test_saving_again_without_choosing_a_file_keeps_the_pictures_already_there(): void
    {
        $kept = $this->existingUpload('uploads/site/kept.jpg');
        $this->settings()->save(['popup.image' => $kept]);

        $this->put(route('panel.content.update'), $this->form());

        $this->assertSame($kept, $this->settings()->get('popup.image'));
        $this->assertTrue($this->storedFileExists($kept));
    }

    public function test_a_picture_from_the_old_admin_panel_stored_straight_under_uploads_is_cleaned_up_too(): void
    {
        $legacy = $this->existingUpload('uploads/legacypopup.jpg');
        $this->settings()->save(['popup.image' => $legacy]);

        $this->put(route('panel.content.update'), $this->form(['remove_image' => '1']));

        $this->assertFalse($this->storedFileExists($legacy));
    }

    public function test_only_pictures_and_mp4_video_are_accepted_and_not_too_big(): void
    {
        foreach ([
            ['popup_image' => UploadedFile::fake()->create('x.php', 5, 'application/x-php')],
            ['popup_image' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
            ['popup_image' => UploadedFile::fake()->create('huge.jpg', 5_000, 'image/jpeg')],
            ['hero_image' => UploadedFile::fake()->create('huge.png', 7_000, 'image/png')],
            ['hero_video' => UploadedFile::fake()->create('clip.avi', 100, 'video/x-msvideo')],
            ['hero_video' => UploadedFile::fake()->create('shell.php', 100, 'application/x-php')],
            ['hero_video' => UploadedFile::fake()->create('huge.mp4', 60_000, 'video/mp4')],
        ] as $upload) {
            $this->put(route('panel.content.update'), $this->form($upload))->assertSessionHasErrors(array_key_first($upload));
        }

        $this->assertSame('', $this->settings()->get('popup.image', ''));
    }

    public function test_the_alert_settings_drive_the_panels_own_polling(): void
    {
        $this->put(route('panel.content.update'), $this->form(['orders_alert' => null, 'orders_poll' => '45']));
        $this->startNewRequest();

        $this->get(route('panel.dashboard'))
            ->assertSee('data-orders-poll="45"', false)
            ->assertSee('data-orders-sound="0"', false);
    }

    public function test_a_member_of_staff_cannot_open_or_change_the_content(): void
    {
        $this->signInAs('staff');

        $this->get(route('panel.content.edit'))->assertForbidden();
        $this->put(route('panel.content.update'), $this->form(['popup_title' => 'Hijacked']))->assertForbidden();
        $this->assertSame('', $this->settings()->get('popup.title', ''));
    }
}
