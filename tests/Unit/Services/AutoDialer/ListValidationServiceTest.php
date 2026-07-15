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
        $result = $this->service->validatePhoneNumber('+447770123456');

        $this->assertTrue($result->valid);
        $this->assertEquals('+447770123456', $result->normalizedNumber);
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
            ['phone_number' => '+14155551212', 'name' => 'Valid 1'],
            ['phone_number' => '+14155551213', 'name' => 'Valid 2'],
            ['phone_number' => 'invalid', 'name' => 'Invalid'],
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

    /** @test */
    public function it_normalizes_non_utf8_csv_preview_to_valid_utf8(): void
    {
        // Row containing Windows-1255 (Hebrew) encoded bytes for "אהרון".
        $hebrewBytes = "\xE0\xE4\xF8\xE5\xEF";
        $csv = "phone_number,description,ID\r\n+972546828882,{$hebrewBytes},303607923\r\n";

        $path = $this->writeTempCsv($csv);

        try {
            $preview = $this->service->parseCsvPreview($path, true, 5);

            // The preview must be JSON-encodable (the original 500 root cause).
            $json = json_encode(['data' => $preview]);
            $this->assertNotFalse($json, 'Preview should be JSON-encodable');
            $this->assertSame(JSON_ERROR_NONE, json_last_error());

            $this->assertSame('אהרון', $preview['rows'][0]['description']);
            $this->assertSame('+972546828882', $preview['rows'][0]['phone_number']);
        } finally {
            @unlink($path);
        }
    }

    /** @test */
    public function it_skips_trailing_blank_rows_in_csv_preview(): void
    {
        $csv = "phone_number,description,ID\r\n+972546828882,Aaron,303607923\r\n\r\n\r\n";

        $path = $this->writeTempCsv($csv);

        try {
            $preview = $this->service->parseCsvPreview($path, true, 5);

            $this->assertSame(1, $preview['total_rows']);
            $this->assertCount(1, $preview['rows']);
        } finally {
            @unlink($path);
        }
    }

    /** @test */
    public function it_strips_utf8_bom_from_csv_headers(): void
    {
        $csv = "\xEF\xBB\xBFphone_number,description\r\n+14155551212,Test\r\n";

        $path = $this->writeTempCsv($csv);

        try {
            $preview = $this->service->parseCsvPreview($path, true, 5);

            $this->assertSame('phone_number', $preview['headers'][0]);
        } finally {
            @unlink($path);
        }
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($path, $contents);

        return $path;
    }
}
