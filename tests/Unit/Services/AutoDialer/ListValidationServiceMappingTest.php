<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\ListValidationService;
use Tests\TestCase;

class ListValidationServiceMappingTest extends TestCase
{
    private ListValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ListValidationService;
    }

    /** @test */
    public function it_maps_name_batch_and_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "phone,full_name,batch_id,account\n+14155551212,John,batch-a,ACC-1\n");

        $result = $this->service->validateCsvFile($path, 1, [
            'phone' => 'phone',
            'name' => 'full_name',
            'batch_identifier' => 'batch_id',
            'metadata' => ['account'],
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->validRows);
        $this->assertSame('John', $result->validRows[0]['name']);
        $this->assertSame('batch-a', $result->validRows[0]['batch_identifier']);
        $this->assertSame(['account' => 'ACC-1'], $result->validRows[0]['metadata']);

        unlink($path);
    }

    /** @test */
    public function it_fails_when_phone_column_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "name\nJohn\n");

        $result = $this->service->validateCsvFile($path, 1, ['phone' => 'phone']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('phone_number', $result->error);

        unlink($path);
    }

    /** @test */
    public function it_previews_headers_and_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($path, "phone,name\n+14155551212,John\n+14155551213,Jane\n");

        $preview = $this->service->parseCsvPreview($path, true, 5);

        $this->assertSame(['phone', 'name'], $preview['headers']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(2, $preview['total_rows']);

        unlink($path);
    }
}
