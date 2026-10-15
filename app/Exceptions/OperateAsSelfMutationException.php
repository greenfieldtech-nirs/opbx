<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the User saving() guard when a request tries to persist the
 * platform owner's own account while they are operating as an organization.
 * Rendered as a 403 OPERATE_AS_SELF_MUTATION_FORBIDDEN in bootstrap/app.php.
 */
class OperateAsSelfMutationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Refusing to persist an operate-as effective user. The platform '.
            'owner cannot modify their own account while operating as an organization.'
        );
    }
}
