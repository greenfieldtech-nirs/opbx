<?php

declare(strict_types=1);

namespace App\Services\Email\DTOs;

/**
 * Email Message DTO
 *
 * Represents a complete email message to be sent.
 */
class EmailMessage
{
    /**
     * Unique correlation ID for request tracing.
     */
    public ?string $correlationId = null;

    /**
     * Create a new email message.
     *
     * @param  EmailRecipient  $from  The sender
     * @param  array  $to  Array of EmailRecipient objects
     * @param  string  $subject  The email subject
     * @param  string|null  $htmlContent  HTML content (optional if using template)
     * @param  string|null  $textContent  Plain text content (optional)
     * @param  array  $cc  CC recipients
     * @param  array  $bcc  BCC recipients
     * @param  string|null  $templateId  Template ID for template-based emails
     * @param  array  $templateData  Data for template variables
     * @param  array  $attachments  Array of EmailAttachment objects
     * @param  array  $headers  Custom headers
     * @param  array  $tags  Tags for tracking
     */
    public function __construct(
        public EmailRecipient $from,
        public array $to,
        public string $subject,
        public ?string $htmlContent = null,
        public ?string $textContent = null,
        public array $cc = [],
        public array $bcc = [],
        public ?string $templateId = null,
        public array $templateData = [],
        public array $attachments = [],
        public array $headers = [],
        public array $tags = []
    ) {
        $this->correlationId = $this->generateCorrelationId();
    }

    /**
     * Create a message from an array.
     */
    public static function fromArray(array $data): self
    {
        $message = new self(
            from: EmailRecipient::fromArray($data['from']),
            to: array_map(fn ($r) => EmailRecipient::fromArray($r), $data['to']),
            subject: $data['subject'],
            htmlContent: $data['html_content'] ?? null,
            textContent: $data['text_content'] ?? null,
            cc: array_map(fn ($r) => EmailRecipient::fromArray($r), $data['cc'] ?? []),
            bcc: array_map(fn ($r) => EmailRecipient::fromArray($r), $data['bcc'] ?? []),
            templateId: $data['template_id'] ?? null,
            templateData: $data['template_data'] ?? [],
            attachments: array_map(
                fn ($a) => $a instanceof EmailAttachment ? $a : EmailAttachment::fromArray($a),
                $data['attachments'] ?? []
            ),
            headers: $data['headers'] ?? [],
            tags: $data['tags'] ?? []
        );

        if (isset($data['correlation_id'])) {
            $message->correlationId = $data['correlation_id'];
        }

        return $message;
    }

    /**
     * Set the from address fluently.
     *
     * @return $this
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->from = new EmailRecipient($email, $name);

        return $this;
    }

    /**
     * Add a recipient fluently.
     *
     * @return $this
     */
    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = new EmailRecipient($email, $name);

        return $this;
    }

    /**
     * Set the subject fluently.
     *
     * @return $this
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set HTML content fluently.
     *
     * @return $this
     */
    public function html(string $content): self
    {
        $this->htmlContent = $content;

        return $this;
    }

    /**
     * Set text content fluently.
     *
     * @return $this
     */
    public function text(string $content): self
    {
        $this->textContent = $content;

        return $this;
    }

    /**
     * Add an attachment fluently.
     *
     * @return $this
     */
    public function attach(EmailAttachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * Add a file attachment from path fluently.
     *
     * @return $this
     */
    public function attachFile(string $path, ?string $filename = null): self
    {
        $this->attachments[] = EmailAttachment::fromFile($path, $filename);

        return $this;
    }

    /**
     * Use a template fluently.
     *
     * @return $this
     */
    public function template(string $templateId, array $data = []): self
    {
        $this->templateId = $templateId;
        $this->templateData = $data;

        return $this;
    }

    /**
     * Add a tag fluently.
     *
     * @return $this
     */
    public function tag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    /**
     * Check if this message uses a template.
     */
    public function isTemplated(): bool
    {
        return $this->templateId !== null;
    }

    /**
     * Check if this message has attachments.
     */
    public function hasAttachments(): bool
    {
        return count($this->attachments) > 0;
    }

    /**
     * Generate a correlation ID for request tracing.
     */
    private function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'from' => $this->from->toArray(),
            'to' => array_map(fn ($r) => $r->toArray(), $this->to),
            'subject' => $this->subject,
            'html_content' => $this->htmlContent,
            'text_content' => $this->textContent,
            'cc' => array_map(fn ($r) => $r->toArray(), $this->cc),
            'bcc' => array_map(fn ($r) => $r->toArray(), $this->bcc),
            'template_id' => $this->templateId,
            'template_data' => $this->templateData,
            'attachments' => array_map(fn ($a) => $a->toArray(), $this->attachments),
            'headers' => $this->headers,
            'tags' => $this->tags,
        ];
    }
}
