<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\GiftCard;
use App\Models\LoyaltyTier;
use App\Models\Payment;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * One checkout can combine a reward voucher, points, a gift card and a normal payment.
 * They are always applied in the same order:
 *
 *   1. tier price (Lavish Elite exclusive price) and the voucher — or points — reduce the price
 *   2. the gift card pays as much of what is left as its balance allows
 *   3. cash / card / Telebirr / CBE Birr pays the rest
 *
 * Points are later earned on parts 2 and 3 only (LoyaltyService::earnableAmount).
 * Everything happens in one database transaction with row locks, so nothing can be spent twice.
 */
class CheckoutService
{
    public function __construct(
        private LoyaltyService $loyalty,
        private GiftCardService $giftCards,
        private PaymentService $payments,
    ) {}

    /**
     * Works out the split without changing anything.
     *
     * @param  array{voucher_id?: int, voucher_code?: string, points?: int, gift_card_id?: int, gift_card_code?: string}  $opts
     */
    public function quote(Payment $payment, array $opts = []): array
    {
        return $this->plan($payment, $opts)['quote'];
    }

    /**
     * Applies the voucher / points / gift card to the bill. If nothing is left to pay the bill is marked paid.
     * Staff pass $method to take the remainder at the desk in the same step; the app leaves the remainder
     * open and pays it through Chapa.
     */
    public function apply(Payment $payment, array $opts, ?User $by = null, ?PaymentMethod $method = null): Payment
    {
        return DB::transaction(function () use ($payment, $opts, $by, $method) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $plan = $this->plan($payment, $opts);
            $q = $plan['quote'];
            $meta = $payment->meta ?? [];

            if ($q['tier_discount'] > 0) {
                $meta['tier_discount'] = $q['tier_discount'];
                $meta['tier'] = $q['tier'];
            }
            if ($v = $plan['voucher']) {
                $v = RewardRedemption::query()->whereKey($v->id)->lockForUpdate()->first();
                if (! $v->usable()) {
                    throw BusinessRuleException::make('voucher_unavailable', 'This voucher was already used or has expired.');
                }
                $v->update(['status' => 'used', 'payment_id' => $payment->id, 'discount_applied' => $q['voucher_discount'], 'used_at' => now(),
                    'used_on_appointment_id' => $payment->payable_type === 'appointment' ? $payment->payable_id : null]);
            }
            if ($q['points_used'] > 0) {
                $this->loyalty->debit($payment->client, $q['points_used'], 'redeem', 'checkout', $payment->id, "Points used on {$payment->description}", $by);
            }
            $taken = 0;
            if ($card = $plan['gift_card']) {
                $taken = $this->giftCards->redeem($card, $q['gift_card_amount'], $payment, $by);
                $meta['gift_cards'] = [...($meta['gift_cards'] ?? []), ['id' => $card->id, 'amount' => $taken]];
            }

            $payment->forceFill([
                'discount_amount' => $payment->discount_amount + $q['tier_discount'] + $q['voucher_discount'] + $q['points_discount'],
                'gift_card_amount' => $payment->gift_card_amount + $taken,
                'points_used' => $payment->points_used + $q['points_used'],
                'meta' => $meta,
            ])->save();

            if ($payment->dueAmount() === 0) {
                return $this->payments->markPaid($payment, $taken > 0 || $payment->gift_card_amount > 0 ? PaymentMethod::GiftCard : PaymentMethod::Voucher, null, null, $by);
            }
            if ($method) {
                return $this->payments->recordManual($payment, $method, $by);
            }

            return $payment->refresh();
        });
    }

    /**
     * Gives back everything applied to a bill that was never paid with money (booking cancelled,
     * or the client removed the credits before paying).
     */
    public function release(Payment $payment, string $why, ?User $by = null): void
    {
        if ($payment->discount_amount === 0 && $payment->gift_card_amount === 0) {
            return;
        }
        DB::transaction(function () use ($payment, $why, $by) {
            RewardRedemption::query()->where('payment_id', $payment->id)->where('status', 'used')->get()
                ->each(fn ($v) => $this->loyalty->releaseVoucher($v));
            if ($payment->points_used > 0) {
                $this->loyalty->award($payment->client, $payment->points_used, 'checkout', $payment->id, "Points returned — {$why}", $by, $why, type: 'adjust', notify: false);
            }
            foreach ($payment->meta['gift_cards'] ?? [] as $g) {
                if ($card = GiftCard::find($g['id'])) {
                    $this->giftCards->restore($card, (int) $g['amount'], $payment, $why, $by);
                }
            }
            $meta = $payment->meta ?? [];
            unset($meta['gift_cards'], $meta['tier_discount'], $meta['tier']);
            $payment->forceFill(['discount_amount' => 0, 'gift_card_amount' => 0, 'points_used' => 0, 'meta' => $meta])->save();
        });
    }

    /** What the client (or staff on their behalf) can use on this bill. */
    public function wallet(Client $client, ?Payment $payment = null): array
    {
        $m = $this->loyalty->membership($client);
        $s = $this->loyalty->settings();
        $service = $payment?->payable_type === 'appointment' ? $payment->payable?->service : null;

        $vouchers = RewardRedemption::query()->where('client_id', $client->id)->where('status', 'issued')->where('expires_at', '>', now())
            ->with('reward.service')->orderBy('expires_at')->get()
            ->map(fn ($v) => [
                'id' => $v->id, 'code' => $v->voucher_code, 'name' => $v->reward->name, 'summary' => $v->reward->summary(),
                'expires_at' => $v->expires_at->toIso8601String(),
                'applies' => $payment ? $v->reward->discountFor($service, $payment->dueAmount()) > 0 : null,
            ])->values();

        $cards = GiftCard::query()->where('recipient_client_id', $client->id)->where('status', 'active')->where('balance', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->with('service')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'masked_code' => $c->maskedCode(), 'label' => $c->label(), 'type' => $c->type, 'balance' => $c->balance,
                'applies' => $payment ? ($c->type !== 'service' || $service?->id === $c->service_id) : null,
            ])->values();

        return [
            'points_balance' => $m->points_balance,
            'point_value_etb' => $s->point_value_etb,
            'points_value_etb' => $s->pointsToEtb(max(0, $m->points_balance)),
            'vouchers' => $vouchers,
            'gift_cards' => $cards,
        ];
    }

    // ------------------------------------------------------------------

    private function plan(Payment $payment, array $opts): array
    {
        if (! $payment->isOpen()) {
            throw BusinessRuleException::make('payment_not_open', 'This bill is already '.$payment->status->value.'.');
        }
        $payment->loadMissing('client', 'payable');
        if ($payment->payable_type === 'gift_card') {
            throw BusinessRuleException::make('gift_card_purchase', 'A gift card can’t be paid with vouchers, points or another gift card.');
        }
        $client = $payment->client;
        $appointment = $payment->payable_type === 'appointment' ? $payment->payable : null;
        $service = $appointment?->service;
        $s = $this->loyalty->settings();
        $remaining = $payment->dueAmount();

        // 1a. Tier price (once per bill, treatments only)
        $tierDiscount = 0;
        $tierName = null;
        if ($appointment && ! isset($payment->meta['tier_discount'])) {
            $tier = LoyaltyTier::find($this->loyalty->membership($client)->tier_id);
            if ($tier && $tier->service_discount_percent > 0) {
                $tierDiscount = min($remaining, intdiv($payment->amount * $tier->service_discount_percent, 100));
                $tierName = $tier->name;
            }
        }
        $remaining -= $tierDiscount;

        // 1b. Voucher
        $voucher = null;
        $voucherDiscount = 0;
        if (! empty($opts['voucher_id']) || ! empty($opts['voucher_code'])) {
            $voucher = RewardRedemption::query()->with('reward.service')->where('client_id', $client->id)
                ->when($opts['voucher_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
                ->when(empty($opts['voucher_id']) ? ($opts['voucher_code'] ?? null) : null, fn ($q, $c) => $q->where('voucher_code', strtoupper(trim($c))))
                ->first();
            if (! $voucher) {
                throw BusinessRuleException::make('voucher_not_found', 'That voucher isn’t in this client’s wallet.');
            }
            if (! $voucher->usable()) {
                throw BusinessRuleException::make('voucher_unavailable', 'This voucher was already used or has expired.');
            }
            $voucherDiscount = $voucher->reward->discountFor($service, $remaining);
            if ($voucherDiscount <= 0) {
                throw BusinessRuleException::make('voucher_not_applicable', $voucher->reward->reward_type === 'product'
                    ? 'Product rewards are handed over at the front desk, not taken off a bill.'
                    : 'This voucher is for '.($voucher->reward->service?->name ?? 'another treatment').'.');
            }
        }
        $remaining -= $voucherDiscount;

        // 1c. Points (staff can apply points directly at checkout)
        $pointsUsed = 0;
        $pointsDiscount = 0;
        if (($want = (int) ($opts['points'] ?? 0)) > 0) {
            $balance = $this->loyalty->membership($client)->points_balance;
            if ($want > $balance) {
                throw BusinessRuleException::make('not_enough_points', "Only {$balance} points available.", ['balance' => $balance]);
            }
            $want = min($want, $s->etbToPoints($remaining));      // never more than the bill
            $pointsDiscount = min($remaining, $s->pointsToEtb($want));
            $pointsUsed = $want;
        }
        $remaining -= $pointsDiscount;

        // 2. Gift card
        $card = null;
        $cardAmount = 0;
        if (! empty($opts['gift_card_id']) || ! empty($opts['gift_card_code'])) {
            $card = ! empty($opts['gift_card_id'])
                ? GiftCard::query()->whereKey($opts['gift_card_id'])->where('recipient_client_id', $client->id)->first()
                : $this->giftCards->findByCode($opts['gift_card_code']);
            if (! $card) {
                throw BusinessRuleException::make('gift_card_not_found', 'We couldn’t find that gift card.');
            }
            $this->giftCards->assertUsable($card);
            if ($card->type === 'service' && $service?->id !== $card->service_id) {
                throw BusinessRuleException::make('gift_card_wrong_service', 'This card is for '.($card->service?->name ?? 'another treatment').' only.');
            }
            $cardAmount = $card->type === 'service' ? $remaining : min($card->balance, $remaining);
        }
        $remaining -= $cardAmount;

        $earnable = max(0, $payment->amount - $payment->discount_amount - $tierDiscount - $voucherDiscount - $pointsDiscount);
        $pointsToEarn = $service ? $this->loyalty->estimate($client, $service, $earnable, $appointment->source?->value === 'app') : 0;

        return [
            'voucher' => $voucher,
            'gift_card' => $card,
            'quote' => [
                'price' => $payment->amount,
                'already_applied' => ['discount' => $payment->discount_amount, 'gift_card' => $payment->gift_card_amount, 'points_used' => $payment->points_used],
                'tier' => $tierName,
                'tier_discount' => $tierDiscount,
                'voucher' => $voucher ? ['id' => $voucher->id, 'code' => $voucher->voucher_code, 'name' => $voucher->reward->name] : null,
                'voucher_discount' => $voucherDiscount,
                'points_used' => $pointsUsed,
                'points_discount' => $pointsDiscount,
                'gift_card' => $card ? ['id' => $card->id, 'masked_code' => $card->maskedCode(), 'balance' => $card->balance, 'type' => $card->type] : null,
                'gift_card_amount' => $cardAmount,
                'gift_card_balance_after' => $card ? ($card->type === 'service' ? 0 : $card->balance - $cardAmount) : null,
                'to_pay' => max(0, $remaining),
                'points_to_earn' => $pointsToEarn,
            ],
        ];
    }
}
