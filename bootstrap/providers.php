<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\Email\Providers\EmailServiceProvider;

return [
    AppServiceProvider::class,
    EmailServiceProvider::class,
];
