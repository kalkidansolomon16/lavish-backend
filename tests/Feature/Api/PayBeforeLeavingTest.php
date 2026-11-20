<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A finished treatment that isn't paid: reception is alerted and sees it in "Waiting to pay", the client is told,
 * and new bookings wait until it's paid (staff who collect payments may override). Also: prescription email and
 * declining a request to join Lavish Rewards.
 */
class PayBeforeLeavingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_unpaid_finished_treatment_alerts_reception_and_blocks_new_bookings_until_paid(): void
    {
        $hydra = Service::where('name', 'Signature HydraFacial')->first();
        $meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $rahel = Client::where('phone', '+251922115566')->first();

        $this->as('+251922115566');
        $id = $this->postJson('/api/v1/client/appointments', ['service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => now()->addDays(4)->toDateString(), 'time' => '10:00'])->assertCreated()->json('data.id');
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$id}/approve")->assertOk();
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/appointments/{$id}/complete")->assertOk();

        // Reception is alerted and the client is told to pay before leaving.
        $reception = User::where('phone', self::RECEPTION)->first();
        $this->assertTrue($reception->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'payment.collect_now'));
        $this->assertTrue($rahel->user->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'payment.due_now'));
        $this->as(self::RECEPTION);
        $owing = $this->getJson('/api/v1/staff/payments/owing')->assertOk();
        $this->assertContains($rahel->id, collect($owing->json('data'))->pluck('client.id')->all());
        $this->assertGreaterThan(0, $owing->json('meta.today'));

        // No new booking in the app until it is paid.
        $this->as('+251922115566');
        $this->postJson('/api/v1/client/appointments', ['service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => now()->addDays(9)->toDateString(), 'time' => '11:00'])
            ->assertStatus(422)->assertJsonPath('code', 'unpaid_treatment');

        // Reception: a walk-in for her is refused unless overridden by someone who collects payments.
        $this->as(self::RECEPTION);
        $walkIn = ['client_id' => $rahel->id, 'service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => now()->addDays(9)->toDateString(), 'time' => '14:00'];
        $this->postJson('/api/v1/staff/appointments/walk-in', $walkIn)->assertStatus(422)->assertJsonPath('code', 'client_owes')->assertJsonPath('details.can_override', true);
        $this->postJson('/api/v1/staff/appointments/walk-in', [...$walkIn, 'allow_unpaid' => true])->assertCreated();

        // Paid → off the list, and she can book again.
        $this->postJson('/api/v1/staff/payments/'.Appointment::find($id)->payment->id.'/record', ['method' => 'cash'])->assertOk();
        $this->assertNotContains($rahel->id, collect($this->getJson('/api/v1/staff/payments/owing')->json('data'))->pluck('client.id')->all());
        $this->as('+251922115566');
        $this->postJson('/api/v1/client/appointments', ['service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => now()->addDays(10)->toDateString(), 'time' => '11:00'])->assertCreated();
    }

    public function test_prescription_is_emailed_to_the_client(): void
    {
        $tigist = Client::where('phone', self::TIGIST)->first();
        $tigist->update(['email' => 'tigist@example.com']);
        $this->as(self::DR_MERON);
        $rx = $this->postJson('/api/v1/staff/prescriptions', [
            'client_id' => $tigist->id, 'note' => 'Avoid the sun for a week',
            'items' => [['custom_name' => 'Vitamin C serum', 'quantity' => 1, 'instructions' => 'Every morning', 'reminder_times' => ['08:00'], 'duration_days' => 30]],
        ])->assertCreated()->json('data.id');
        $mail = OutboundMessage::where('channel', 'email')->where('related_type', 'prescription')->where('related_id', $rx)->first();
        $this->assertNotNull($mail);
        $this->assertSame('tigist@example.com', $mail->to);
        $this->assertStringContainsString('Vitamin C serum', $mail->body);
        $this->assertStringContainsString('08:00 for 30 days', $mail->body);
        $this->assertStringContainsString('Avoid the sun', $mail->body);
    }

    public function test_printed_card_keeps_its_number_for_the_barcode_and_can_be_a_promo_card(): void
    {
        $this->as(self::GM);
        $code = $this->postJson('/api/v1/staff/gift-cards/stock/generate', ['count' => 1, 'batch' => 'Promo'])->assertCreated()->json('data.codes.0');
        $this->getJson('/api/v1/staff/gift-cards?status=active,in_stock')->assertOk()->assertJsonPath('data.0.card_number', $code);
        // The batch's print file can be saved again later.
        $this->getJson('/api/v1/staff/gift-cards/stock/batch-codes?batch=Promo')->assertOk()->assertJsonPath('data.codes.0', $code)->assertJsonPath('meta.missing', 0);

        // Reception can't give a free card; a manager can, with a reason.
        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', ['code' => $code, 'type' => 'promo', 'amount' => 1000, 'reason' => 'Raffle'])->assertForbidden();
        $this->as(self::GM);
        $this->postJson('/api/v1/staff/gift-cards/stock/sell', ['code' => $code, 'type' => 'promo', 'amount' => 1000, 'reason' => 'Raffle winner',
            'recipient_name' => 'Winner', 'recipient_email' => 'winner@example.com', 'message' => 'Congratulations!'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.type', 'promo')->assertJsonPath('data.balance', 1000);
    }
}
