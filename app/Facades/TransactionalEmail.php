<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use Illuminate\Support\Facades\Facade;

/**
 * Transactional Email Facade
 *
 * @method static \App\Services\Email\DTOs\EmailSendResult send(\App\Services\Email\DTOs\EmailMessage $message)
 * @method static string sendAsync(\App\Services\Email\DTOs\EmailMessage $message)
 * @method static bool supportsAttachments()
 * @method static bool supportsTemplates()
 * @method static string getDriverName()
 * @method static bool healthCheck()
 *
 * @see \App\Services\Email\Contracts\TransactionalEmailInterface
 */
class TransactionalEmail extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return TransactionalEmailInterface::class;
    }
}
