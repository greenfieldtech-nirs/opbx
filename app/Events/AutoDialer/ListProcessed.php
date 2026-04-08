<?php

declare(strict_types=1);

namespace App\Events\AutoDialer;

use App\Models\AutoDialerList;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListProcessed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AutoDialerList $list,
        public int $validRows,
        public int $invalidRows,
    ) {}
}
