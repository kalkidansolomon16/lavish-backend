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
 * Before approving, reception sees the doctor's slots (free / booked / requested / class). When the doctor the
 * client chose is busy, reception asks the client to change the time or the doctor; the client changes it in the app.
 */
class AppointmentChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_sees_the_doctors_day_asks_the_client_to_change_and_the_client_changes(): void
    {
        $this->seedDemo();
        $hydra = Service::where('name', 'Signature HydraFacial')->first();
        $meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $date = now()->addDays(8)->toDateString();

        // Tigist asks for Dr. Meron at 10:00.
        $this->as(self::TIGIST);
        $id = $this->postJson('/api/v1/client/appointments', ['service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => $date, 'time' => '10:00'])->assertCreated()->json('data.id');

        // Meanwhile reception books a walk-in with Dr. Meron at the same time (approved straight away).
        $this->as(self::RECEPTION);
        $this->postJson('/api/v1/staff/appointments/walk-in', [
            'client' => ['name' => 'Walk-in Client', 'phone' => '0933221100'], 'service_id' => $hydra->id, 'doctor_id' => $meron->id, 'date' => $date, 'time' => '10:00',
        ])->assertCreated();

        // Before approving: the slot is taken, other slots are free, free times are suggested.
        $check = $this->getJson("/api/v1/staff/appointments/{$id}/check")->assertOk();
        $slots = collect($check->json('data.slots'))->keyBy('time');
        $this->assertSame('booked', $slots['10:00']['state']);
        $this->assertStringContainsString('Walk-in Client', $slots['10:00']['who']);
        $this->assertSame('free', $slots['14:00']['state']);
        $this->assertNotEmpty($check->json('data.clashes'));
        $this->assertNotEmpty($check->json('data.free_times'));
        $this->postJson("/api/v1/staff/appointments/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'doctor_busy');

        // Ask the client to change the time, with two free times.
        $this->postJson("/api/v1/staff/appointments/{$id}/request-change", [
            'kind' => 'time', 'message' => 'Sorry for the trouble!', 'times' => [['date' => $date, 'time' => '14:00'], ['date' => $date, 'time' => '15:00']],
        ])->assertOk()->assertJsonPath('data.change_request.kind', 'time')->assertJsonPath('data.change_request.times.0.time', '14:00')->assertJsonPath('data.status', 'pending');
        $tigist = Client::where('phone', self::TIGIST)->first();
        $this->assertTrue($tigist->user->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'appointment.change_requested'));
        $this->assertTrue(OutboundMessage::where('channel', 'sms')->where('to', self::TIGIST)->where('body', 'like', '%choose another time%')->exists());

        // Tigist sees it and picks 14:00; the busy time is refused.
        $this->as(self::TIGIST);
        $this->getJson("/api/v1/client/appointments/{$id}")->assertOk()->assertJsonPath('data.change_request.message', 'Sorry for the trouble!');
        $this->postJson("/api/v1/client/appointments/{$id}/change", ['date' => $date, 'time' => '10:00'])->assertStatus(422)->assertJsonPath('code', 'slot_taken');
        $this->postJson("/api/v1/client/appointments/{$id}/change", ['date' => $date, 'time' => '14:00'])->assertOk()
            ->assertJsonPath('data.time', '14:00')->assertJsonPath('data.change_request', null);
        $this->assertTrue(User::where('phone', self::RECEPTION)->first()->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'appointment.changed'));

        // Now reception can approve.
        $this->as(self::RECEPTION);
        $this->postJson("/api/v1/staff/appointments/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('14:00', Appointment::find($id)->starts_at->format('H:i'));
    }
}
