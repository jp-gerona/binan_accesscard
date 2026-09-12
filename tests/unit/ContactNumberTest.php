<?php

use App\Support\ContactNumber;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ContactNumberTest extends CIUnitTestCase
{
    public function testParsesAcceptedMobileAndBinanLandlineToDigits(): void
    {
        $this->assertSame(
            ['value' => '09171234567', 'supplied' => true, 'valid' => true],
            ContactNumber::parse('0917-123-4567')
        );
        $this->assertSame(
            ['value' => '0491234567', 'supplied' => true, 'valid' => true],
            ContactNumber::parse('(049) 123-4567')
        );
    }

    public function testDistinguishesBlankContactFromMalformedSuppliedContact(): void
    {
        $this->assertSame(
            ['value' => null, 'supplied' => false, 'valid' => true],
            ContactNumber::parse('')
        );
        $this->assertSame(
            ['value' => null, 'supplied' => true, 'valid' => false],
            ContactNumber::parse('0917-12')
        );
    }
}
