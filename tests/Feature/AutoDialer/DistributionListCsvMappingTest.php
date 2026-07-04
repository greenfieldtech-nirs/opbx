<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DistributionListCsvMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private AutoDialerList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
        $this->list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'draft',
        ]);

        Storage::fake('local');
    }

    /** @test */
    public function it_previews_csv_headers_and_rows(): void
    {
        $csv = "phone,full_name,batch_id,account\n+14155551212,John Doe,batch-1,ACC-123\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/preview-csv", [
                'file' => $file,
                'has_header' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.headers', ['phone', 'full_name', 'batch_id', 'account'])
            ->assertJsonPath('data.total_rows', 1);
    }

    /** @test */
    public function it_accepts_has_header_as_string_from_form_data(): void
    {
        $csv = "phone,full_name\n+14155551212,John Doe\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/preview-csv", [
                'file' => $file,
                'has_header' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('data.headers', ['phone', 'full_name'])
            ->assertJsonPath('data.total_rows', 1);
    }

    /** @test */
    public function it_uploads_csv_with_mapping_and_creates_destinations(): void
    {
        $csv = "phone,full_name,batch_id,account\n+14155551212,John Doe,batch-1,ACC-123\n+14155551213,Jane Smith,batch-2,ACC-456\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => $file,
                'mapping' => [
                    'phone' => 'phone',
                    'name' => 'full_name',
                    'batch_identifier' => 'batch_id',
                    'metadata' => ['account'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'upload');

        $destination = AutoDialerDestination::where('list_id', $this->list->id)
            ->where('phone_number', '+14155551212')
            ->first();

        $this->assertNotNull($destination);
        $this->assertSame('John Doe', $destination->name);
        $this->assertSame('batch-1', $destination->batch_identifier);
        $this->assertSame(['account' => 'ACC-123'], $destination->metadata);
    }

    /** @test */
    public function it_rejects_upload_without_phone_mapping(): void
    {
        $csv = "phone\n+14155551212\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => $file,
                'mapping' => ['name' => 'phone'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mapping.phone']);
    }
}
