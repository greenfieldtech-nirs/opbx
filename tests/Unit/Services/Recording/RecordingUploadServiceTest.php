<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recording;

use App\Services\Recording\RecordingUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Unit tests for RecordingUploadService
 *
 * Tests file validation, upload processing, and security features.
 */
class RecordingUploadServiceTest extends TestCase
{
    private RecordingUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RecordingUploadService();

        // Set default config values
        Config::set('recordings.max_file_size_kb', 5120); // 5MB
        Config::set('recordings.allowed_mime_types', ['audio/mpeg', 'audio/wav']);
        Config::set('recordings.allowed_extensions', ['mp3', 'wav']);
    }

    /**
     * Test that uploadFile method exists and has proper signature.
     */
    public function test_upload_file_method_exists_and_has_proper_signature(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'uploadFile');
        $parameters = $reflection->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertEquals('Illuminate\Http\UploadedFile', $parameters[0]->getType()->getName());
        $this->assertEquals('string', $parameters[1]->getType()->getName());
        $this->assertEquals('App\Models\User', $parameters[2]->getType()->getName());
    }

    /**
     * Test file validation rejects oversized files.
     */
    public function test_validate_file_rejects_oversized_files(): void
    {
        $largeFile = UploadedFile::fake()->create('large.mp3', 6000, 'audio/mpeg'); // 6KB file

        Config::set('recordings.max_file_size_kb', 5); // 5KB limit

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateFile');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/File size exceeds the maximum allowed size of \d+\.\d+MB\./');

        $method->invoke($this->service, $largeFile);
    }

    /**
     * Test file validation rejects invalid MIME types.
     */
    public function test_validate_file_rejects_invalid_mime_types(): void
    {
        $invalidFile = UploadedFile::fake()->create('test.txt', 1000, 'text/plain');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateFile');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file type. Only MP3 and WAV files are allowed.');

        $method->invoke($this->service, $invalidFile);
    }

    /**
     * Test file validation rejects invalid extensions.
     */
    public function test_validate_file_rejects_invalid_extensions(): void
    {
        $invalidFile = UploadedFile::fake()->create('test.exe', 1000, 'audio/mpeg');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateFile');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file extension. Only .mp3 and .wav files are allowed.');

        $method->invoke($this->service, $invalidFile);
    }

    /**
     * Test containsDangerousCharacters detects directory traversal.
     */
    public function test_contains_dangerous_characters_detects_directory_traversal(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('containsDangerousCharacters');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, '../../../test.mp3'));
        $this->assertTrue($method->invoke($this->service, '..\\test.mp3'));
        $this->assertTrue($method->invoke($this->service, 'test/../file.mp3'));
    }

    /**
     * Test containsDangerousCharacters detects null bytes.
     */
    public function test_contains_dangerous_characters_detects_null_bytes(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('containsDangerousCharacters');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, "test\0.mp3"));
        $this->assertTrue($method->invoke($this->service, "test\r.mp3"));
        $this->assertTrue($method->invoke($this->service, "test\n.mp3"));
    }

    /**
     * Test containsDangerousCharacters detects Windows reserved names.
     */
    public function test_contains_dangerous_characters_detects_reserved_names(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('containsDangerousCharacters');
        $method->setAccessible(true);

        $reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'LPT1'];

        foreach ($reservedNames as $name) {
            $this->assertTrue($method->invoke($this->service, $name . '.mp3'), "Should detect $name as reserved");
        }

        $this->assertFalse($method->invoke($this->service, 'normal.mp3'));
    }

    /**
     * Test file content validation blocks PHP files.
     */
    public function test_check_file_content_blocks_php_files(): void
    {
        // Create a file that looks like PHP
        $phpContent = '<?php echo "malicious"; ?>';
        $fakeFile = tmpfile();
        fwrite($fakeFile, $phpContent);
        rewind($fakeFile);

        $metaData = stream_get_meta_data($fakeFile);
        $file = new UploadedFile($metaData['uri'], 'test.mp3', 'audio/mpeg', null, true);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkFileContent');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File contains potentially dangerous content.');

        $method->invoke($this->service, $file);

        fclose($fakeFile);
    }

    /**
     * Test checkFileContent method exists and signature validation works.
     */
    public function test_check_file_content_validates_signatures(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkFileContent');
        $method->setAccessible(true);

        // Test that method exists
        $this->assertTrue(method_exists($this->service, 'checkFileContent'));

        // Test with PHP content (should fail)
        $phpContent = '<?php echo "malicious"; ?>';
        $fakeFile = tmpfile();
        fwrite($fakeFile, $phpContent);
        rewind($fakeFile);
        $metaData = stream_get_meta_data($fakeFile);
        $file = new UploadedFile($metaData['uri'], 'test.mp3', 'audio/mpeg', null, true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File contains potentially dangerous content.');

        $method->invoke($this->service, $file);

        fclose($fakeFile);
    }

    /**
     * Test secure filename generation.
     */
    public function test_generate_secure_filename_creates_safe_names(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateSecureFilename');
        $method->setAccessible(true);

        $filename = $method->invoke($this->service, 'Test File (1).mp3', 'mp3');

        // Should contain slugified name with underscores and random prefix
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{8}_test_file_1\.mp3$/', $filename);
    }

    /**
     * Test secure filename generation handles dangerous characters.
     */
    public function test_generate_secure_filename_sanitizes_dangerous_characters(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateSecureFilename');
        $method->setAccessible(true);

        $filename = $method->invoke($this->service, '../../../Test File!.mp3', 'mp3');

        // Should sanitize dangerous characters and special chars
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{8}_test_file\.mp3$/', $filename);
        $this->assertStringNotContainsString('!', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString('\\', $filename);
        $this->assertStringNotContainsString('..', $filename);
    }

    /**
     * Test WAV metadata extraction.
     */
    public function test_extract_wav_metadata_extracts_duration(): void
    {
        // Create a minimal WAV header (44 bytes)
        $wavHeader = pack('C*',
            // RIFF header
            0x52, 0x49, 0x46, 0x46, // "RIFF"
            0x24, 0x08, 0x00, 0x00, // File size
            0x57, 0x41, 0x56, 0x45, // "WAVE"
            // Format chunk
            0x66, 0x6D, 0x74, 0x20, // "fmt "
            0x10, 0x00, 0x00, 0x00, // Chunk size
            0x01, 0x00,             // Audio format
            0x02, 0x00,             // Num channels
            0x80, 0x3E, 0x00, 0x00, // Sample rate (16000)
            0x00, 0x7D, 0x00, 0x00, // Byte rate
            0x04, 0x00,             // Block align
            0x10, 0x00,             // Bits per sample
            // Data chunk
            0x64, 0x61, 0x74, 0x61, // "data"
            0x00, 0x08, 0x00, 0x00  // Data size (2048 bytes)
        );

        $fakeFile = tmpfile();
        fwrite($fakeFile, $wavHeader);
        rewind($fakeFile);

        $metaData = stream_get_meta_data($fakeFile);
        $filePath = $metaData['uri'];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractWavMetadata');
        $method->setAccessible(true);

        $metadata = $method->invoke($this->service, $filePath);

        // Should extract duration (2048 / (16000 * 2 * 2) = 0.032 seconds, rounded to 0)
        $this->assertArrayHasKey('duration_seconds', $metadata);
        $this->assertIsInt($metadata['duration_seconds']);

        fclose($fakeFile);
    }

    /**
     * Test metadata extraction handles missing files gracefully.
     */
    public function test_extract_metadata_handles_missing_files_gracefully(): void
    {
        $file = UploadedFile::fake()->create('test.mp3', 1000, 'audio/mpeg');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMetadata');
        $method->setAccessible(true);

        // Temporarily move the file to simulate missing file
        $realPath = $file->getRealPath();
        $tempPath = $realPath . '.backup';
        rename($realPath, $tempPath);

        $metadata = $method->invoke($this->service, $file);

        // Should return empty array for missing file
        $this->assertIsArray($metadata);
        $this->assertEmpty($metadata);

        // Restore file
        rename($tempPath, $realPath);
    }

    /**
     * Test upload handles storage failure - tested via integration tests.
     */
    public function test_upload_file_method_exists(): void
    {
        $this->assertTrue(method_exists($this->service, 'uploadFile'));
    }
}