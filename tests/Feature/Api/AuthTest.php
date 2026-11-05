<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_staff_sign_in_on_the_web_with_phone_or_email(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => '0911 00 11 22', 'password' => 'password', 'app' => 'web'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'roles', 'permissions', 'pages']])
            ->assertJsonPath('user.portal.key', 'ceo');

        $this->postJson('/api/v1/auth/login', ['login' => 'gm@lavish.et', 'password' => 'password', 'app' => 'web'])
            ->assertOk()->assertJsonPath('user.portal.key', 'gm');

        $this->postJson('/api/v1/auth/login', ['login' => '0911001122', 'password' => 'wrong', 'app' => 'web'])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_each_app_only_accepts_its_own_accounts(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => self::TIGIST, 'password' => 'password', 'app' => 'web'])
            ->assertForbidden()->assertJsonPath('code', 'wrong_app');
        $this->postJson('/api/v1/auth/login', ['login' => self::CEO, 'password' => 'password', 'app' => 'mobile'])
            ->assertForbidden()->assertJsonPath('code', 'wrong_app');
        $this->postJson('/api/v1/auth/login', ['login' => self::TIGIST, 'password' => 'password', 'app' => 'mobile'])
            ->assertOk()->assertJsonPath('user.type', 'client');
    }

    public function test_suspended_staff_and_doctors_without_access_cannot_sign_in(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => '+251251117788', 'password' => 'password', 'app' => 'web'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');           // Mekdes — suspended
        $this->postJson('/api/v1/auth/login', ['login' => '+251911500005', 'password' => 'password', 'app' => 'web'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');           // Dr. Sara — access pending
    }

    public function test_client_registers_with_an_sms_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Meklit Bogale', 'phone' => '0918 77 66 55', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonPath('phone', '+251918776655');

        $this->postJson('/api/v1/auth/login', ['login' => '0918776655', 'password' => 'secret123', 'app' => 'mobile'])
            ->assertForbidden()->assertJsonPath('code', 'phone_not_verified');

        $this->postJson('/api/v1/auth/register/verify', ['phone' => '0918776655', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'otp_invalid');

        $token = $this->postJson('/api/v1/auth/register/verify', ['phone' => '0918776655', 'code' => '123456'])
            ->assertCreated()->assertJsonPath('user.type', 'client')->json('token');

        $this->withToken($token)->getJson('/api/v1/client/appointments')->assertOk();
        $this->assertDatabaseHas('clients', ['phone' => '+251918776655', 'name' => 'Meklit Bogale']);
    }

    public function test_a_walk_in_client_who_registers_keeps_their_history(): void
    {
        // Almaz was booked at reception and has no app account yet.
        $this->postJson('/api/v1/auth/register', ['name' => 'Almaz T.', 'phone' => '+251977314590', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertCreated();
        $token = $this->postJson('/api/v1/auth/register/verify', ['phone' => '+251977314590', 'code' => '123456'])->json('token');

        $me = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('Almaz Tesfaye', Client::find($me->json('data.client_id'))->name);
        $this->assertNotNull(Client::find($me->json('data.client_id'))->history);
    }

    public function test_student_must_replace_the_temporary_password(): void
    {
        // Kidist has never logged in: she still holds a temporary password.
        $login = $this->postJson('/api/v1/auth/login', ['login' => '0914882107', 'password' => 'password', 'app' => 'mobile'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/v1/student/enrollments')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');

        $this->withToken($token)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'password', 'password' => 'my-new-pass', 'password_confirmation' => 'my-new-pass',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->withToken($token)->getJson('/api/v1/student/enrollments')->assertOk();
    }

    public function test_client_resets_a_forgotten_password(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::TIGIST])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => self::TIGIST, 'code' => '123456', 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/v1/auth/login', ['login' => self::TIGIST, 'password' => 'brand-new-1', 'app' => 'mobile'])->assertOk();
        // Students are told to call the academy instead.
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => self::EDEN])->assertOk()->assertJsonPath('code', 'call_academy');
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => self::TIGIST, 'password' => 'password', 'app' => 'mobile'])->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->assertSame(0, User::where('phone', self::TIGIST)->first()->tokens()->count());
    }
}
