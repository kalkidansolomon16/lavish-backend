<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Paying outside the app (no Chapa): the client uploads a receipt, reception accepts or rejects it. */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['lavish.payments.online_enabled' => false]);
        $this->seedDemo();
    }

    private function bill(): Appointment
    {
        $this->as('+251922115566'); // Rahel
        $id = $this->postJson('/api/v1/client/appointments', [
            'service_id' => Service::where('name', 'Signature HydraFacial')->value('id'),
            'doctor_id' => User::where('phone', self::DR_MERON)->first()->doctor->id,
            'date' => now()->addDays(6)->toDateString(), 'time' => '10:00',
        ])->assertCreated()->json('data.id');

        return Appointment::find($id);
    }

    public function test_client_uploads_receipt_and_reception_accepts_it(): void
    {
        $appt = $this->bill();
        $pid = $appt->payment->id;

        $this->getJson('/api/v1/catalog/config')->assertJsonPath('data.online_payments_enabled', false);
        $this->postJson("/api/v1/client/payments/{$pid}/checkout", ['method' => 'telebirr'])->assertStatus(422)->assertJsonPath('code', 'online_payments_off');

        $this->post("/api/v1/client/payments/{$pid}/receipt", [
            'receipt' => UploadedFile::fake()->create('telebirr.jpg', 120, 'image/jpeg'), 'method' => 'telebirr', 'reference' => 'TB12345678',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.receipt.status', 'pending')
            ->assertJsonPath('data.receipt.reference', 'TB12345678');

        $this->as(self::RECEPTION);
        $list = $this->getJson('/api/v1/staff/payments?status=in_review')->assertOk()->assertJsonPath('meta.totals.receipts_to_check', 1);
        $receiptId = $list->json('data.0.receipt.id');
        $this->assertTrue($list->json('data.0.receipt.file.is_image'));
        $this->postJson("/api/v1/staff/payments/{$pid}/receipts/{$receiptId}/accept")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.method.value', 'telebirr')->assertJsonPath('data.provider_reference', 'TB12345678');
    }

    public function test_rejected_receipt_reopens_the_bill_and_tells_the_client(): void
    {
        $appt = $this->bill();
        $pid = $appt->payment->id;
        $rahel = User::where('phone', '+251922115566')->first();
        $this->post("/api/v1/client/payments/{$pid}/receipt", ['receipt' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf'), 'method' => 'bank_transfer'],
            ['Accept' => 'application/json'])->assertOk();

        $this->as(self::RECEPTION);
        $rid = $this->getJson('/api/v1/staff/payments?status=in_review')->json('data.0.receipt.id');
        $this->postJson("/api/v1/staff/payments/{$pid}/receipts/{$rid}/reject", ['reason' => 'Amount does not match'])->assertOk()->assertJsonPath('data.status', 'unpaid');
        $this->assertTrue($rahel->notifications()->get()->contains(fn ($n) => $n->data['type'] === 'payment.receipt_rejected'));
    }

    public function test_staff_record_a_payment_with_a_receipt(): void
    {
        $pid = $this->bill()->payment->id;
        $this->as(self::RECEPTION);
        $this->post("/api/v1/staff/payments/{$pid}/record", ['method' => 'cbe_birr', 'reference' => 'FT2611', 'receipt' => UploadedFile::fake()->create('cbe.png', 120, 'image/png')],
            ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.receipts.0.source', 'staff')->assertJsonPath('data.receipts.0.status', 'accepted');
    }
}
