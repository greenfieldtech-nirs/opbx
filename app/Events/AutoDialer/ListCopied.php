<?php

declare(strict_types=1);

namespace App\Events\AutoDialer;

use App\Models\AutoDialerList;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListCopied
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AutoDialerList $sourceList,
        public AutoDialerList $newList,
        public int $userId,
    ) {}
}
