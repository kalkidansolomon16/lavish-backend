<?php

namespace Tests\Feature\Api;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Treatments page: add / edit / hide / remove, separate permissions, app sees the changes. */
class TreatmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_managers_add_edit_hide_and_remove_treatments_and_the_app_follows(): void
    {
        // Reception can't see the page; the GM can do everything by default.
        $this->as(self::RECEPTION);
        $this->getJson('/api/v1/staff/services')->assertForbidden();

        $meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $this->as(self::GM);
        $this->assertContains('treatments', $this->getJson('/api/v1/auth/me')->json('data.pages'));
        $list = $this->getJson('/api/v1/staff/services')->assertOk();
        $this->assertNotEmpty($list->json('meta.doctors'));

        $id = $this->postJson('/api/v1/staff/services', [
            'name' => 'Lavish Glow Peel', 'price' => 6500, 'duration_minutes' => 50, 'icon' => 'auto_awesome',
            'description' => 'A gentle brightening peel.', 'doctor_ids' => [$meron->id],
        ])->assertCreated()->assertJsonPath('data.price', 6500)->assertJsonPath('data.doctor_ids', [$meron->id])->json('data.id');
        $this->postJson('/api/v1/staff/services', ['name' => 'Lavish Glow Peel', 'price' => 1, 'duration_minutes' => 30])->assertStatus(422);

        // Clients see it, and Dr. Meron offers it.
        $this->getJson('/api/v1/catalog/services')->assertJsonFragment(['name' => 'Lavish Glow Peel']);
        $this->assertTrue(collect($this->getJson("/api/v1/catalog/doctors/{$meron->id}")->json('data.services'))->pluck('id')->contains($id));

        $this->patchJson("/api/v1/staff/services/{$id}", ['price' => 7000, 'active' => false])->assertOk()->assertJsonPath('data.price', 7000);
        $this->assertNotContains($id, collect($this->getJson('/api/v1/catalog/services')->json('data'))->pluck('id'));

        $this->deleteJson("/api/v1/staff/services/{$id}")->assertOk();
        $this->assertNull(Service::find($id));
    }

    public function test_booked_and_online_treatments_cannot_be_deleted_and_permissions_are_separate(): void
    {
        $this->as(self::GM);
        $booked = Service::query()->whereHas('appointments')->where('is_online', false)->first();
        $this->deleteJson("/api/v1/staff/services/{$booked->id}")->assertStatus(422)->assertJsonPath('code', 'treatment_in_use');
        $this->deleteJson('/api/v1/staff/services/'.Service::online()->id)->assertStatus(422)->assertJsonPath('code', 'treatment_required');

        // Edit without delete.
        $this->as(self::CEO);
        $this->putJson('/api/v1/staff/permissions/gm/treatments.delete', ['granted' => false])->assertOk();
        $this->as(self::GM);
        $fresh = Service::create(['name' => 'Temp', 'slug' => 'temp', 'price' => 100, 'duration_minutes' => 30]);
        $this->patchJson("/api/v1/staff/services/{$fresh->id}", ['price' => 200])->assertOk();
        $this->deleteJson("/api/v1/staff/services/{$fresh->id}")->assertForbidden();
        $this->assertCount(1, Doctor::query()->whereHas('services', fn ($q) => $q->whereKey($booked->id))->limit(1)->get());
    }
}
