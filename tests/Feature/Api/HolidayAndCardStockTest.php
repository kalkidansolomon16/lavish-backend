<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\GiftCard;
use App\Models\LoyaltyCampaign;
use App\Models\Reward;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Holiday rewards (date windows), campaign notices, and printed gift cards with barcodes. */
class HolidayAndCardStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_a_holiday_reward_shows_and_can_be_redeemed_only_inside_its_dates(): void
    {
        $reward = Reward::where('points_cost', 2000)->first();
        $reward->update(['available_from' => now()->addDays(5), 'available_until' => now()->addDays(15)]);

        $this->as(self::TIGIST);
        $ids = collect($this->getJson('/api/v1/client/rewards/catalog')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($reward->id, $ids);
        $this->postJson("/api/v1/client/rewards/catalog/{$reward->id}/redeem")->assertStatus(422)->assertJsonPath('code', 'reward_unavailable');

        $this->travel(6)->days();
        $ids = collect($this->getJson('/api/v1/client/rewards/catalog')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($reward->id, $ids);
        $this->postJson("/api/v1/client/rewards/catalog/{$reward->id}/redeem")->assertCreated();

        $this->travel(10)->days();
        $this->assertNotContains($reward->id, collect($this->getJson('/api/v1/client/rewards/catalog')->json('data'))->pluck('id'));
    }

    public function test_manager_sets_reward_dates_from_the_portal(): void
    {
        $reward = Reward::first();
        $this->as(self::GM);
        $this->patchJson("/api/v1/staff/rewards/catalog/{$reward->id}", [
            'available_from' => now()->addDay()->toDateTimeString(), 'available_until' => now()->toDateTimeString(), 'reason' => 'Genna',
        ])->assertStatus(422)->assertJsonValidationErrors('available_until');
        $this->patchJson("/api/v1/staff/rewards/catalog/{$reward->id}", [
            'available_from' => '2026-12-30 00:00:00', 'available_until' => '2027-01-10 23:59:00', 'reason' => 'Genna',
        ])->assertOk();
        $this->assertSame('2027-01-10', $reward->refresh()->available_until->toDateString());
    }

    public function test_campaign_notice_goes_to_members_once_when_it_starts(): void
    {
        $this->as(self::GM);
        $this->postJson('/api/v1/staff/rewards/campaigns', [
            'name' => 'Fasika double points', 'multiplier' => 2, 'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addDays(3)->toDateTimeString(),
            'notify_members' => true, 'notice_title' => 'Melkam Fasika!', 'notice_body' => 'Double points on every treatment this week.',
        ])->assertOk();
        $tigist = User::where('phone', self::TIGIST)->first();

        $this->artisan('lavish:loyalty campaigns')->assertSuccessful();
        $this->assertSame(0, $tigist->notifications()->where('data->type', 'rewards.campaign')->count()); // not started yet

        $this->travel(2)->hours();
        $this->artisan('lavish:loyalty campaigns')->assertSuccessful();
        $this->artisan('lavish:loyalty campaigns')->assertSuccessful(); // second run sends nothing
        $this->assertSame(1, $tigist->notifications()->where('data->type', 'rewards.campaign')->count());
        $this->assertSame('Melkam Fasika!', $tigist->notifications()->where('data->type', 'rewards.campaign')->first()->data['title']);
        $this->assertGreaterThan(0, LoyaltyCampaign::where('name', 'Fasika double points')->first()->notified_count);
    }

    public function test_printed_cards_are_generated_sold_by_barcode_and_then_spent(): void
    {
        // Reception can't create card stock.
        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/gift-cards/stock/generate', ['count' => 3])->assertForbidden();

        $this->as(self::GM);
        $res = $this->postJson('/api/v1/staff/gift-cards/stock/generate', ['count' => 3, 'batch' => 'Genna 2027'])->assertCreated()
            ->assertJsonPath('data.batch', 'Genna 2027')->assertJsonCount(3, 'data.codes');
        $code = $res->json('data.codes.0');
        $this->getJson('/api/v1/staff/gift-cards')->assertOk()->assertJsonPath('meta.in_stock', 3)
            ->assertJsonPath('meta.batches.0.unsold', 3);
        $this->assertNotContains('in_stock', collect($this->getJson('/api/v1/staff/gift-cards')->json('data'))->pluck('status'));

        // A blank card can't be claimed or spent.
        $this->as(self::TIGIST);
        $this->postJson('/api/v1/client/gift-cards/claim', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'gift_card_not_activated');

        // Reception scans the barcode (no dashes) and sells it for ETB 2,000 cash.
        $this->as(self::RECEPTION);
        $scanned = str_replace('-', '', $code);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', ['code' => $scanned, 'type' => 'monetary', 'amount' => 2000, 'method' => 'cash', 'recipient_phone' => self::TIGIST])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.balance', 2000)->assertJsonPath('data.physical', true);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', ['code' => $scanned, 'type' => 'monetary', 'amount' => 2000, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_already_sold');
        $this->postJson('/api/v1/staff/gift-cards/lookup', ['code' => strtolower($scanned)])->assertOk()->assertJsonPath('data.balance', 2000);

        // The recipient was told, and can add the card in the app.
        $tigist = User::where('phone', self::TIGIST)->first();
        $this->assertSame(1, $tigist->notifications()->where('data->type', 'gift_card.received')->count());
        $this->as(self::TIGIST);
        $this->postJson('/api/v1/client/gift-cards/check', ['code' => $code])->assertOk()->assertJsonPath('data.balance', 2000);
    }

    public function test_numbers_already_printed_can_be_imported(): void
    {
        $this->as(self::GM);
        $this->postJson('/api/v1/staff/gift-cards/stock/import', ['codes' => ['4006381333931', '4006381333948', '4006381333931', '123'], 'batch' => 'Printer order 12'])
            ->assertCreated()->assertJsonPath('data.added', 2)->assertJsonPath('data.skipped', ['123']);
        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', ['code' => '4006381333948', 'type' => 'service', 'service_id' => Service::where('name', 'Signature HydraFacial')->value('id'), 'method' => 'telebirr'])
            ->assertOk()->assertJsonPath('data.balance', 4500)->assertJsonPath('data.type', 'service');
        $this->assertSame(1, GiftCard::where('batch', 'Printer order 12')->where('status', 'in_stock')->count());
    }
}
