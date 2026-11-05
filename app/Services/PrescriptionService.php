<?php

namespace App\Services;

use App\Enums\PrescriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\Doctor;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Notifications\LavishNotification;
use Illuminate\Support\Facades\DB;

class PrescriptionService
{
    public function __construct(private Notifier $notifier, private ChatService $chat) {}

    /**
     * @param  list<array{product_id?: ?int, custom_name?: ?string, quantity: int, instructions?: ?string}>  $items
     */
    public function create(Doctor $doctor, Client $client, ?string $note, array $items): Prescription
    {
        $isPatient = $client->primary_doctor_id === $doctor->id
            || $client->appointments()->where('doctor_id', $doctor->id)->whereIn('status', ['approved', 'completed'])->exists();
        if (! $isPatient) {
            throw BusinessRuleException::make('not_your_patient', 'You can only prescribe for your own patients.', status: 403);
        }

        $rx = DB::transaction(function () use ($doctor, $client, $note, $items) {
            $rx = Prescription::create(['client_id' => $client->id, 'doctor_id' => $doctor->id, 'note' => $note, 'status' => PrescriptionStatus::Sent]);
            foreach ($items as $i) {
                $product = ! empty($i['product_id']) ? Product::query()->withStock()->findOrFail($i['product_id']) : null;
                $rx->items()->create([
                    'product_id' => $product?->id,
                    'custom_name' => $product ? null : $i['custom_name'],
                    'quantity' => $i['quantity'],
                    'instructions' => $i['instructions'] ?? null,
                    'was_out_of_stock' => $product ? $product->available() <= 0 : false,
                ]);
            }

            return $rx;
        });

        $count = count($items);
        $this->notifier->client($client, new LavishNotification('prescription.new', 'New prescription',
            "{$doctor->name} sent you {$count} item(s) for your routine.", 'medication', ['screen' => 'prescription', 'id' => $rx->id]));

        return $rx->load('items.product');
    }

    /** Re-suggests a product that was out of stock when prescribed. Arrives in the client's chat. */
    public function suggestRestocked(PrescriptionItem $item, Doctor $doctor): PrescriptionItem
    {
        $rx = $item->prescription;
        if ($rx->doctor_id !== $doctor->id) {
            throw BusinessRuleException::make('not_your_prescription', 'This is another doctor’s prescription.', status: 403);
        }
        $product = $item->product ? Product::query()->withStock()->find($item->product_id) : null;
        if (! $item->was_out_of_stock || ! $product || $item->suggested_at) {
            throw BusinessRuleException::make('nothing_to_suggest', 'This item doesn’t need a new suggestion.');
        }
        if ($product->available() <= 0) {
            throw BusinessRuleException::make('still_out_of_stock', "{$product->name} is still out of stock.");
        }
        if (! $rx->client->user_id) {
            throw BusinessRuleException::make('client_not_on_app', 'This client isn’t on the app yet.');
        }

        $conversation = $this->chat->clientDoctor($rx->client, $doctor);
        $this->chat->systemMessage($conversation, $doctor->user,
            "Good news — {$product->name}, which I prescribed for you, is back in stock. You can order it from the Shop in the app. (".($item->instructions ?: 'Use as directed').')');
        $item->update(['suggested_at' => now()]);

        return $item;
    }
}
