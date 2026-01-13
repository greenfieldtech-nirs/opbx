<?php

namespace Tests\Unit;

use App\Services\PhoneNumberService;
use Tests\TestCase;

class PhoneNumberServiceTest extends TestCase
{
    private PhoneNumberService $phoneService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phoneService = new PhoneNumberService();
    }

    public function test_extract_calling_code_us_number()
    {
        $result = $this->phoneService->extractCallingCode('+15551234567');
        $this->assertEquals('+1', $result);
    }

    public function test_extract_calling_code_uk_number()
    {
        $result = $this->phoneService->extractCallingCode('+442071234567');
        $this->assertEquals('+44', $result);
    }

    public function test_extract_calling_code_invalid_number()
    {
        $result = $this->phoneService->extractCallingCode('invalid');
        $this->assertNull($result);
    }

    public function test_calling_code_to_country_code_us()
    {
        $result = $this->phoneService->callingCodeToCountryCode('+1');
        $this->assertEquals('US', $result);
    }

    public function test_calling_code_to_country_code_uk()
    {
        $result = $this->phoneService->callingCodeToCountryCode('+44');
        $this->assertEquals('GB', $result);
    }

    public function test_calling_code_to_country_code_invalid()
    {
        $result = $this->phoneService->callingCodeToCountryCode('+999');
        $this->assertNull($result);
    }

    public function test_validate_and_format_valid_number()
    {
        // Use a more complete US number that's likely to be valid
        $result = $this->phoneService->validateAndFormatPhoneNumber('+15551234567');

        // The exact validation may vary, so just check that we get a result
        $this->assertIsArray($result);
        $this->assertArrayHasKey('formatted', $result);
        $this->assertArrayHasKey('country_code', $result);
        $this->assertArrayHasKey('is_valid', $result);
        $this->assertTrue($result['is_valid']);
    }

    public function test_validate_and_format_invalid_number()
    {
        $result = $this->phoneService->validateAndFormatPhoneNumber('invalid');
        $this->assertNull($result);
    }

    public function test_extract_phone_components()
    {
        $result = $this->phoneService->extractPhoneComponents('+15551234567');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('calling_code', $result);
        $this->assertArrayHasKey('national_number', $result);
        $this->assertArrayHasKey('country_code', $result);
        $this->assertArrayHasKey('formatted_e164', $result);
        $this->assertEquals('+1', $result['calling_code']);
    }

    public function test_extract_phone_components_invalid()
    {
        $result = $this->phoneService->extractPhoneComponents('invalid');
        $this->assertNull($result);
    }

    public function test_international_numbers()
    {
        // Test various international calling codes
        $testCases = [
            ['+33123456789', '+33'], // France
            ['+442071234567', '+44'], // UK
            ['+819012345678', '+81'], // Japan
        ];

        foreach ($testCases as [$number, $expectedCode]) {
            $callingCode = $this->phoneService->extractCallingCode($number);

            $this->assertEquals($expectedCode, $callingCode, "Wrong calling code for: $number");
        }
    }
}