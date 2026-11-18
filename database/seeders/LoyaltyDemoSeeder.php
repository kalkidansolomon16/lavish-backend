<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientLoyalty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Lavish Rewards & gift card demo members and cards (local / demo only).
 * Runs inside DemoSeeder, and can be added to an existing demo database on its own:
 *   php artisan db:seed --class=LoyaltySeeder && php artisan db:seed --class=LoyaltyDemoSeeder
 * It does nothing if the demo clients are missing or already have rewards data.
 */
class LoyaltyDemoSeeder extends Seeder
{
    private CarbonImmutable $today;

    private function client(string $phone): Client
    {
        return Client::query()->where('phone', $phone)->firstOrFail();
    }

    /**
     * Lavish Rewards & gift cards demo (see docs/API.md §11):
     *   Tigist — Radiance member, 2,400 points, 350 expiring in 13 days, an "ETB 300 off" voucher ready,
     *            a received ETB 2,500 card with ETB 1,000 left, and a HydraFacial card she sent.
     *   Mulu   — referred by Tigist (pending until her first visit).
     *   An unclaimed ETB 2,500 card with code LVSH-DEMO-GIFT-2026 to test "Add a card".
     */
    public function run(): void
    {
        $this->today = CarbonImmutable::today();
        if (! Client::query()->where('phone', '+251911223344')->exists() || ClientLoyalty::query()->exists()) {
            return;
        }
        $loyalty = app(\App\Services\LoyaltyService::class);
        $tigist = $this->client('+251911223344');
        $mulu = $this->client('+251933447788');
        $hydra = \App\Models\Appointment::query()->where('client_id', $tigist->id)->where('status', 'completed')->first();

        $loyalty->membership($tigist)->update(['joined_at' => $this->today->subDays(83), 'referral_code' => 'TIGIST-7K2M']);
        $lots = [
            // [points, source, id, description, days ago]
            [950, 'appointment', null, 'PRP Skin Rejuvenation visit', 352],
            [200, 'signup', $tigist->id, 'Welcome to Lavish Rewards', 83],
            [1275, 'appointment', null, 'Morpheus8 & Microneedling visit', 60],
            [525, 'appointment', $hydra?->id, 'Signature HydraFacial visit', 20],
            [50, 'review', $hydra?->id, 'Thanks for your review', 19],
        ];
        foreach ($lots as [$points, $source, $id, $text, $ago]) {
            $row = $loyalty->award($tigist, $points, $source, $id, $text, notify: false);
            $at = $this->today->subDays($ago)->setTime(11, 0);
            $row->forceFill(['created_at' => $at, 'updated_at' => $at, 'expires_at' => $at->addMonths(12)])->save();
        }
        $voucher = $loyalty->redeem($tigist, \App\Models\Reward::query()->where('points_cost', 600)->firstOrFail());
        $at = $this->today->subDays(10)->setTime(15, 0);
        \App\Models\PointsLedger::query()->where('client_id', $tigist->id)->where('type', 'redeem')->update(['created_at' => $at]);
        $voucher->forceFill(['created_at' => $at, 'expires_at' => $at->addDays(90)])->save();
        $loyalty->refreshTier($loyalty->membership($tigist));
        $loyalty->membership($tigist)->update(['tier_up_seen' => true]);

        $loyalty->membership($this->client('+251922115566'))->update(['joined_at' => $this->today->subDays(60)]);
        $loyalty->award($this->client('+251922115566'), 1450, 'appointment', null, 'PRP Skin Rejuvenation visit', notify: false);
        $loyalty->membership($mulu)->update(['joined_at' => $this->today->subDays(25)]);
        $loyalty->award($mulu, 200, 'signup', $mulu->id, 'Welcome to Lavish Rewards', notify: false);
        $loyalty->applyReferralCode($mulu, 'TIGIST-7K2M');

        // Seeding isn't real activity: drop the tier-up / voucher notifications it generated.
        \Illuminate\Support\Facades\DB::table('notifications')->where('type', 'like', 'rewards.%')->delete();

        // Gift cards
        $card = function (string $code, array $attrs, array $ledger) {
            $c = \App\Models\GiftCard::create([
                'code_hash' => \App\Services\GiftCardService::hash($code),
                'code_last4' => substr(\App\Services\GiftCardService::normalize($code), -4),
                'status' => 'active', 'design' => 'classic', 'delivery' => 'sms',
                'expires_at' => $this->today->addMonths(23)->endOfDay(), 'delivered_at' => $this->today->subDays(30),
                ...$attrs,
            ]);
            foreach ($ledger as [$type, $amount, $after, $reason, $ago]) {
                \App\Models\GiftCardLedger::create(['gift_card_id' => $c->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $after,
                    'reason' => $reason, 'created_at' => $this->today->subDays($ago), 'updated_at' => $this->today->subDays($ago)]);
            }

            return $c;
        };
        $card('LVSH-TGST-RCVD-2500', [
            'type' => 'monetary', 'initial_value' => 2500, 'balance' => 1000, 'purchaser_name' => 'Selamawit Haile',
            'recipient_name' => 'Tigist Worku', 'recipient_phone' => $tigist->phone, 'recipient_client_id' => $tigist->id,
            'message' => 'Happy wedding month, Tigist! Treat yourself.', 'design' => 'gold', 'claimed_at' => $this->today->subDays(29), 'source' => 'app',
        ], [['issue', 2500, 2500, 'Card purchased', 31], ['redeem', -1500, 1000, 'Order #9002', 12]]);
        $card('LVSH-TGST-SENT-HYDR', [
            'type' => 'service', 'service_id' => \App\Models\Service::where('name', 'Signature HydraFacial')->value('id'), 'initial_value' => 4500, 'balance' => 4500,
            'purchaser_client_id' => $tigist->id, 'purchaser_name' => 'Tigist Worku', 'recipient_name' => 'Hanna Bekele',
            'recipient_phone' => '+251912778899', 'message' => 'For all your help with the wedding ❤', 'design' => 'botanical', 'source' => 'app',
            'delivered_at' => $this->today->subDays(5),
        ], [['issue', 4500, 4500, 'Card purchased', 5]]);
        $card('LVSH-DEMO-GIFT-2026', [
            'type' => 'monetary', 'initial_value' => 2500, 'balance' => 2500, 'purchaser_name' => 'Lavish Medical Spa',
            'recipient_name' => 'Mulu Desta', 'message' => 'Welcome to Lavish!', 'design' => 'thank_you', 'source' => 'front_desk',
            'delivery' => 'print', 'sold_by' => \App\Models\User::where('phone', '+251911003344')->value('id'),
        ], [['issue', 2500, 2500, 'Card purchased', 2]]);
    }
}
