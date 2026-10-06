<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\IsolatesSettings;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class OfferCodeTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase, UsesLocalStore;

    public function test_the_code_in_the_green_bar_and_in_the_popup_can_be_copied(): void
    {
        Voucher::factory()->public()->create(['code' => 'EID25']);

        $html = $this->get('/ar-KW')->assertOk()->getContent();

        // One button in the bar under the header, one in the pop-up.
        $this->assertSame(2, substr_count($html, 'data-copy-code="EID25"'));
        $this->assertStringContainsString('نسخ كود الخصم EID25', $html);
    }

    public function test_the_copy_confirmation_is_translated(): void
    {
        Voucher::factory()->public()->create(['code' => 'EID25']);

        $this->get('/en-KW')->assertOk()->assertSee('Discount code copied', false);
        // The toast text travels to the script as JSON, which escapes Arabic.
        $this->get('/ar-KW')->assertOk()->assertSee(trim(json_encode('تم نسخ كود الخصم'), '"'), false);
    }

    public function test_without_a_live_offer_there_is_no_code_to_copy(): void
    {
        $this->get('/ar-KW')->assertOk()->assertDontSee('data-copy-code', false);
    }
}
