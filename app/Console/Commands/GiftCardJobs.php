<?php

namespace App\Console\Commands;

use App\Services\GiftCardService;
use Illuminate\Console\Command;

/** Every few minutes: send gift cards whose delivery date has come, and expire cards past their date. */
class GiftCardJobs extends Command
{
    protected $signature = 'lavish:gift-cards';

    protected $description = 'Deliver scheduled gift cards and expire old ones';

    public function handle(GiftCardService $cards): int
    {
        $this->line('delivered: '.$cards->deliverDue());
        $this->line('expired: '.$cards->expireDue());

        return self::SUCCESS;
    }
}
