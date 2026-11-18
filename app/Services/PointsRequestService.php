<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PointsRequest;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * A member uses their points by asking in the app:
 *
 *   1. The client picks what for (discount on shop products, on a treatment, or something else), how many
 *      points, and adds a note. The points are held from then on.
 *   2. Reception (rewards.requests) looks at it:
 *        treatment → takes it off the client's treatment bill (or marks it given with a note);
 *        shop      → sends it to e-commerce (orders.points), who take it off the client's shop order;
 *        other     → marks it done with a note.
 *      Or rejects it with a reason — the held points are free again.
 *   3. On completion the points are taken (oldest first) and the client sees what they were used for and the
 *      balance left.
 */
class PointsRequestService
{
    public function __construct(
        private LoyaltyService $loyalty,
        private PaymentService $payments,
        private Notifier $notifier,
    ) {}

    public function create(Client $client, string $kind, int $points, ?string $note): PointsRequest
    {
        if (! $this->loyalty->isMember($client)) {
            throw BusinessRuleException::make('not_member', 'You aren’t in Lavish Rewards yet. Ask at the Lavish front desk to be enrolled.');
        }
        if ($points < 1) {
            throw BusinessRuleException::make('points_required', 'Enter how many points you want to use.');
        }

        return DB::transaction(function () use ($client, $kind, $points, $note) {
            $this->loyalty->membership($client); // make sure the row exists, then lock it so two requests can't hold the same points
            \App\Models\ClientLoyalty::query()->whereKey($client->id)->lockForUpdate()->first();
            $available = $this->loyalty->availablePoints($client);
            if ($points > $available) {
                throw BusinessRuleException::make('not_enough_points', "You have {$available} points available.", ['available' => $available]);
            }
            $req = PointsRequest::create([
                'client_id' => $client->id, 'kind' => $kind, 'points' => $points, 'status' => 'pending',
                'value_etb' => $this->loyalty->settings()->pointsToEtb($points), 'note' => $note,
            ]);
            $this->notifier->staff('rewards.requests', new LavishNotification('points.request', 'New points request',
                "{$client->name} wants to use ".number_format($points).' points — '.strtolower($req->kindLabel()).'.', 'stars', null, '/rewards?tab=requests'));

            return $req;
        });
    }

    public function cancel(PointsRequest $req): PointsRequest
    {
        if ($req->status !== 'pending') {
            throw BusinessRuleException::make('request_not_open', 'This request is already being handled.');
        }
        $req->update(['status' => 'cancelled']);

        return $req;
    }

    /** Reception sends a shop request to e-commerce. */
    public function forward(PointsRequest $req, User $by, ?string $note = null): PointsRequest
    {
        $this->assertOpen($req);
        if ($req->kind !== 'shop' || $req->status !== 'pending') {
            throw BusinessRuleException::make('request_not_forwardable', 'Only new shop requests are sent to e-commerce.');
        }
        $req->update(['status' => 'forwarded', 'forwarded_by' => $by->id, 'forwarded_at' => now(), 'staff_note' => $note ?? $req->staff_note]);
        $req->loadMissing('client');
        $this->notifier->staff('orders.points', new LavishNotification('points.forwarded', 'Points to take off a shop order',
            "{$req->client->name}: ".number_format($req->points).' points (ETB '.number_format($req->value_etb).') off their next shop order. Sent by '.$by->name.'.',
            'shopping_bag', null, '/orders?tab=points'));
        $this->notifier->client($req->client, new LavishNotification('points.forwarded', 'Your points request is with Lavish Shop',
            'We’ll take '.number_format($req->points).' points off your shop order.', 'shopping_bag', ['screen' => 'points_requests']));

        return $req->refresh();
    }

