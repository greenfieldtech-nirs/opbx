<?php

declare(strict_types=1);

namespace App\Services\Email\DTOs;

/**
 * Email Attachment DTO
 *
 * Represents a file attachment for an email.
 */
readonly class EmailAttachment
{
    /**
     * Create a new email attachment.
     *
     * @param  string  $filename  The filename for the attachment
     * @param  string  $content  The file content (base64 encoded or raw binary)
     * @param  string|null  $mimeType  The MIME type (auto-detected if null)
     * @param  bool  $isBase64  Whether the content is base64 encoded
     */
    public function __construct(
        public string $filename,
        public string $content,
        public ?string $mimeType = null,
        public bool $isBase64 = false
    ) {}

    /**
     * Create an attachment from a file path.
     *
     * @param  string  $path  The file path
     * @param  string|null  $filename  Optional custom filename
     *
     * @throws \InvalidArgumentException If file doesn't exist
     */
    public static function fromFile(string $path, ?string $filename = null): self
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        $filename ??= basename($path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return new self(
            filename: $filename,
            content: $content,
            mimeType: $mimeType,
            isBase64: false
        );
    }

    /**
     * Create an attachment from base64 content.
     *
     * @param  string  $filename  The filename
     * @param  string  $base64Content  Base64 encoded content
     * @param  string|null  $mimeType  The MIME type
     */
    public static function fromBase64(string $filename, string $base64Content, ?string $mimeType = null): self
    {
        return new self(
            filename: $filename,
            content: $base64Content,
            mimeType: $mimeType,
            isBase64: true
        );
    }

    /**
     * Get the content as base64 encoded string.
     */
    public function getBase64Content(): string
    {
        if ($this->isBase64) {
            return $this->content;
        }

        return base64_encode($this->content);
    }

    /**
     * Get the raw content (decoded if base64).
     */
    public function getRawContent(): string
    {
        if ($this->isBase64) {
            return base64_decode($this->content);
        }

        return $this->content;
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'content' => $this->getBase64Content(),
            'mime_type' => $this->mimeType,
        ];
    }
}
