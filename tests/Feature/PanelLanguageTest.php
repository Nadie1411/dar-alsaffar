<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Enums\PanelModule;
use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\User;
use App\Services\Store\Payments\PaymentOutcome;
use App\Support\PanelNav;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Every word the panel shows comes from lang/{ar,en}/panel.php. These tests
 * read the panel's own views and controllers for the keys they ask for and
 * check that both languages answer every one — so a missing translation, or a
 * key written in a shape the translator cannot find, is caught here and not by
 * somebody reading the raw key on a screen.
 */
class PanelLanguageTest extends TestCase
{
    /**
     * @return array<string,string>
     */
    private function flat(string $locale): array
    {
        return Arr::dot(Lang::get('panel', [], $locale));
    }

    /** @return array<int,string> */
    private function panelSourceFiles(): array
    {
        $files = [];

        foreach ([
            resource_path('views/panel'), resource_path('views/components/panel'),
            app_path('Http/Controllers/Panel'), app_path('Http/Requests/Panel'), app_path('Rules'),
        ] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $files[] = $file->getPathname();
            }
        }

        return array_merge($files, [
            app_path('Support/PanelNav.php'), app_path('Support/PanelFormat.php'), app_path('Enums/AdminRole.php'), app_path('Enums/PanelModule.php'),
            app_path('Http/Middleware/PanelAuth.php'), app_path('Console/Commands/CreateStaffAccount.php'),
        ]);
    }

    /**
     * The keys the panel's code asks for outright, as opposed to building them.
     *
     * @return array<string,string> key => the file that asks for it
     */
    private function staticKeysInUse(): array
    {
        $keys = [];

        foreach ($this->panelSourceFiles() as $file) {
            preg_match_all('/(?:__|trans_choice)\(\s*[\'"](panel\.[A-Za-z0-9_.]+)[\'"]/', File::get($file), $matches);

            foreach ($matches[1] as $key) {
                if (! str_ends_with($key, '.')) {
                    $keys[$key] = basename($file);
                }
            }
        }

        return $keys;
    }

    /**
     * The keys the panel builds from a value — a role, a status, an action —
     * listed from the values themselves so a new one cannot be forgotten.
     *
     * @return array<int,string>
     */
    private function builtKeys(): array
    {
        $keys = [];

        foreach (PanelModule::cases() as $module) {
            $keys[] = 'panel.modules.'.$module->value;
        }

        foreach (AdminRole::cases() as $role) {
            $keys[] = 'panel.roles.'.$role->value;
        }

        foreach (OrderStatus::cases() as $status) {
            $keys[] = 'panel.orders.status.'.$status->value;
        }

        foreach (PaymentState::cases() as $state) {
            $keys[] = 'panel.payments.states.'.$state->value;
        }

        foreach ([Payment::ANOMALY_ORDER_CANCELLED, Payment::ANOMALY_AMOUNT_MISMATCH, Payment::ANOMALY_DUPLICATE] as $anomaly) {
            $keys[] = 'panel.payments.anomalies.'.$anomaly;
        }

        foreach ([PaymentOutcome::PAID, PaymentOutcome::PENDING, PaymentOutcome::FAILED, PaymentOutcome::CANCELLED, PaymentOutcome::UNKNOWN] as $result) {
            $keys[] = 'panel.payments.recheck.'.$result;
        }

        foreach (['cod', 'online'] as $method) {
            $keys[] = 'panel.orders.methods.'.$method;
        }

        foreach (['percentage', 'fixed', 'free_shipping'] as $type) {
            $keys[] = 'panel.vouchers.types.'.$type;
        }

        foreach (['all', 'live', 'scheduled', 'expired', 'inactive'] as $filter) {
            $keys[] = 'panel.vouchers.filters.'.$filter;
        }

        foreach (['today', '7d', '30d', 'month', 'last_month'] as $preset) {
            $keys[] = 'panel.reports.presets.'.$preset;
        }

        foreach (['story', 'philosophy', 'quality'] as $section) {
            $keys[] = 'panel.content.'.$section;
        }

        foreach (['newOrders', 'unreadMessages', 'paymentsToReview', 'outOfStock', 'lowStock'] as $item) {
            $keys[] = 'panel.dashboard.attentionItems.'.$item;
        }

        foreach (['overview', 'sales', 'catalogue', 'store', 'team'] as $group) {
            $keys[] = 'panel.nav.'.$group;
        }

        // Every action the panel writes to the activity log, and the area it belongs to.
        foreach (File::allFiles(app_path('Http/Controllers/Panel')) as $file) {
            preg_match_all("/->record\\([^;]*?'([a-z_]+\\.[a-z_]+)'/s", File::get($file->getPathname()), $matches);

            foreach ($matches[1] as $action) {
                $keys[] = 'panel.log.actions.'.$action;
                $keys[] = 'panel.log.areas.'.explode('.', $action)[0];
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_arabic_and_english_have_exactly_the_same_keys(): void
    {
        $arabic = array_keys($this->flat('ar'));
        $english = array_keys($this->flat('en'));

        $this->assertSame([], array_values(array_diff($english, $arabic)), 'in English but not in Arabic');
        $this->assertSame([], array_values(array_diff($arabic, $english)), 'in Arabic but not in English');
    }

    public function test_every_key_the_panels_code_asks_for_is_answered_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $flat = $this->flat($locale);
            $missing = [];

            foreach ($this->staticKeysInUse() as $key => $file) {
                if (! array_key_exists(substr($key, strlen('panel.')), $flat)) {
                    $missing[] = "$key (asked for in $file)";
                }
            }

            $this->assertSame([], $missing, "missing in $locale");
        }
    }

    public function test_every_key_the_panel_builds_from_a_value_is_answered_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $flat = $this->flat($locale);
            $missing = array_values(array_filter(
                $this->builtKeys(),
                fn (string $key) => ! array_key_exists(substr($key, strlen('panel.')), $flat)
            ));

            $this->assertSame([], $missing, "missing in $locale");
        }
    }

    public function test_the_translator_can_actually_find_what_the_files_hold(): void
    {
        // Keys in the files are only useful if asking for them by their dotted name returns them.
        foreach (['ar', 'en'] as $locale) {
            foreach (array_keys($this->flat($locale)) as $key) {
                $this->assertTrue(Lang::has('panel.'.$key, $locale, false), "panel.$key in $locale");
            }
        }
    }

    public function test_no_string_is_empty_and_none_is_just_its_own_key(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach ($this->flat($locale) as $key => $text) {
                $this->assertIsString($text, "$key in $locale");
                $this->assertNotSame('', trim($text), "$key in $locale is empty");
                $this->assertNotSame('panel.'.$key, $text, "$key in $locale is its own key");
            }
        }
    }

    public function test_arabic_strings_are_arabic_and_english_strings_are_not(): void
    {
        $arabicLetters = '/\p{Arabic}/u';
        // Words that are the same in both languages, or are product and brand names.
        $exempt = ['top.language', 'common.currency', 'orders.methods.cod'];
        $arabic = $this->flat('ar');
        $english = $this->flat('en');
        $untranslated = [];
        $leaked = [];

        foreach ($english as $key => $text) {
            if (preg_match($arabicLetters, $text) === 1 && ! in_array($key, $exempt, true) && $key !== 'top.language') {
                $leaked[] = $key;
            }
        }

        foreach ($arabic as $key => $text) {
            if (preg_match($arabicLetters, $text) !== 1 && $text === ($english[$key] ?? null) && ! str_contains($key, 'currency')) {
                $untranslated[] = $key;
            }
        }

        $this->assertSame([], $leaked, 'Arabic text in the English file');
        $this->assertSame([], $untranslated, 'English text left in the Arabic file');
    }

    public function test_plural_forms_are_written_the_way_the_translator_reads_them(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach ($this->staticKeysInUse() as $key => $file) {
                $isChoice = (bool) preg_match('/trans_choice\(\s*[\'"]'.preg_quote($key, '/').'[\'"]/', File::get(
                    collect($this->panelSourceFiles())->first(fn ($path) => basename($path) === $file) ?? ''
                ));

                if ($isChoice) {
                    $text = (string) Lang::get($key, [], $locale);

                    $this->assertStringContainsString('|', $text, "$key in $locale is used as a plural but has one form");
                }
            }
        }
    }

    public function test_the_sidebar_only_offers_pages_that_exist_and_has_a_label_for_each(): void
    {
        $owner = User::factory()->owner()->make(['id' => 1]);

        foreach (PanelNav::for($owner) as $links) {
            foreach ($links as $link) {
                $this->assertNotSame('', $link['label']);
                $this->assertStringStartsWith(url('/panel'), $link['url']);
            }
        }

        $this->assertCount(14, collect(PanelNav::for($owner))->flatten(1), 'every module has a page');
    }
}
