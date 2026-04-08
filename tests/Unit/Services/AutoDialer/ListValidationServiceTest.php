<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\ListValidationService;
use App\Services\AutoDialer\ValidationResult;
use PHPUnit\Framework\TestCase;

class ListValidationServiceTest extends TestCase
{
    private ListValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ListValidationService;
    }

    /** @test */
    public function it_validates_valid_us_phone_number(): void
    {
        $result = $this->service->validatePhoneNumber('+14155551212');

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->valid);
        $this->assertEquals('+14155551212', $result->normalizedNumber);
        $this->assertNull($result->error);
    }

    /** @test */
    public function it_validates_valid_uk_phone_number(): void
    {
        $result = $this->service->validatePhoneNumber('+447700900123');

        $this->assertTrue($result->valid);
        $this->assertEquals('+447700900123', $result->normalizedNumber);
    }

    /** @test */
    public function it_validates_phone_number_without_plus(): void
    {
        $result = $this->service->validatePhoneNumber('4155551212', 'US');

        $this->assertTrue($result->valid);
        $this->assertEquals('+14155551212', $result->normalizedNumber);
    }

    /** @test */
    public function it_rejects_invalid_phone_number(): void
    {
        $result = $this->service->validatePhoneNumber('not-a-number');

        $this->assertFalse($result->valid);
        $this->assertNotNull($result->error);
        $this->assertNull($result->normalizedNumber);
    }

    /** @test */
    public function it_rejects_empty_phone_number(): void
    {
        $result = $this->service->validatePhoneNumber('');

        $this->assertFalse($result->valid);
        $this->assertEquals('Phone number is empty', $result->error);
    }

    /** @test */
    public function it_rejects_too_short_phone_number(): void
    {
        $result = $this->service->validatePhoneNumber('+123');

        $this->assertFalse($result->valid);
    }

    /** @test */
    public function it_validates_batch_of_phone_numbers(): void
    {
        $entries = [
            ['phone_number' => '+14155551212', 'description' => 'Valid 1'],
            ['phone_number' => '+14155551213', 'description' => 'Valid 2'],
            ['phone_number' => 'invalid', 'description' => 'Invalid'],
        ];

        $result = $this->service->batchValidate($entries);

        $this->assertEquals(2, $result->validCount);
        $this->assertEquals(1, $result->invalidCount);
    }

    /** @test */
    public function it_detects_duplicates_in_list(): void
    {
        $phoneNumbers = [
            '+14155551212',
            '+14155551213',
            '+14155551212', // duplicate
            '+14155551214',
        ];

        $duplicates = $this->service->findDuplicates($phoneNumbers);

        $this->assertCount(1, $duplicates);
        $this->assertEquals('+14155551212', $duplicates[0]['phone_number']);
        $this->assertEquals([0, 2], $duplicates[0]['indices']);
    }

    /** @test */
    public function it_cleans_phone_number_before_validation(): void
    {
        $result = $this->service->validatePhoneNumber('(415) 555-1212', 'US');

        $this->assertTrue($result->valid);
        $this->assertEquals('+14155551212', $result->normalizedNumber);
    }
}
