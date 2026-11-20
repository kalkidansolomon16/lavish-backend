<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientLoyalty;
use App\Models\GiftCard;
use App\Models\OutboundMessage;
use App\Models\PointsRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rewards by enrolment + points requests (client → reception → e-commerce), and gift cards used at the desk.
 */
class PointsRequestAndCardUseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function balance(string $phone): int
    {
        return ClientLoyalty::find(Client::where('phone', $phone)->first()->id)->points_balance;
    }

    public function test_reception_enrols_a_new_client_with_their_details_and_only_members_earn(): void
    {
        $this->as(self::RECEPTION);
        $res = $this->postJson('/api/v1/staff/rewards/enrol', ['client' => [
            'name' => 'Selam Haile', 'phone' => '0944556677', 'email' => 'selam@example.com', 'gender' => 'Female', 'date_of_birth' => '1995-04-12',
        ], 'note' => 'Wants points for facials'])->assertCreated()->assertJsonPath('data.rewards.member', true)->assertJsonPath('data.enrolled_by', 'Hana Tadesse');
        $client = Client::where('phone', '+251944556677')->first();
        $this->assertSame('selam@example.com', $client->email);
        $this->assertSame($res->json('data.client.id'), $client->id);
        // The members list shows enrolled clients only (Almaz, a walk-in, isn't one).
        $names = collect($this->getJson('/api/v1/staff/rewards/members')->assertOk()->json('data'))->pluck('client.name');
        $this->assertContains('Selam Haile', $names);
        $this->assertNotContains('Almaz Tesfaye', $names);

        // A doctor / nurse can't enrol.
        $this->as(self::NURSE);
        $this->postJson("/api/v1/staff/rewards/clients/{$client->id}/join")->assertForbidden();
    }

    public function test_treatment_request_comes_off_the_bill_and_points_are_held_until_then(): void
    {
        $tigist = Client::where('phone', self::TIGIST)->first();
        $this->as(self::TIGIST);
        $this->getJson('/api/v1/client/rewards')->assertJsonPath('data.available_points', 2400);
        $id = $this->postJson('/api/v1/client/rewards/requests', ['kind' => 'treatment', 'points' => 2000, 'note' => 'For my HydraFacial'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.value_etb', 1000)->json('data.id');
        // The 2,000 are held: only 400 left for another request.
        $this->getJson('/api/v1/client/rewards')->assertJsonPath('data.held_points', 2000)->assertJsonPath('data.available_points', 400);
        $this->postJson('/api/v1/client/rewards/requests', ['kind' => 'other', 'points' => 500, 'note' => 'x'])->assertStatus(422)->assertJsonPath('code', 'not_enough_points');
        $this->assertTrue(User::where('phone', self::RECEPTION)->first()->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'points.request'));

        // Tigist's HydraFacial bill (ETB 4,500).
        $hydra = Service::where('name', 'Signature HydraFacial')->first();
        $doctor = User::where('phone', self::DR_MERON)->first()->doctor;
        $apptId = $this->postJson('/api/v1/client/appointments', ['service_id' => $hydra->id, 'doctor_id' => $doctor->id, 'date' => now()->addDays(5)->toDateString(), 'time' => '10:00'])->assertCreated()->json('data.id');
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$apptId}/approve")->assertOk();
        $bill = Appointment::find($apptId)->payment;

        $show = $this->getJson("/api/v1/staff/rewards/requests/{$id}")->assertOk();
        $this->assertContains($bill->id, collect($show->json('data.bills'))->pluck('id')->all());
        $this->postJson("/api/v1/staff/rewards/requests/{$id}/complete", ['payment_id' => $bill->id])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.points_used', 2000)->assertJsonPath('data.discount_etb', 1000);
        $this->assertSame(400, $this->balance(self::TIGIST));
        $this->assertSame(1000, $bill->refresh()->discount_amount);
        $this->assertSame(3500, $bill->dueAmount());

        // The client's history says what the points were used for and what is left.
        $this->as(self::TIGIST);
        $h = $this->getJson('/api/v1/client/rewards/history')->json('data.0');
        $this->assertSame(-2000, $h['points']);
        $this->assertSame(400, $h['balance_after']);
        $this->assertStringContainsString('ETB 1,000 off', $h['description']);
        $this->getJson('/api/v1/client/rewards/requests')->assertJsonPath('data.0.status', 'completed');
        $this->assertTrue($tigist->user->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'points.used'));
        $this->postJson("/api/v1/staff/rewards/requests/{$id}/complete", ['note' => 'again'])->assertForbidden(); // clients can't
    }

    public function test_shop_request_goes_to_ecommerce_and_a_rejected_request_frees_the_points(): void
    {
        $this->as(self::TIGIST);
        $shop = $this->postJson('/api/v1/client/rewards/requests', ['kind' => 'shop', 'points' => 1000])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/client/rewards/requests', ['kind' => 'other', 'points' => 400, 'note' => 'Birthday gift box'])->assertCreated()->json('data.id');

        // E-commerce doesn't see it until reception sends it.
        $this->as(self::ECOMMERCE);
        $this->getJson('/api/v1/staff/rewards/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/staff/rewards/requests/{$shop}")->assertNotFound();

        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/rewards/requests/{$other}/forward")->assertStatus(422); // only shop requests
        $this->postJson("/api/v1/staff/rewards/requests/{$shop}/forward", ['note' => 'Next skincare order'])->assertOk()->assertJsonPath('data.status', 'forwarded');
        $this->postJson("/api/v1/staff/rewards/requests/{$other}/reject", ['reason' => 'We don’t offer gift boxes'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->as(self::ECOMMERCE);
        $this->assertTrue(User::where('phone', self::ECOMMERCE)->first()->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'points.forwarded'));
        $this->getJson('/api/v1/staff/rewards/requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.open', 1);
        $this->postJson("/api/v1/staff/rewards/requests/{$shop}/complete")->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->postJson("/api/v1/staff/rewards/requests/{$shop}/complete", ['note' => 'Taken off order #12 at the counter'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1400, $this->balance(self::TIGIST));

        // The rejected 400 are free again.
        $this->as(self::TIGIST);
        $this->getJson('/api/v1/client/rewards')->assertJsonPath('data.available_points', 1400)->assertJsonPath('data.held_points', 0);
        $this->assertSame(1, PointsRequest::where('status', 'rejected')->count());
    }

    public function test_printed_card_sold_with_recipient_email_then_used_for_treatments_until_empty(): void
    {
        $this->as(self::GM);
        $code = $this->postJson('/api/v1/staff/gift-cards/stock/generate', ['count' => 1, 'batch' => 'Test'])->assertCreated()->json('data.codes.0');

        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', [
            'code' => $code, 'type' => 'monetary', 'amount' => 3000, 'method' => 'cash',
            'purchaser_name' => 'Dawit', 'recipient_name' => 'Liya', 'recipient_email' => 'liya@example.com',
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $card = GiftCard::where('batch', 'Test')->first();
        $mail = OutboundMessage::where('channel', 'email')->where('related_id', $card->id)->first();
        $this->assertSame('liya@example.com', $mail->to);
        $this->assertStringContainsString('Surprise', $mail->body);
        $this->assertStringContainsString(str_replace('-', '', $code), str_replace('-', '', $mail->body)); // the card number is in the message
        // Reception sees the message in the card's details and can send it again.
        $this->getJson("/api/v1/staff/gift-cards/{$card->id}")->assertOk()->assertJsonPath('data.messages.0.channel', 'email');
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/resend")->assertOk()->assertJsonCount(2, 'data.messages');

        // Liya comes in: reception scans the card, sees it, uses ETB 2,000 then the last 1,000.
        $hydra = Service::where('name', 'Signature HydraFacial')->first();
        $this->postJson('/api/v1/staff/gift-cards/lookup', ['code' => $code])->assertOk()
            ->assertJsonPath('data.recipient.name', 'Liya')->assertJsonPath('data.from', 'Dawit')->assertJsonPath('data.balance', 3000);
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/use", ['amount' => 2000, 'service_id' => $hydra->id, 'note' => 'Dr. Meron'])->assertOk()
            ->assertJsonPath('data.balance', 1000)->assertJsonPath('data.used', 2000)->assertJsonPath('data.ledger.0.type', 'redeem');
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/use", ['amount' => 1500])->assertStatus(422)->assertJsonPath('code', 'gift_card_balance');
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/use", ['amount' => 1000])->assertOk()->assertJsonPath('data.status', 'redeemed');
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/use", ['amount' => 1])->assertStatus(422);

        // A nurse can't use cards.
        $this->as(self::NURSE);
        $this->postJson("/api/v1/staff/gift-cards/{$card->id}/use", ['amount' => 1])->assertForbidden();
    }
}
