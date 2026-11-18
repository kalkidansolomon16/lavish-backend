<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Push\FcmPushSender;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phone notifications: Firebase delivery, invalid-token clean-up, and appointment reminders. */
class PushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function fakeFirebaseKey(): string
    {
        // A throw-away RSA key made only for these tests (not a real Firebase key).
        $pem = file_get_contents(base_path('tests/fixtures/test-firebase-key.pem'));
        $path = storage_path('framework/testing-firebase.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode(['project_id' => 'lavish-test', 'client_email' => 'push@lavish-test.iam.gserviceaccount.com', 'private_key' => $pem]));

        return $path;
    }

    public function test_every_client_notification_goes_to_their_phones_through_firebase(): void
    {
        $path = $this->fakeFirebaseKey();
        $this->app->singleton(PushSender::class, fn () => new FcmPushSender($path));
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/lavish-test/messages/1'])
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $tigist = User::where('phone', self::TIGIST)->first();
        $this->as(self::TIGIST);
        $this->postJson('/api/v1/auth/devices', ['token' => 'phone-token-1', 'platform' => 'android'])->assertOk();
        $this->postJson('/api/v1/auth/devices', ['token' => 'old-phone-token', 'platform' => 'android'])->assertOk();

        $tigist->notify(new LavishNotification('appointment.approved', 'Appointment approved', 'HydraFacial on Thu at 10:00 is confirmed.', 'event_available', ['screen' => 'appointment', 'id' => 5]));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/lavish-test/messages:send')
            && $r['message']['notification']['title'] === 'Appointment approved'
            && collect((array) $r['message']['data'])->except('nid')->all() == ['type' => 'appointment.approved', 'screen' => 'appointment', 'id' => '5'] && ! empty($r['message']['data']->nid)
            && $r->hasHeader('Authorization', 'Bearer ya29.test'));
        // The phone the app was removed from is forgotten; the in-app inbox still has the notification.
        $this->assertSame(['phone-token-1'], DeviceToken::query()->where('user_id', $tigist->id)->pluck('token')->all());
        $this->assertSame(1, $tigist->notifications()->where('data->type', 'appointment.approved')->count());
    }

    public function test_a_push_failure_never_breaks_the_action(): void
    {
        $this->app->singleton(PushSender::class, fn () => new FcmPushSender('missing-file.json'));
        $tigist = User::where('phone', self::TIGIST)->first();
        $tigist->deviceTokens()->create(['token' => 't', 'platform' => 'android']);
        $tigist->notify(new LavishNotification('payment.received', 'Payment received', 'Thanks!'));
        $this->assertSame(1, $tigist->notifications()->where('data->type', 'payment.received')->count());
    }

    public function test_clients_are_reminded_the_evening_before_and_two_hours_before(): void
    {
        $appt = Appointment::query()->where('status', 'approved')->whereHas('client.user')->first();
        $user = $appt->client->user;
        $count = fn () => $user->notifications()->where('data->type', 'appointment.reminder')->count();

        $this->travelTo($appt->starts_at->copy()->subDay()->setTime(17, 0));
        $this->artisan('lavish:appointment-reminders')->assertSuccessful();
        $this->assertSame(0, $count());                       // too early in the day

        $this->travelTo($appt->starts_at->copy()->subDay()->setTime(18, 15));
        $this->artisan('lavish:appointment-reminders')->assertSuccessful();
        $this->artisan('lavish:appointment-reminders')->assertSuccessful();
        $this->assertSame(1, $count());                       // once
        $this->assertSame('Your appointment is tomorrow', $user->notifications()->latest()->first()->data['title']);

        $this->travelTo($appt->starts_at->copy()->subMinutes(120));
        $this->artisan('lavish:appointment-reminders')->assertSuccessful();
        $this->artisan('lavish:appointment-reminders')->assertSuccessful();
        $this->assertSame(2, $count());
        $this->assertSame('See you in 2 hours', $user->notifications()->latest()->first()->data['title']);
    }
}
