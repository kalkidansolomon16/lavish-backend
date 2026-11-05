<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $meron;

    private Doctor $yonas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $this->yonas = User::where('phone', self::DR_YONAS)->first()->doctor;
    }

    private function day(int $n): string
    {
        return now()->addDays($n)->toDateString();
    }

    public function test_book_approve_pay_complete_then_chat(): void
    {
        $botox = Service::where('name', 'Botox & Dermal Fillers')->first();

        // Mulu books Botox with Dr. Yonas from the app.
        $mulu = $this->as('+251933447788');
        $id = $this->postJson('/api/v1/client/appointments', [
            'service_id' => $botox->id, 'doctor_id' => $this->yonas->id, 'date' => $this->day(5), 'time' => '10:00', 'note' => 'First visit',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payment.amount', 18500)->json('data.id');

        // The slot is now taken for everyone else.
        $this->as('+251922115566');
        $this->postJson('/api/v1/client/appointments', ['service_id' => $botox->id, 'doctor_id' => $this->yonas->id, 'date' => $this->day(5), 'time' => '10:00'])
            ->assertStatus(422)->assertJsonPath('code', 'slot_taken');
        $slots = collect($this->getJson("/api/v1/catalog/availability?service_id={$botox->id}&doctor_id={$this->yonas->id}&date={$this->day(5)}")->json('data.slots'));
        $this->assertFalse($slots->firstWhere('time', '10:00')['available']);
        $this->assertTrue($slots->firstWhere('time', '14:00')['available']);

        // Chat is locked until the first treatment.
        $this->as('+251933447788');
        $this->postJson("/api/v1/client/chats/{$this->yonas->id}")->assertForbidden()->assertJsonPath('code', 'chat_locked');

        // Reception approves; the client is notified.
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('appointment.approved', $mulu->notifications()->first()->data['type']);

        // The client pays in the app (fake Chapa checkout).
        $this->as('+251933447788');
        $paymentId = Appointment::find($id)->payment->id;
        $url = $this->postJson("/api/v1/client/payments/{$paymentId}/checkout", ['method' => 'telebirr'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->json('data.checkout_url');
        $this->get($url)->assertOk()->assertSee('Test payment successful');
        $this->postJson("/api/v1/client/payments/{$paymentId}/verify")->assertOk()->assertJsonPath('data.status', 'paid');

        // Dr. Yonas marks it done → chat unlocks.
        $this->as(self::DR_YONAS);
        $this->postJson("/api/v1/staff/appointments/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->as('+251933447788');
        $conversation = $this->postJson("/api/v1/client/chats/{$this->yonas->id}")->assertOk()->assertJsonPath('data.locked', false)->json('data.id');
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['body' => 'Thank you doctor!'])->assertCreated()->assertJsonPath('data.mine', true);

        $this->as(self::DR_YONAS);
        $this->getJson("/api/v1/conversations/{$conversation}/messages")->assertOk()->assertJsonPath('data.0.body', 'Thank you doctor!')->assertJsonPath('data.0.mine', false);
    }

    public function test_reception_cannot_approve_over_a_teaching_class(): void
    {
        // Demo: Rahel asked Dr. Meron for 11:00 tomorrow, but Dr. Meron teaches 10:00–12:00.
        $appt = Appointment::query()->where('doctor_id', $this->meron->id)->where('status', 'pending')->first();
        $this->as(self::RECEPTION);

        $res = $this->postJson("/api/v1/staff/appointments/{$appt->id}/approve")->assertStatus(422)->assertJsonPath('code', 'doctor_busy');
        $this->assertSame('class', $res->json('details.clashes.0.kind'));

        $check = $this->getJson("/api/v1/staff/appointments/{$appt->id}/check")->assertOk();
        $meron = collect($check->json('data.suggestions'))->firstWhere('doctor.id', $this->meron->id);
        $this->assertFalse($meron['available']);

        // Reassigning to a free doctor works.
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/approve", ['doctor_id' => $this->yonas->id])
            ->assertOk()->assertJsonPath('data.doctor.id', $this->yonas->id);
    }

    public function test_unassigned_booking_needs_a_doctor_before_approval(): void
    {
        $appt = Appointment::query()->whereNull('doctor_id')->where('is_online', false)->where('status', 'pending')->first();
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$appt->id}/approve")->assertStatus(422)->assertJsonPath('code', 'doctor_required');
    }

    public function test_online_session_link_appears_only_once_paid(): void
    {
        $online = Service::where('is_online', true)->first();
        $tigist = $this->as(self::TIGIST);
        $id = $this->postJson('/api/v1/client/appointments', ['service_id' => $online->id, 'date' => $this->day(8), 'time' => '15:00', 'note' => 'Skin check'])
            ->assertCreated()->assertJsonPath('data.is_online', true)->assertJsonPath('data.branch', null)->json('data.id');

        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$id}/approve", ['doctor_id' => $this->yonas->id])->assertOk();
        $this->assertNotNull(Appointment::find($id)->video_link);
        $this->assertSame('appointment.online_confirmed', $tigist->notifications()->first()->data['type']);

        $this->as(self::TIGIST);
        $this->getJson("/api/v1/client/appointments/{$id}")->assertJsonPath('data.video_link', null)->assertJsonPath('data.video_link_locked', true);

        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/payments/'.Appointment::find($id)->payment->id.'/record', ['method' => 'telebirr'])->assertOk();

        $this->as(self::TIGIST);
        $this->getJson("/api/v1/client/appointments/{$id}")->assertJsonPath('data.video_link_locked', false)
            ->assertJsonPath('data.video_link', Appointment::find($id)->video_link);
    }

    public function test_client_cancels_and_a_paid_booking_is_flagged_for_refund(): void
    {
        $this->as(self::TIGIST);
        $appt = Appointment::query()->where('client_id', User::where('phone', self::TIGIST)->first()->client->id)
            ->where('is_online', true)->where('status', 'approved')->first();   // paid online session in 4 days
        $this->postJson("/api/v1/client/appointments/{$appt->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('refund_due', $appt->payment->fresh()->status->value);
    }

    public function test_reception_books_a_walk_in(): void
    {
        $hydra = Service::where('name', 'Signature HydraFacial')->first();
        $this->as(self::RECEPTION);
        $res = $this->postJson('/api/v1/staff/appointments/walk-in', [
            'client' => ['name' => 'Meseret Alem', 'phone' => '0919 12 34 56', 'gender' => 'Female'],
            'source' => 'walk_in',
            'history' => ['concern' => 'Uneven skin tone', 'skin_type' => 'Oily', 'allergies' => 'None', 'pregnant' => 'No'],
            'service_id' => $hydra->id, 'doctor_id' => $this->meron->id, 'date' => $this->day(6), 'time' => '09:00',
            'send_sms' => true, 'send_app_invite' => true,
        ])->assertCreated()->assertJsonPath('data.appointment.status', 'approved')->assertJsonPath('data.client.has_app', false);

        $this->assertDatabaseHas('client_histories', ['client_id' => $res->json('data.client.id'), 'skin_type' => 'Oily']);
        $this->assertDatabaseHas('outbound_messages', ['channel' => 'sms', 'to' => '+251919123456']);
    }
}