    /**
     * Completes a request. With a bill: the points come off that bill (never more than it costs — unused points
     * stay with the client). Without a bill: the points are taken and the note says how they were used.
     */
    public function complete(PointsRequest $req, User $by, ?Payment $payment = null, ?string $note = null): PointsRequest
    {
        $this->assertOpen($req);
        if (! $payment && blank($note)) {
            throw BusinessRuleException::make('note_required', 'Choose the bill to take the points off, or write how the points were used.');
        }

        return DB::transaction(function () use ($req, $by, $payment, $note) {
            $req = PointsRequest::query()->whereKey($req->id)->lockForUpdate()->first();
            $this->assertOpen($req);
            $client = $req->client;
            $s = $this->loyalty->settings();
            $points = $req->points;
            $discount = $req->value_etb;

            if ($payment) {
                $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
                if ($payment->client_id !== $client->id || ! $payment->isOpen()) {
                    throw BusinessRuleException::make('payment_not_open', 'Choose one of this client’s unpaid bills.');
                }
                if ($payment->payable_type === 'gift_card') {
                    throw BusinessRuleException::make('gift_card_purchase', 'Points can’t be used to buy a gift card.');
                }
                $due = $payment->dueAmount();
                $discount = min($due, $s->pointsToEtb($points));
                $points = min($points, max(1, $s->etbToPoints($discount)));
                if ($discount <= 0) {
                    throw BusinessRuleException::make('nothing_due', 'Nothing is left to pay on this bill.');
                }
            }

            $balance = $this->loyalty->membership($client)->points_balance;
            if ($points > $balance) {
                throw BusinessRuleException::make('not_enough_points', "{$client->name} has only {$balance} points now.", ['balance' => $balance]);
            }

            $what = $payment ? "{$req->kindLabel()}: {$payment->description} (ETB ".number_format($discount).' off)' : "{$req->kindLabel()}: {$note}";
            $this->loyalty->debit($client, $points, 'redeem', 'points_request', $req->id, $what, $by, $note);

            if ($payment) {
                $payment->forceFill([
                    'discount_amount' => $payment->discount_amount + $discount,
                    'points_used' => $payment->points_used + $points,
                    'meta' => [...($payment->meta ?? []), 'points_request_id' => $req->id],
                ])->save();
                if ($payment->dueAmount() === 0) {
                    $this->payments->markPaid($payment, PaymentMethod::Voucher, null, null, $by);
                }
            }

            $req->update([
                'status' => 'completed', 'handled_by' => $by->id, 'handled_at' => now(), 'payment_id' => $payment?->id,
                'points_used' => $points, 'discount_etb' => $payment ? $discount : $req->value_etb, 'staff_note' => $note ?? $req->staff_note,
            ]);
            AuditLog::record($by, 'points.request.complete', 'client', $client->id, $note, ['points_balance' => $balance],
                ['points_balance' => $balance - $points, 'request_id' => $req->id, 'payment_id' => $payment?->id]);

            $left = $balance - $points;
            $this->notifier->client($client, new LavishNotification('points.used', 'Your points were used',
                number_format($points).' points used — '.($payment ? "ETB ".number_format($discount)." off {$payment->description}" : $note).
                '. You have '.number_format($left).' points left.'.($points < $req->points ? ' The bill was smaller, so '.number_format($req->points - $points).' points stay with you.' : ''),
                'stars', ['screen' => 'points_requests']));

            return $req->refresh();
        });
    }

    public function reject(PointsRequest $req, User $by, string $reason): PointsRequest
    {
        $this->assertOpen($req);
        $req->update(['status' => 'rejected', 'handled_by' => $by->id, 'handled_at' => now(), 'staff_note' => $reason]);
        $this->notifier->client($req->client, new LavishNotification('points.rejected', 'Points request not approved',
            "Your request to use ".number_format($req->points)." points wasn’t approved: {$reason}. Your points are available again.", 'info', ['screen' => 'points_requests']));

        return $req->refresh();
    }

    private function assertOpen(PointsRequest $req): void
    {
        if (! $req->isOpen()) {
            throw BusinessRuleException::make('request_not_open', 'This request was already '.$req->status.'.');
        }
    }
}
