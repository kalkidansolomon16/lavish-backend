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
    public function __construct(private Notifier $notifier, private ChatService $chat, private Messenger $messenger) {}

    /**
     * @param  list<array{product_id?: ?int, custom_name?: ?string, quantity: int, instructions?: ?string, reminder_times?: ?list<string>, starts_on?: ?string, duration_days?: ?int}>  $items
     */
    public function create(Doctor $doctor, Client $client, ?string $note, array $items, bool $anyClient = false): Prescription
    {
        // Doctors prescribe for their own patients; the CEO (the clinic's medical director) for any client.
        $isPatient = $anyClient || $client->primary_doctor_id === $doctor->id
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
                    'reminder_times' => ! empty($i['reminder_times']) ? collect($i['reminder_times'])->unique()->sort()->values()->all() : null,
                    'starts_on' => ! empty($i['reminder_times']) ? ($i['starts_on'] ?? now()->toDateString()) : null,
                    'duration_days' => ! empty($i['reminder_times']) ? ($i['duration_days'] ?? null) : null,
                    'was_out_of_stock' => $product ? $product->available() <= 0 : false,
                ]);
            }

            return $rx;
        });

        $count = count($items);
        $this->notifier->client($client, new LavishNotification('prescription.new', 'New prescription',
            "{$doctor->name} sent you {$count} item(s) for your routine.", 'medication', ['screen' => 'prescription', 'id' => $rx->id]));
        $this->emailPrescription($rx->load('items.product'), $client, $doctor);

        return $rx->load('items.product');
    }

    /** The prescription by email too (to the client's email on file), with how to use each item and the reminders. */
    private function emailPrescription(Prescription $rx, Client $client, Doctor $doctor): void
    {
        $to = $client->email ?: $client->user?->email;
        if (! $to) {
            return;
        }
        $lines = $rx->items->map(function (PrescriptionItem $i, int $n) {
            $name = $i->product?->name ?? $i->custom_name;
            $row = ($n + 1).". {$name} × {$i->quantity}";
            if ($i->instructions) {
                $row .= "\n   How to use: {$i->instructions}";
            }
            if (! empty($i->reminder_times)) {
                $row .= "\n   Reminders: ".implode(', ', $i->reminder_times).($i->duration_days ? " for {$i->duration_days} days" : '').
                    ($i->starts_on ? ' from '.$i->starts_on->format('j M Y') : '');
            }
            if ($i->product) {
                $row .= "\n   Available in the Lavish shop".($i->was_out_of_stock ? ' (out of stock right now — we’ll let you know when it’s back)' : '');
            }

            return $row;
        })->implode("\n\n");
        $first = trim(explode(' ', $client->name)[0] ?? '');
        $body = "Dear {$first},\n\n{$doctor->name} sent you a prescription on ".$rx->created_at->format('j M Y').":\n\n{$lines}".
            ($rx->note ? "\n\nNote from {$doctor->name}:\n{$rx->note}" : '').
            "\n\nYou can see it in the Lavish app under Care → Prescriptions, order the Lavish products there, and get reminders on your phone.".
            "\n\nLavish Medical Spa";
        $this->messenger->email($to, "Your prescription from {$doctor->name}", $body, $rx);
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
