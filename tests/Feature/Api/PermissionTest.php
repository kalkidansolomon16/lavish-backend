<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_hierarchy_rules(): void
    {
        $this->as(self::GM);
        $matrix = $this->getJson('/api/v1/staff/permissions')->assertOk();
        $reception = collect($matrix->json('data.roles'))->firstWhere('key', 'reception');
        $this->assertContains('page.products', $reception['editable']);       // GM holds it, reception is below
        $this->assertNotContains('finance.revenue', $reception['editable']);  // GM doesn't hold it
        $this->assertSame([], collect($matrix->json('data.roles'))->firstWhere('key', 'ceo')['editable']);

        $this->putJson('/api/v1/staff/permissions/reception/page.products', ['granted' => true])->assertOk();
        $this->putJson('/api/v1/staff/permissions/reception/finance.revenue', ['granted' => false])->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        $this->putJson('/api/v1/staff/permissions/ceo/page.news', ['granted' => false])->assertForbidden();
        $this->putJson('/api/v1/staff/permissions/gm/page.news', ['granted' => false])->assertForbidden();   // own level
        $this->putJson('/api/v1/staff/permissions/doctor/appointments.complete', ['granted' => false])->assertForbidden(); // GM lacks it

        $this->as(self::RECEPTION);
        $this->putJson('/api/v1/staff/permissions/nurse/page.clients', ['granted' => false])->assertForbidden(); // no permissions.manage
    }

    public function test_a_revoked_permission_takes_effect_immediately(): void
    {
        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/payments')->assertOk();

        $this->as(self::CEO);
        $this->putJson('/api/v1/staff/permissions/reception/page.payments', ['granted' => false])->assertOk();

        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/payments')->assertForbidden();
        $this->assertNotContains('payments', $this->getJson('/api/v1/auth/me')->json('data.pages'));
        $this->assertContains('team', $this->getJson('/api/v1/auth/me')->json('data.pages')); // Lavish Team is always there

        $this->as(self::CEO);
        $this->postJson('/api/v1/staff/permissions/reset')->assertOk();
        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/payments')->assertOk();
    }

    public function test_view_and_change_are_separate_permissions(): void
    {
        // A role can see staff without being allowed to add them.
        $this->as(self::CEO);
        $this->putJson('/api/v1/staff/permissions/reception/page.staff', ['granted' => true])->assertOk();
        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/members')->assertOk()->assertJsonPath('meta.creatable_roles', []);
        $this->postJson('/api/v1/staff/members', ['role' => 'nurse', 'name' => 'Test Nurse', 'phone' => '0911998877'])->assertForbidden();

        // News: create without delete.
        $this->as(self::GM);
        $id = $this->postJson('/api/v1/staff/news', ['title' => 'Hello', 'body' => 'Open on Saturday', 'tag' => 'Update', 'published' => true])->assertCreated()->json('data.id');
        $this->as(self::CEO);
        $this->putJson('/api/v1/staff/permissions/gm/news.delete', ['granted' => false])->assertOk();
        $this->as(self::GM);
        $this->deleteJson("/api/v1/staff/news/{$id}")->assertForbidden();
    }

    public function test_nurse_role_has_clinical_support_access(): void
    {
        $this->as(self::NURSE);
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('nurse', $me->json('data.portal.key'));
        $this->assertEqualsCanonicalizing(['dashboard', 'appointments', 'clients', 'prescriptions', 'team'], $me->json('data.pages'));
        $this->getJson('/api/v1/staff/appointments')->assertOk();
        $this->getJson('/api/v1/staff/payments')->assertForbidden();
        $this->getJson('/api/v1/staff/dashboard')->assertOk()->assertJsonPath('data.portal', 'nurse');
    }

    public function test_staff_management_respects_the_hierarchy(): void
    {
        $this->as(self::GM);
        $res = $this->getJson('/api/v1/staff/members')->assertOk();
        $this->assertNotContains('gm', $res->json('meta.creatable_roles'));   // the GM can't add another GM

        $new = $this->postJson('/api/v1/staff/members', ['name' => 'Lulit Haile', 'phone' => '0920 11 22 33', 'role' => 'reception', 'position' => 'Front Desk'])
            ->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^LA-[A-Z2-9]{6}$/', $new['temporary_password']);
        $this->postJson('/api/v1/staff/members', ['name' => 'X', 'phone' => '0920 11 22 34', 'role' => 'gm'])->assertForbidden();

        // GM may add but not suspend (staff.suspend is CEO only by default).
        $this->postJson("/api/v1/staff/members/{$new['member']['id']}/status", ['status' => 'suspended'])->assertForbidden();
        $this->as(self::CEO);
        $this->postJson("/api/v1/staff/members/{$new['member']['id']}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $ceo = User::where('phone', self::CEO)->first();
        $this->postJson("/api/v1/staff/members/{$ceo->id}/status", ['status' => 'suspended'])->assertForbidden();   // never yourself
    }

    public function test_doctor_access_grant_and_ban(): void
    {
        $sara = User::where('phone', '+251911500005')->first()->doctor;
        $this->as(self::GM);
        $this->postJson("/api/v1/staff/doctors/{$sara->id}/access", ['access' => 'active'])->assertOk()->assertJsonPath('data.access', 'active');
        $this->postJson("/api/v1/staff/doctors/{$sara->id}/access", ['access' => 'banned'])->assertForbidden();   // banning is CEO only

        $this->as(self::CEO);
        $this->postJson("/api/v1/staff/doctors/{$sara->id}/access", ['access' => 'banned'])->assertOk();
        $this->getJson("/api/v1/catalog/doctors/{$sara->id}")->assertNotFound();   // hidden from the app
    }
}
