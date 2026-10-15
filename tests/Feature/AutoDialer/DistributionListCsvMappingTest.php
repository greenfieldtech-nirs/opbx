<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Jobs\ProcessListUploadJob;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\ListValidationService;
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
    public function it_records_the_original_filename_and_exposes_it_on_the_list(): void
    {
        $file = UploadedFile::fake()->createWithContent('webinar-leads.csv', "phone\n+14155551212\n");

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => $file,
                'mapping' => ['phone' => 'phone'],
            ])
            ->assertOk();

        // The job runs inline here, like a worker finishing before the request returns:
        // the list must end READY, not be reset to PROCESSING afterwards.
        $this->list->refresh();
        $this->assertSame('webinar-leads.csv', $this->list->original_filename);
        $this->assertSame('ready', $this->list->status->value);

        $this->actingAs($this->user)
            ->getJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}")
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'webinar-leads.csv');
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

    /** @test */
    public function it_processes_upload_job_without_authenticated_user(): void
    {
        $csv = "phone,full_name\n+14155551212,John Doe\n";
        $fullPath = tempnam(sys_get_temp_dir(), 'upload_test_').'.csv';
        file_put_contents($fullPath, $csv);

        try {
            // Simulate queue worker context: no authenticated user
            auth()->forgetGuards();

            $job = new ProcessListUploadJob(
                $this->list->id,
                $fullPath,
                'test-job-id',
                false,
                ['phone' => 'phone', 'name' => 'full_name']
            );
            $job->handle(app(ListValidationService::class));

            $this->assertDatabaseHas('auto_dialer_destinations', [
                'list_id' => $this->list->id,
                'phone_number' => '+14155551212',
                'name' => 'John Doe',
            ]);

            $this->list->refresh();
            $this->assertSame('ready', $this->list->status->value);
        } finally {
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    /** @test */
    public function it_previews_and_uploads_a_headerless_csv_keeping_the_first_row(): void
    {
        $csv = "+14155551212,John Doe\n+14155551213,Jane Smith\n";

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/preview-csv", [
                'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
                'has_header' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.headers', ['column_1', 'column_2'])
            ->assertJsonPath('data.total_rows', 2);

        $this->actingAs($this->user)
            ->postJson("/api/v1/auto-dialer-campaigns/lists/{$this->list->id}/upload", [
                'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
                'has_header' => false,
                'mapping' => ['phone' => 'column_1', 'name' => 'column_2'],
            ])
            ->assertOk();

        $this->assertSame(2, AutoDialerDestination::where('list_id', $this->list->id)->count());
        $this->assertDatabaseHas('auto_dialer_destinations', ['list_id' => $this->list->id, 'phone_number' => '+14155551212', 'name' => 'John Doe']);
    }
}
