<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Doctor;
use App\Models\Enrollment;
use App\Models\News;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every read endpoint answers 200 with the demo data, for every kind of account.
 * Catches broken queries, missing eager loads and resource errors.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_public_catalogue(): void
    {
        $doctor = Doctor::query()->where('access', 'active')->first();
        $service = $doctor->services()->first();
        foreach ([
            '/api/v1/catalog/config', '/api/v1/catalog/branches', '/api/v1/catalog/services', '/api/v1/catalog/doctors',
            '/api/v1/catalog/doctors/'.$doctor->id, '/api/v1/catalog/products', '/api/v1/catalog/products/'.Product::first()->id,
            '/api/v1/catalog/news', '/api/v1/catalog/news/'.News::where('published', true)->first()->id,
            '/api/v1/catalog/courses', '/api/v1/catalog/courses/1',
            '/api/v1/catalog/availability?service_id='.$service->id.'&date='.now()->addDays(3)->toDateString().'&doctor_id='.$doctor->id,
            '/api/v1/catalog/availability?service_id='.$service->id.'&date='.now()->addDays(3)->toDateString(),
        ] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson('/api/v1/catalog/doctors/'.Doctor::where('access', 'pending')->first()->id)->assertNotFound();
    }

    public function test_client_app_screens(): void
    {
        $user = $this->as(self::TIGIST);
        $c = $user->client;
        $urls = [
            '/api/v1/auth/me', '/api/v1/notifications', '/api/v1/conversations', '/api/v1/client/chats',
            '/api/v1/client/appointments', '/api/v1/client/appointments?tab=upcoming', '/api/v1/client/appointments?tab=requests', '/api/v1/client/appointments?tab=past',
            '/api/v1/client/appointments/'.$c->appointments()->first()->id,
            '/api/v1/client/payments', '/api/v1/client/orders', '/api/v1/client/orders/'.$c->orders()->first()->id,
            '/api/v1/client/prescriptions', '/api/v1/client/prescriptions/'.$c->prescriptions()->first()->id,
            '/api/v1/client/complaints',
        ];
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
        $conversation = Conversation::query()->where('client_id', $c->id)->first();
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk()->assertJsonCount(3, 'data');

        // someone else's records are invisible
        $other = Appointment::query()->where('client_id', '!=', $c->id)->first();
        $this->getJson('/api/v1/client/appointments/'.$other->id)->assertNotFound();
    }

    public function test_student_app_screens(): void
    {
        $user = $this->as(self::EDEN);
        $e = $user->enrollments()->first();
        foreach (['/api/v1/auth/me', '/api/v1/student/enrollments', '/api/v1/student/enrollments/'.$e->id, '/api/v1/student/sessions', '/api/v1/conversations'] as $url) {
            $this->getJson($url)->assertOk();
        }
        $module = $e->course->modules()->where('published', true)->first();
        $this->getJson('/api/v1/student/modules/'.$module->id)->assertOk();
        $done = $user->enrollments()->whereHas('certificate')->first();
        $this->getJson("/api/v1/student/enrollments/{$done->id}/certificate")->assertOk()->assertJsonPath('data.student_name', 'Eden Fikru');
        // students can't use client routes
        $this->getJson('/api/v1/client/appointments')->assertForbidden();
    }

    public function test_every_staff_portal_loads_its_pages(): void
    {
        $pages = [
            'page.appointments' => ['/api/v1/staff/appointments', '/api/v1/staff/appointments/classes?from='.now()->toDateString().'&to='.now()->addDays(7)->toDateString()],
            'page.clients|appointments.approve|appointments.create' => ['/api/v1/staff/clients'],
            'page.payments' => ['/api/v1/staff/payments'],
            'page.products|page.orders|page.prescriptions' => ['/api/v1/staff/products', '/api/v1/staff/products/movements'],
            'page.orders' => ['/api/v1/staff/orders'],
            'page.prescriptions' => ['/api/v1/staff/prescriptions'],
            'page.complaints' => ['/api/v1/staff/complaints'],
            'page.academy' => ['/api/v1/staff/academy/courses', '/api/v1/staff/academy/enrollments'],
            'page.staff' => ['/api/v1/staff/members'],
            'page.news' => ['/api/v1/staff/news'],
            'page.permissions' => ['/api/v1/staff/permissions'],
            'academy.teach' => ['/api/v1/staff/teaching/courses', '/api/v1/staff/teaching/sessions', '/api/v1/staff/teaching/students', '/api/v1/staff/teaching/submissions'],
        ];

        foreach ([self::CEO, self::GM, self::RECEPTION, self::ECOMMERCE, self::DR_MERON, self::NURSE] as $phone) {
            $user = $this->as($phone);
            $this->getJson('/api/v1/staff/dashboard')->assertOk();
            $this->getJson('/api/v1/staff/search?q=ti')->assertOk();
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.type', 'staff');
            // Lavish Team is open to every staff member.
            $this->assertContains('team', $this->getJson('/api/v1/auth/me')->json('data.pages'));
            foreach (['/api/v1/staff/team/people', '/api/v1/staff/team/announcements', '/api/v1/staff/team/groups'] as $url) {
                $this->getJson($url)->assertOk();
            }
            foreach ($pages as $permission => $urls) {
                foreach ($urls as $url) {
                    $this->getJson($url)->assertStatus($user->hasPermission(...explode('|', $permission)) ? 200 : 403);
                }
            }
        }
        $this->as(self::DR_MERON);
        $this->getJson('/api/v1/staff/dashboard?portal=teacher')->assertOk()->assertJsonPath('data.portal', 'teacher');
    }

    public function test_staff_detail_endpoints(): void
    {
        $this->as(self::CEO);
        $appt = Appointment::query()->where('status', 'pending')->first();
        foreach ([
            '/api/v1/staff/appointments/'.$appt->id, '/api/v1/staff/appointments/'.$appt->id.'/check',
            '/api/v1/staff/clients/'.Client::first()->id, '/api/v1/staff/orders/'.Order::first()->id,
            '/api/v1/staff/complaints/'.Complaint::first()->id, '/api/v1/staff/products/'.Product::first()->id,
            '/api/v1/conversations/'.Conversation::query()->where('type', 'group')->first()->id.'/messages',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->assertNotEmpty($this->getJson('/api/v1/staff/appointments/'.$appt->id.'/check')->json('data.suggestions'));
        $this->assertGreaterThan(0, Payment::count() + Prescription::count() + Enrollment::count());
    }

    public function test_doctor_sees_only_own_appointments_and_patients(): void
    {
        $user = $this->as(self::DR_MERON);
        $ids = collect($this->getJson('/api/v1/staff/appointments?per_page=200')->assertOk()->json('data'))->pluck('doctor.id')->unique()->values()->all();
        $this->assertSame([$user->doctor->id], $ids);

        $foreign = Appointment::query()->where('doctor_id', '!=', $user->doctor->id)->whereNotNull('doctor_id')->first();
        $this->getJson('/api/v1/staff/appointments/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/staff/appointments/'.$foreign->id.'/approve')->assertForbidden();
    }
}
