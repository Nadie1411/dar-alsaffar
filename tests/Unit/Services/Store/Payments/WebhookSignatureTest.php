<?php

namespace Tests\Unit\Services\Store\Payments;

use App\Services\Store\Payments\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'test-secret-key';

    /**
     * The signature computed with the `openssl` command line — not with this
     * class's own logic — for the string MyFatoorah's documentation gives:
     *   Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,
     *   Transaction.PaymentId=07076409988323998875,Invoice.ExternalIdentifier=asdqwd-f13sdf-fasjkz
     */
    private const DOCUMENTED = 'z0PP7PwjRfpiIU0XLFIp7vv1zx4ENLwSoh3oA9QcNJY=';

    /** The same, with every missing value written as an empty string. */
    private const WITH_EMPTIES = 'dEehaQJ5o/1f8qdJMJwTC9/KkblQ2aKxJ6BGIE/EzOY=';

    /**
     * @return array<string,mixed>
     */
    private function documentedEvent(): array
    {
        return ['Data' => [
            'Invoice' => ['Id' => '6409988', 'Status' => 'PAID', 'ExternalIdentifier' => 'asdqwd-f13sdf-fasjkz'],
            'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => '07076409988323998875'],
        ]];
    }

    public function test_signs_the_documented_example_exactly_as_openssl_does(): void
    {
        $this->assertSame(self::DOCUMENTED, (new WebhookSignature)->sign($this->documentedEvent(), self::SECRET));
    }

    public function test_a_missing_value_counts_as_an_empty_string(): void
    {
        $event = ['Data' => [
            'Invoice' => ['Id' => '6409988', 'Status' => 'PENDING', 'ExternalIdentifier' => null],
            'Transaction' => ['Status' => 'FAILED'],
        ]];

        $this->assertSame(self::WITH_EMPTIES, (new WebhookSignature)->sign($event, self::SECRET));
    }

    public function test_accepts_the_right_signature_and_nothing_else(): void
    {
        $signature = new WebhookSignature;
        $event = $this->documentedEvent();

        $this->assertTrue($signature->isValid($event, self::DOCUMENTED, self::SECRET));
        $this->assertTrue($signature->isValid($event, '  '.self::DOCUMENTED."\n", self::SECRET));
        $this->assertFalse($signature->isValid($event, self::WITH_EMPTIES, self::SECRET));
        $this->assertFalse($signature->isValid($event, self::DOCUMENTED, 'another-secret'));
        $this->assertFalse($signature->isValid($event, null, self::SECRET));
        $this->assertFalse($signature->isValid($event, '', self::SECRET));
        $this->assertFalse($signature->isValid($event, self::DOCUMENTED, ''));
    }

    public function test_changing_any_of_the_five_signed_fields_breaks_the_signature(): void
    {
        $signature = new WebhookSignature;

        foreach ([
            'Data.Invoice.Id' => '6409989',
            'Data.Invoice.Status' => 'PENDING',
            'Data.Transaction.Status' => 'FAILED',
            'Data.Transaction.PaymentId' => '07076409988323998876',
            'Data.Invoice.ExternalIdentifier' => 'someone-elses-order',
        ] as $path => $value) {
            $event = $this->documentedEvent();
            data_set($event, $path, $value);

            $this->assertFalse($signature->isValid($event, self::DOCUMENTED, self::SECRET), $path);
        }
    }

    public function test_fields_outside_the_signed_five_can_change_without_effect(): void
    {
        $event = $this->documentedEvent();
        $event['Data']['Amount']['ValueInBaseCurrency'] = '999';

        $this->assertTrue((new WebhookSignature)->isValid($event, self::DOCUMENTED, self::SECRET));
    }
}
