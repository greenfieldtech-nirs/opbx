<?php

declare(strict_types=1);

namespace App\Services\Email\DTOs;

/**
 * Email Recipient DTO
 *
 * Represents an email recipient with email address and optional name.
 */
readonly class EmailRecipient
{
    /**
     * Create a new email recipient.
     *
     * @param  string  $email  The email address
     * @param  string|null  $name  The optional display name
     */
    public function __construct(
        public string $email,
        public ?string $name = null
    ) {}

    /**
     * Create a recipient from an array.
     *
     * @param  array  $data  Array with 'email' and optional 'name' keys
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'],
            name: $data['name'] ?? null
        );
    }

    /**
     * Format the recipient for email headers.
     *
     * @return string Formatted as "Name <email>" or just "email"
     */
    public function format(): string
    {
        if ($this->name) {
            return sprintf('"%s" <%s>', $this->name, $this->email);
        }

        return $this->email;
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
}
