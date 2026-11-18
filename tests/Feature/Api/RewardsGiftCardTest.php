<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLoyalty;
use App\Models\Doctor;
use App\Models\GiftCard;
use App\Models\LoyaltyCampaign;
use App\Models\LoyaltyTier;
use App\Models\OutboundMessage;
use App\Models\PointsLedger;
use App\Models\Reward;
use App\Models\Service;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lavish Rewards & gift cards — the rules from the functional spec:
 * points only for completed + paid visits, checkout order voucher → gift card → money,
 * no points on the voucher part, partial gift card redemption, reversal, expiry, referrals, tiers.
 */
class RewardsGiftCardTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $meron;

    private Service $hydra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $this->hydra = Service::where('name', 'Signature HydraFacial')->first(); // ETB 4,500
    }

    /** Rahel books a HydraFacial in the app and reception approves it. */
    private function approvedVisit(string $phone = '+251922115566', int $day = 6, string $time = '10:00'): Appointment
    {
        $this->as($phone);
        $id = $this->postJson('/api/v1/client/appointments', [
            'service_id' => $this->hydra->id, 'doctor_id' => $this->meron->id, 'date' => now()->addDays($day)->toDateString(), 'time' => $time,
        ])->assertCreated()->json('data.id');
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$id}/approve")->assertOk();

        return Appointment::find($id);
    }

    private function balance(string $phone): int
    {
        return ClientLoyalty::find(Client::where('phone', $phone)->first()->id)?->points_balance ?? 0;
    }

    public function test_points_need_the_visit_completed_and_paid_whichever_comes_second(): void
    {
        $rahel = '+251922115566';
        $before = $this->balance($rahel);
        $appt = $this->approvedVisit($rahel);

        // Completed but not paid → nothing yet.
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/complete")->assertOk();
        $this->assertSame($before, $this->balance($rahel));

        // Paid at the desk → credited: 4,500 ETB → 450 + 50 per visit + 25 app booking = 525 (Glow 1×).
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/payments/{$appt->payment->id}/record", ['method' => 'cash'])->assertOk();
        $this->assertSame($before + 525, $this->balance($rahel));

        // Never twice.
        app(LoyaltyService::class)->creditVisit($appt->refresh());
        $this->assertSame($before + 525, $this->balance($rahel));
        $this->assertSame(1, PointsLedger::where('source_type', 'appointment')->where('source_id', $appt->id)->count());
    }

    public function test_tier_multiplier_and_double_points_campaign(): void
    {
        LoyaltyCampaign::create(['name' => 'Double points week', 'multiplier' => 2, 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek()]);
        // Tigist is Radiance (1.25×): (450 + 50) × 1.25 × 2 + 25 = 1,275
        $this->as(self::TIGIST);
        $this->getJson("/api/v1/client/rewards/estimate?service_id={$this->hydra->id}")->assertOk()->assertJsonPath('data.points', 1275);
        $this->getJson('/api/v1/client/rewards/ways-to-earn')->assertOk()->assertJsonPath('data.campaigns.0.name', 'Double points week');
    }

    public function test_dashboard_catalog_redeem_and_history(): void
    {
        $this->as(self::TIGIST);
        $this->getJson('/api/v1/client/rewards')->assertOk()
            ->assertJsonPath('data.member', true)
            ->assertJsonPath('data.points_balance', 2400)
            ->assertJsonPath('data.tier.key', 'radiance')
            ->assertJsonPath('data.next_tier.key', 'elite')
            ->assertJsonPath('data.expiring_soon.points', 350)
            ->assertJsonPath('data.vouchers_available', 1);

        $catalog = collect($this->getJson('/api/v1/client/rewards/catalog')->assertOk()->json('data'));
        $this->assertTrue($catalog->firstWhere('points_cost', 2000)['affordable']);
        $this->assertTrue($catalog->firstWhere('points_cost', 25000)['locked']); // Lavish Elite only

        $reward = Reward::where('points_cost', 2000)->first();
        $this->postJson("/api/v1/client/rewards/catalog/{$reward->id}/redeem")->assertCreated()->assertJsonPath('data.status', 'issued');
        $this->assertSame(400, $this->balance(self::TIGIST));
        $elite = Reward::where('points_cost', 25000)->first();
        $this->postJson("/api/v1/client/rewards/catalog/{$elite->id}/redeem")->assertStatus(422)->assertJsonPath('code', 'tier_required');

        $this->getJson('/api/v1/client/rewards/vouchers?status=issued')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('redeem', $this->getJson('/api/v1/client/rewards/history')->assertOk()->json('data.0.type'));
    }

    public function test_checkout_order_voucher_then_gift_card_then_cash_and_no_points_on_the_voucher_part(): void
    {
        $appt = $this->approvedVisit(self::TIGIST, 7, '11:00');
        $payment = $appt->payment;
        $voucher = $this->as(self::TIGIST)->client->vouchers()->first();     // ETB 300 off
        $card = GiftCard::where('code_last4', '2500')->first();                // ETB 1,000 left

        $this->as(self::RECEPTION);
        $this->getJson("/api/v1/staff/payments/{$payment->id}/wallet")->assertOk()
            ->assertJsonPath('data.vouchers.0.applies', true)->assertJsonPath('data.gift_cards.0.balance', 1000);
        $this->postJson("/api/v1/staff/payments/{$payment->id}/quote", ['voucher_id' => $voucher->id, 'gift_card_id' => $card->id])->assertOk()
            ->assertJsonPath('data.voucher_discount', 300)
            ->assertJsonPath('data.gift_card_amount', 1000)
            ->assertJsonPath('data.to_pay', 3200);

        $before = $this->balance(self::TIGIST);
        $this->postJson("/api/v1/staff/payments/{$payment->id}/checkout", ['voucher_id' => $voucher->id, 'gift_card_id' => $card->id, 'method' => 'cash'])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.discount_amount', 300)
            ->assertJsonPath('data.gift_card_amount', 1000)->assertJsonPath('data.due_amount', 3200);
        $this->assertSame('used', $voucher->refresh()->status);
        $this->assertSame(0, $card->refresh()->balance);
        $this->assertSame('redeemed', $card->status);

        // Points on 4,200 (gift card + cash), not on the 300 voucher part: (420 + 50) × 1.25 + 25 = 612
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/complete")->assertOk();
        $this->assertSame($before + 612, $this->balance(self::TIGIST));
    }

    public function test_cancelling_a_booking_gives_back_the_voucher_and_gift_card_money(): void
    {
        $this->as(self::TIGIST);
        $voucher = User::where('phone', '+251911223344')->first()->client->vouchers()->first();
        $card = GiftCard::where('code_last4', '2500')->first();
        $res = $this->postJson('/api/v1/client/appointments', [
            'service_id' => $this->hydra->id, 'doctor_id' => $this->meron->id, 'date' => now()->addDays(8)->toDateString(), 'time' => '14:00',
            'voucher_id' => $voucher->id, 'gift_card_id' => $card->id,
        ])->assertCreated()->assertJsonPath('meta.credits_error', null)->assertJsonPath('data.payment.status', 'unpaid');
        $this->assertSame('used', $voucher->refresh()->status);
        $this->assertSame(0, $card->refresh()->balance);

        $this->postJson('/api/v1/client/appointments/'.$res->json('data.id').'/cancel')->assertOk();
        $this->assertSame('issued', $voucher->refresh()->status);
        $this->assertSame(1000, $card->refresh()->balance);
        $this->assertSame('active', $card->status);
    }

    public function test_refund_takes_back_earned_points_even_into_a_negative_balance(): void
    {
        $rahel = '+251922115566';
        $appt = $this->approvedVisit($rahel);
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/payments/{$appt->payment->id}/record", ['method' => 'cash'])->assertOk();
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/complete")->assertOk();
        $client = Client::where('phone', $rahel)->first();
        $earned = $this->balance($rahel);
        // She spends everything, then the visit is refunded.
        app(LoyaltyService::class)->debit($client, $earned, 'redeem', 'reward', 1, 'Spent');
        $appt->payment->update(['status' => 'refund_due']);
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/payments/{$appt->payment->id}/refund")->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertSame(-525, $this->balance($rahel));
    }

    public function test_buy_a_gift_card_in_the_app_deliver_claim_and_spend_part_of_it(): void
    {
        // Tigist buys ETB 5,000 for Mulu.
        $this->as(self::TIGIST);
        $res = $this->postJson('/api/v1/client/gift-cards', [
            'type' => 'monetary', 'amount' => 5000, 'recipient_name' => 'Mulu Desta', 'recipient_phone' => '0933447788',
            'message' => 'Enjoy!', 'design' => 'gold',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_payment');
        $code = $res->json('code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code);
        // A gift card purchase can't itself be paid with vouchers / points / another card.
        $this->postJson('/api/v1/client/payments/'.$res->json('payment.id').'/quote', ['gift_card_id' => 1])->assertStatus(422)->assertJsonPath('code', 'gift_card_purchase');

        // Pays with the fake Chapa checkout → paid, waiting for reception to activate it (nothing sent yet).
        $paymentId = $res->json('payment.id');
        $url = $this->postJson("/api/v1/client/payments/{$paymentId}/checkout", ['method' => 'telebirr'])->assertOk()->json('data.checkout_url');
        $this->get($url)->assertOk();
        $this->postJson("/api/v1/client/payments/{$paymentId}/verify")->assertOk()->assertJsonPath('data.status', 'paid');
        $card = GiftCard::find($res->json('data.id'));
        $this->assertSame('awaiting_activation', $card->status);
        $this->assertNull($card->delivered_at);
        $this->postJson('/api/v1/client/gift-cards/check', ['code' => $code])->assertStatus(422);
        // The buyer's app shows the card with its code (for the barcode).
        $this->getJson("/api/v1/client/gift-cards/{$card->id}")->assertOk()->assertJsonPath('data.code', $code)->assertJsonPath('data.status', 'awaiting_activation');

        // Reception sees it under "To activate", scans it, sees everything, activates it.
        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/gift-cards?status=awaiting_activation')->assertOk()->assertJsonPath('meta.counts.awaiting_activation', 1);
        $this->postJson('/api/v1/staff/gift-cards/lookup', ['code' => $code])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_activation')->assertJsonPath('data.initial_value', 5000)
            ->assertJsonPath('data.from', 'Tigist Worku')->assertJsonPath('data.recipient.name', 'Mulu Desta')->assertJsonPath('data.payment_status', 'paid');
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/activate")->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.activated_by', fn ($v) => $v !== null);
        $card->refresh();
        $this->assertSame('active', $card->status);
        $this->assertNotNull($card->activated_at);
        $this->assertNotNull($card->delivered_at);
        $sms = OutboundMessage::where('related_type', 'gift_card')->where('related_id', $card->id)->first();
        $this->assertStringContainsString('activated', $sms->body);
        $this->assertStringContainsString('to '.$card->expires_at->format('j M Y'), $sms->body);
        $this->assertStringNotContainsString($code, $sms->body); // the code is not kept in the message log
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/activate")->assertStatus(422); // only once
        $this->assertSame(0, (int) PointsLedger::where('source_type', 'gift_card')->count()); // buying earns nothing

        // Mulu sees it, checks and claims it.
        $mulu = $this->as('+251933447788');
        $this->assertTrue($mulu->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'gift_card.received'));
        $this->postJson('/api/v1/client/gift-cards/check', ['code' => strtolower(str_replace('-', ' ', $code))])->assertOk()->assertJsonPath('data.balance', 5000);
        $this->postJson('/api/v1/client/gift-cards/claim', ['code' => $code])->assertOk()->assertJsonPath('data.mine', 'received');
        $this->getJson('/api/v1/client/gift-cards')->assertOk()->assertJsonPath('data.total_balance', 5000);

        // Spends 4,500 on a HydraFacial at the desk; ETB 500 stays on the card.
        $appt = $this->approvedVisit('+251933447788', 9, '09:30');
        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/gift-cards/lookup', ['code' => $code])->assertOk()->assertJsonPath('data.masked_code', '•••• '.substr(str_replace('-', '', $code), -4));
        $this->postJson("/api/v1/staff/payments/{$appt->payment->id}/checkout", ['gift_card_code' => $code])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.method.value', 'gift_card');
        $this->assertSame(500, $card->refresh()->balance);
        $this->assertTrue($mulu->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'gift_card.balance_low'));
    }

    public function test_front_desk_sells_a_card_and_manager_voids_and_reissues_it(): void
    {
        $this->as(self::RECEPTION);
        $res = $this->postJson('/api/v1/staff/gift-cards', [
            'type' => 'service', 'service_id' => $this->hydra->id, 'method' => 'cash', 'delivery' => 'print',
            'purchaser_client_id' => Client::where('phone', '+251922115566')->value('id'), 'recipient_name' => 'Rahel’s sister',
        ])->assertCreated()->assertJsonPath('data.initial_value', 4500)->assertJsonPath('data.status', 'active');
        $this->assertNotEmpty($res->json('code'));
        // Lists never show the code.
        $this->assertStringNotContainsString($res->json('code'), $this->getJson('/api/v1/staff/gift-cards')->assertOk()->getContent());
        $this->getJson('/api/v1/staff/gift-cards')->assertJsonPath('meta.liability', fn ($v) => $v >= 4500);

        // Reception can't void or issue promo cards.
        $id = $res->json('data.id');
        $this->postJson("/api/v1/staff/gift-cards/{$id}/void", ['reason' => 'Lost card'])->assertForbidden();
        $this->postJson('/api/v1/staff/gift-cards', ['type' => 'promo', 'amount' => 500, 'delivery' => 'print', 'reason' => 'Campaign'])->assertForbidden();

        $this->as(self::GM);
        $void = $this->postJson("/api/v1/staff/gift-cards/{$id}/void", ['reason' => 'Client lost the printed card'])->assertOk()
            ->assertJsonPath('data.status', 'void')->assertJsonPath('reissued.balance', 4500);
        $this->assertNotSame($res->json('code'), $void->json('code'));
        $this->assertSame(1, AuditLog::where('action', 'gift_card.void')->count());
        $this->postJson('/api/v1/staff/gift-cards/lookup', ['code' => $res->json('code')])->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_manual_adjustment_needs_a_manager_and_a_reason_and_is_audited(): void
    {
        $tigist = Client::where('phone', '+251911223344')->first();
        $this->as(self::RECEPTION);
        $this->getJson("/api/v1/staff/rewards/clients/{$tigist->id}")->assertOk()->assertJsonPath('data.rewards.points_balance', 2400);
        $this->postJson("/api/v1/staff/rewards/clients/{$tigist->id}/adjust", ['points' => 100, 'reason' => 'Goodwill'])->assertForbidden();

        $this->as(self::GM);
        $this->postJson("/api/v1/staff/rewards/clients/{$tigist->id}/adjust", ['points' => 100])->assertStatus(422);
        $this->postJson("/api/v1/staff/rewards/clients/{$tigist->id}/adjust", ['points' => 100, 'reason' => 'Waited 40 minutes'])->assertOk()
            ->assertJsonPath('data.rewards.points_balance', 2500);
        $log = AuditLog::where('action', 'points.adjust')->first();
        $this->assertSame('Waited 40 minutes', $log->reason);
        $this->assertSame(2500, $log->after['points_balance']);
        $this->getJson('/api/v1/staff/rewards/audit')->assertOk()->assertJsonPath('data.0.action', 'points.adjust');
    }

    public function test_referral_rewards_the_friend_after_the_first_completed_paid_visit(): void
    {
        $before = $this->balance(self::TIGIST);
        $appt = $this->approvedVisit('+251933447788'); // Mulu was referred by Tigist
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/payments/{$appt->payment->id}/record", ['method' => 'cash'])->assertOk();
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/complete")->assertOk();
        $this->assertSame($before + 500, $this->balance(self::TIGIST));
        $this->as(self::TIGIST);
        $this->getJson('/api/v1/client/rewards/referrals')->assertOk()->assertJsonPath('data.referrals.0.status', 'rewarded');
    }

    public function test_nightly_expiry_reminders_and_elite_points_never_expire(): void
    {
        $loyalty = app(LoyaltyService::class);
        $this->assertSame(1, $loyalty->daily()['reminded']);          // Tigist's 350 points expire in 13 days

        $this->travel(14)->days();
        $result = $loyalty->nightly();
        $this->assertSame(350, $result['points_expired']);
        $this->assertSame(2050, $this->balance(self::TIGIST));

        // An Elite member's old points stay.
        $rahel = Client::where('phone', '+251922115566')->first();
        ClientLoyalty::find($rahel->id)->update(['tier_id' => LoyaltyTier::where('key', 'elite')->value('id')]);
        PointsLedger::where('client_id', $rahel->id)->update(['expires_at' => now()->subDay()]);
        LoyaltyTier::where('key', 'elite')->update(['min_points_12m' => 0]);
        $loyalty->nightly();
        $this->assertSame(1450, $this->balance('+251922115566'));
    }

    public function test_signing_up_does_not_join_rewards_reception_enrols_the_client(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Hanna Bekele', 'phone' => '0912778899', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'referral_code' => 'tigist-7k2m',
        ])->assertCreated();
        $this->postJson('/api/v1/auth/register/verify', ['phone' => '0912778899', 'code' => '123456'])->assertCreated();
        $this->assertSame(0, $this->balance('+251912778899'));
        $this->assertDatabaseHas('referrals', ['code' => 'TIGIST-7K2M', 'status' => 'pending']);

        // Not a member until reception enrols her; she can't join from the app.
        $this->as('+251912778899');
        $this->getJson('/api/v1/client/rewards')->assertOk()->assertJsonPath('data.member', false);
        $this->postJson('/api/v1/client/rewards/join')->assertStatus(422)->assertJsonPath('code', 'enrol_at_reception');
        $this->postJson('/api/v1/client/rewards/requests', ['kind' => 'treatment', 'points' => 10])->assertStatus(422)->assertJsonPath('code', 'not_member');

        $this->as(self::RECEPTION);
        $client = Client::where('phone', '+251912778899')->first();
        $this->postJson('/api/v1/staff/rewards/enrol', ['client_id' => $client->id, 'note' => 'Asked at the desk'])->assertCreated()
            ->assertJsonPath('data.rewards.member', true);
        $this->assertSame(200, $this->balance('+251912778899')); // welcome points (the rule can be set to 0)
        $this->postJson('/api/v1/staff/rewards/enrol', ['client_id' => $client->id])->assertStatus(422)->assertJsonPath('code', 'already_member');
        $this->assertSame(200, $this->getJson("/api/v1/staff/rewards/clients/{$client->id}")->json('data.history.0.balance_after'));

        // Her friend's HydraFacial card was sent to this phone before she had an account.
        $this->as('+251912778899');
        $this->postJson('/api/v1/client/gift-cards/claim', ['code' => 'LVSH-TGST-SENT-HYDR'])->assertOk()->assertJsonPath('data.type', 'service');
    }

    public function test_staff_screens_and_reports(): void
    {
        $this->as(self::GM);
        $this->getJson('/api/v1/staff/rewards/settings')->assertOk()->assertJsonCount(3, 'data.tiers');
        $this->putJson('/api/v1/staff/rewards/settings', ['point_value_etb' => 1])->assertStatus(422); // reason required
        $this->putJson('/api/v1/staff/rewards/settings', ['point_value_etb' => 1, 'reason' => 'Owner decision'])->assertOk()
            ->assertJsonPath('data.settings.point_value_etb', 1);
        $this->postJson('/api/v1/staff/rewards/campaigns', ['name' => 'Valentine 2×', 'multiplier' => 2, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)])->assertOk();
        $this->getJson('/api/v1/staff/rewards/members?tier_id='.LoyaltyTier::where('key', 'radiance')->value('id'))->assertOk()->assertJsonPath('data.0.client.name', 'Tigist Worku');
        $this->getJson('/api/v1/staff/rewards/members?q=Almaz')->assertOk()->assertJsonPath('data.0.member', false); // walk-in, not joined yet
        $tiers = collect($this->getJson('/api/v1/staff/rewards/settings')->json('data.tiers'))->map(fn ($t) => collect($t)->except('key')->all())->all();
        $tiers[1]['min_points_12m'] = 2500;
        $tiers[1]['benefits'] = ['Earn 1.25× points', 'Priority booking'];
        $this->putJson('/api/v1/staff/rewards/tiers', ['tiers' => $tiers, 'reason' => 'Lower Radiance threshold'])->assertOk()
            ->assertJsonPath('data.tiers.1.min_points_12m', 2500)->assertJsonPath('data.tiers.1.benefits.1', 'Priority booking');

        $this->as(self::CEO);
        $r = $this->getJson('/api/v1/staff/rewards/reports')->assertOk();
        $this->assertSame(1000 + 4500 + 2500, $r->json('data.gift_cards.liability'));
        $this->assertGreaterThan(0, $r->json('data.points.outstanding'));

        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/rewards/settings')->assertOk();
        $this->putJson('/api/v1/staff/rewards/settings', ['point_value_etb' => 2, 'reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.pages', fn ($p) => in_array('rewards', $p) && in_array('giftcards', $p));
    }
}
