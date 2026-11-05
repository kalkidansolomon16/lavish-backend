<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_order_is_tracked_to_delivery_and_stock_is_deducted(): void
    {
        $spf = Product::where('sku', 'LV-SUN-003')->first();   // 5 in stock, 3 reserved by open demo orders → 2 available
        $this->as(self::TIGIST);
        $this->getJson("/api/v1/catalog/products/{$spf->id}")->assertJsonPath('data.available', 2);

        $order = fn (int $qty) => $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $spf->id, 'quantity' => $qty]],
            'delivery_address' => 'Bole, Addis Ababa', 'payment_method' => 'pay_on_delivery',
        ]);
        $order(3)->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');
        $id = $order(2)->assertCreated()
            ->assertJsonPath('data.total', 2 * 1900 + 150)
            ->assertJsonPath('data.timeline.0.status', 'placed')
            ->json('data.id');

        $this->as(self::ECOMMERCE);
        foreach (['confirmed', 'preparing', 'ready'] as $step) {
            $this->postJson("/api/v1/staff/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', $step);
        }
        $this->postJson("/api/v1/staff/orders/{$id}/advance")->assertStatus(422)->assertJsonPath('code', 'courier_required');
        $this->postJson("/api/v1/staff/orders/{$id}/advance", ['courier' => ['name' => 'Yared Bekele', 'phone' => '+251922334455']])
            ->assertOk()->assertJsonPath('data.status', 'out');

        $before = Product::query()->withStock()->find($spf->id)->stockTotal();
        $res = $this->postJson("/api/v1/staff/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame($before - 2, Product::query()->withStock()->find($spf->id)->stockTotal());
        $this->assertNotEmpty($res->json('meta.low_stock'));                                  // SPF is under its reorder level
        $this->assertSame('paid', Order::find($id)->payment->status->value);                  // pay on delivery collected
        $this->assertSame(6, Order::find($id)->emails()->count());                            // one email per step

        $this->as(self::TIGIST);
        $timeline = collect($this->getJson("/api/v1/client/orders/{$id}")->json('data.timeline'));
        $this->assertTrue($timeline->every(fn ($s) => $s['at'] !== null));
    }

    public function test_cancelled_paid_order_is_flagged_for_refund(): void
    {
        $order = Order::where('status', 'preparing')->first();   // demo order paid by Telebirr
        $this->as(self::ECOMMERCE);
        $this->postJson("/api/v1/staff/orders/{$order->id}/cancel", ['reason' => 'Out of stock'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('refund_due', $order->payment->fresh()->status->value);
        $this->postJson("/api/v1/staff/orders/{$order->id}/advance")->assertStatus(422)->assertJsonPath('code', 'order_closed');
    }

    public function test_inventory_receive_transfer_adjust(): void
    {
        $tonic = Product::where('sku', 'LV-HAI-005')->first();   // out of stock; a doctor is waiting on it
        [$addis, $adama] = [Branch::where('name', 'Addis Ababa')->first(), Branch::where('name', 'Adama')->first()];
        $this->as(self::ECOMMERCE);

        $this->postJson("/api/v1/staff/products/{$tonic->id}/receive", ['branch_id' => $addis->id, 'quantity' => 10, 'reference' => 'INV-1'])
            ->assertOk()->assertJsonPath('data.stock', 10);
        // the prescribing doctor was told it's back
        $this->assertDatabaseHas('notifications', ['type' => 'product.restocked_for_patients']);

        $this->postJson("/api/v1/staff/products/{$tonic->id}/transfer", ['from_branch_id' => $addis->id, 'to_branch_id' => $adama->id, 'quantity' => 4])
            ->assertForbidden();   // E-commerce can't transfer (General Manager can)
        $this->as(self::GM);
        $this->postJson("/api/v1/staff/products/{$tonic->id}/transfer", ['from_branch_id' => $addis->id, 'to_branch_id' => $adama->id, 'quantity' => 40])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_branch_stock');
        $res = $this->postJson("/api/v1/staff/products/{$tonic->id}/transfer", ['from_branch_id' => $addis->id, 'to_branch_id' => $adama->id, 'quantity' => 4])->assertOk();
        $this->assertSame(4, collect($res->json('data.branches'))->firstWhere('name', 'Adama')['quantity']);

        $this->as(self::ECOMMERCE);
        $this->postJson("/api/v1/staff/products/{$tonic->id}/adjust", ['branch_id' => $adama->id, 'counted' => 3, 'reason' => 'Damaged'])
            ->assertOk()->assertJsonPath('data.stock', 9);
        $this->assertSame(['adjust', 'transfer', 'in'], collect($this->getJson("/api/v1/staff/products/movements?product_id={$tonic->id}")->json('data'))->take(3)->pluck('type')->all());
    }

    public function test_doctor_prescribes_and_e_commerce_turns_it_into_an_order(): void
    {
        $client = User::where('phone', self::TIGIST)->first()->client;
        $serum = Product::where('sku', 'LV-SER-001')->first();
        $this->as(self::DR_MERON);
        $rx = $this->postJson('/api/v1/staff/prescriptions', [
            'client_id' => $client->id, 'note' => 'Brighten dark spots',
            'items' => [['product_id' => $serum->id, 'quantity' => 1, 'instructions' => 'Every morning'], ['custom_name' => 'Vitamin D 1000 IU', 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.items.1.is_custom', true)->json('data.id');

        $this->as(self::TIGIST);
        $this->getJson("/api/v1/client/prescriptions/{$rx}")->assertOk()->assertJsonPath('data.items.0.product.in_stock', true);

        $this->as(self::ECOMMERCE);
        $this->postJson("/api/v1/staff/prescriptions/{$rx}/fulfil")->assertCreated()->assertJsonCount(1, 'data.items');
        $this->assertSame('ordered', Prescription::find($rx)->status->value);
        $this->postJson("/api/v1/staff/prescriptions/{$rx}/fulfil")->assertStatus(422)->assertJsonPath('code', 'already_ordered');
    }

    public function test_doctor_resuggests_a_restocked_product_in_the_chat(): void
    {
        $item = PrescriptionItem::query()->where('was_out_of_stock', true)->whereHas('product', fn ($p) => $p->where('sku', 'LV-HAI-005'))->first();
        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/prescription-items/{$item->id}/suggest")->assertStatus(422)->assertJsonPath('code', 'still_out_of_stock');

        $this->as(self::ECOMMERCE);
        $this->postJson('/api/v1/staff/products/'.$item->product_id.'/receive', ['branch_id' => Branch::main()->id, 'quantity' => 5])->assertOk();

        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/prescription-items/{$item->id}/suggest")->assertOk();
        $this->assertDatabaseHas('messages', ['body' => 'Good news — Scalp Revive Hair Tonic, which I prescribed for you, is back in stock. You can order it from the Shop in the app. (Massage into scalp every evening)']);
    }
}
