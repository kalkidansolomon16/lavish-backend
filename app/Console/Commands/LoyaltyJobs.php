<?php

namespace App\Console\Commands;

use App\Services\LoyaltyService;
use Illuminate\Console\Command;

/**
 * Lavish Rewards scheduled work (see routes/console.php):
 *   nightly — expire points and vouchers past their date, recalculate 12-month points and tiers
 *   campaigns — send a campaign's app notice to members when it starts
 *   daily   — remind clients 14 days before points expire, give birthday points
 */
class LoyaltyJobs extends Command
{
    protected $signature = 'lavish:loyalty {job : nightly|daily|campaigns}';

    protected $description = 'Run the Lavish Rewards nightly or daily job';

    public function handle(LoyaltyService $loyalty): int
    {
        $result = match ($this->argument('job')) {
            'nightly' => $loyalty->nightly(),
            'daily' => $loyalty->daily(),
            'campaigns' => $loyalty->announceCampaigns(),
            default => null,
        };
        if ($result === null) {
            $this->error('Use: lavish:loyalty nightly | daily | campaigns');

            return self::INVALID;
        }
        foreach ($result as $k => $v) {
            $this->line(str_replace('_', ' ', $k).": {$v}");
        }

        return self::SUCCESS;
    }
}
