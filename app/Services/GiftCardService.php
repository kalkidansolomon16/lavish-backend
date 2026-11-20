<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\GiftCard;
use App\Models\GiftCardLedger;
use App\Models\LoyaltySetting;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Payments\PaymentService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gift cards: prepaid value bought now and spent later, in one go or over several visits.
 *
 * Codes: 16 random characters (XXXX-XXXX-XXXX-XXXX, no look-alike letters). An HMAC of the code is used for
 * lookups (typed or scanned barcode); staff lists show the last 4 characters. For e-gift cards the code is also
 * kept encrypted so the buyer and the recipient can show the barcode in the app.
 *
 * E-gift cards (bought in the app): pending_payment → paid → awaiting_activation. Reception finds the card
 * (barcode / list), checks the details and activates it; the recipient then gets "your gift card is active,
 * use it from … to …" by email and/or SMS, with the code. Cards sold at the desk and printed cards are active
 * as soon as they are paid. At the visit staff scan the card and use it for the treatment (useAtDesk) or take
 * it off the client's bill at checkout; every use is in the card's history.
 *
 * Printed cards: a batch of numbers is generated (or imported from the printing company) as `in_stock`
 * cards with no value. The barcode on the card holds the same number, so a USB scanner at the desk simply
 * types it. A stock card gets its value and becomes active only when it is sold (sellStock).
 *
 * Buying a gift card earns no points — the points come when the card is spent on a treatment.
 */
class GiftCardService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Shortest code accepted (imported printer numbers may be 10+ digits; our own codes are 16). */
    public const MIN_CODE = 10;

    public function __construct(private Notifier $notifier, private Messenger $messenger) {}

    // ------------------------------------------------------------------ codes

    public static function normalize(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) config('app.key'));
    }

    public function findByCode(string $code): ?GiftCard
    {
        $n = self::normalize($code);

        return strlen($n) >= self::MIN_CODE ? GiftCard::query()->where('code_hash', self::hash($n))->first() : null;
    }

    private function newCode(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 16; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (GiftCard::query()->where('code_hash', self::hash($raw))->exists());

        return implode('-', str_split($raw, 4));
    }

    // ------------------------------------------------------------------ issuing

    /**
     * Client buys a card in the app. The card waits for payment (Chapa), then becomes active and is delivered.
     *
     * @return array{0: GiftCard, 1: Payment, 2: string} card, its bill, and the code (shown once to the buyer)
     */
    public function buy(Client $buyer, array $data, PaymentService $payments): array
    {
        [$card, $code] = $this->make($data, ['purchaser_client_id' => $buyer->id, 'purchaser_name' => $buyer->name, 'status' => 'pending_payment', 'source' => 'app']);
        $payment = $payments->createFor($card, $buyer, $card->initial_value, $card->label());

        return [$card, $payment, $code];
    }

    /** Front desk sells a printed or digital card and takes the money at the desk. */
    public function sell(array $data, PaymentMethod $method, User $by, PaymentService $payments): array
    {
        $buyer = ! empty($data['purchaser_client_id']) ? Client::find($data['purchaser_client_id']) : null;

        return DB::transaction(function () use ($data, $method, $by, $payments, $buyer) {
            [$card, $code] = $this->make($data, [
                'purchaser_client_id' => $buyer?->id, 'purchaser_name' => $buyer?->name ?? ($data['purchaser_name'] ?? null),
                'status' => 'pending_payment', 'source' => 'front_desk', 'sold_by' => $by->id,
            ]);
            if ($buyer) {
                $payments->recordManual($payments->createFor($card, $buyer, $card->initial_value, $card->label()), $method, $by);
            } else {
                // Walk-in buyer without a client record: the sale is still kept in the ledger and audit log.
                $this->activate($card->refresh(), $by);
                AuditLog::record($by, 'gift_card.sold', 'gift_card', $card->id, null, null, ['value' => $card->initial_value, 'method' => $method->value]);
            }

            return [$card->refresh(), $code];
        });
    }

    // ------------------------------------------------------------------ printed card stock

    /**
     * Makes $count blank printed cards (no value yet). Returns the batch name and the codes — the codes
     * are given once, to send to the printer (barcode + number on each card).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function generateStock(int $count, ?string $batch, ?string $design, User $by): array
    {
        $batch = $this->batchName($batch);
        $codes = DB::transaction(function () use ($count, $batch, $design, $by) {
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $code = $this->newCode();
                $this->stockCard($code, $batch, $design, $by);
                $codes[] = $code;
            }

            return $codes;
        });
        AuditLog::record($by, 'gift_card.stock_generated', 'gift_card', null, null, null, ['batch' => $batch, 'count' => $count]);

        return [$batch, $codes];
    }

    /**
     * Registers cards that were already printed with their own numbers. Duplicates and numbers that are too
     * short are skipped. @return array{batch: string, added: int, skipped: list<string>}
     */
    public function importStock(array $codes, ?string $batch, ?string $design, User $by): array
    {
        $batch = $this->batchName($batch);
        $added = 0;
        $skipped = [];
        DB::transaction(function () use ($codes, $batch, $design, $by, &$added, &$skipped) {
            foreach (array_unique(array_map(fn ($c) => self::normalize((string) $c), $codes)) as $n) {
                if ($n === '') {
                    continue;
                }
                if (strlen($n) < self::MIN_CODE || strlen($n) > 32 || GiftCard::query()->where('code_hash', self::hash($n))->exists()) {
                    $skipped[] = $n;

                    continue;
                }
                $this->stockCard($n, $batch, $design, $by);
                $added++;
            }
        });
        AuditLog::record($by, 'gift_card.stock_imported', 'gift_card', null, null, null, ['batch' => $batch, 'added' => $added, 'skipped' => count($skipped)]);

        return ['batch' => $batch, 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * Sells a printed card: scan its barcode, set the amount (or treatment), take the money. The card is
     * active straight away — the buyer already holds it, so nothing is sent unless a recipient phone is given.
     */
    public function sellStock(string $code, array $data, PaymentMethod $method, User $by, PaymentService $payments): GiftCard
    {
        $card = $this->findByCode($code);
        if (! $card) {
            throw BusinessRuleException::make('gift_card_not_found', 'No gift card has that number. Check the card, or add its batch under Card stock.');
        }
        if ($card->status !== 'in_stock') {
            throw BusinessRuleException::make('gift_card_already_sold', 'This card has already been sold ('.$card->label().', '.$card->status.').');
        }
        $type = $data['type'] ?? 'monetary';
        $service = $type === 'service' ? Service::findOrFail($data['service_id']) : null;
        $value = $service ? $service->price : (int) ($data['amount'] ?? 0);
        if ($value < 100) {
            throw BusinessRuleException::make('gift_card_amount', 'A gift card must be worth at least ETB 100.');
        }
        $promo = $type === 'promo';
        $buyer = ! $promo && ! empty($data['purchaser_client_id']) ? Client::find($data['purchaser_client_id']) : null;
        $phone = ! empty($data['recipient_phone']) ? (Phone::normalize($data['recipient_phone']) ?? $data['recipient_phone']) : null;

        return DB::transaction(function () use ($card, $data, $type, $service, $value, $buyer, $phone, $method, $by, $payments, $promo) {
            $card->update([
                'type' => $type, 'service_id' => $service?->id, 'initial_value' => $value, 'balance' => $value,
                'status' => 'pending_payment', 'sold_by' => $by->id,
                'purchaser_client_id' => $buyer?->id, 'purchaser_name' => $promo ? 'Lavish Medical Spa' : ($buyer?->name ?? ($data['purchaser_name'] ?? null)),
                'recipient_name' => $data['recipient_name'] ?? null, 'recipient_phone' => $phone, 'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_client_id' => $data['recipient_client_id'] ?? ($phone ? Client::query()->where('phone', $phone)->value('id') : null),
                'message' => $data['message'] ?? null, 'delivery' => 'print', 'expires_at' => $data['expires_at'] ?? null,
            ]);
            if ($buyer && ! $promo) {
                $payments->recordManual($payments->createFor($card, $buyer, $value, $card->label()), $method, $by);
            } else {
                $this->activate($card->refresh(), $by);
            }
            AuditLog::record($by, $promo ? 'gift_card.promo' : 'gift_card.stock_sold', 'gift_card', $card->id, $data['reason'] ?? null, ['status' => 'in_stock'],
                ['value' => $value, 'method' => $promo ? 'promo' : $method->value, 'batch' => $card->batch]);

            return $card->refresh();
        });
    }

    private function stockCard(string $code, string $batch, ?string $design, User $by): GiftCard
    {
        return GiftCard::create([
            'code_hash' => self::hash($code), 'code_last4' => substr(self::normalize($code), -4),
            'code_encrypted' => $code, // printed on the card anyway; kept (encrypted) so staff can show / re-print its barcode
            'type' => 'monetary', 'initial_value' => 0, 'balance' => 0, 'status' => 'in_stock',
            'design' => $design ?: 'classic', 'delivery' => 'print', 'source' => 'card_stock', 'batch' => $batch,
            'physical' => true, 'sold_by' => null, 'purchaser_name' => null,
        ]);
    }

    private function batchName(?string $batch): string
    {
        return trim((string) $batch) !== '' ? Str::limit(trim($batch), 40, '') : 'Batch '.now()->format('Y-m-d H:i');
    }

    /** Free promotional card for a campaign (manager only, shorter expiry). */
    public function issuePromo(array $data, User $by, ?string $reason): array
    {
        [$card, $code] = $this->make([...$data, 'type' => 'promo'], ['status' => 'pending_payment', 'source' => 'promo', 'sold_by' => $by->id, 'purchaser_name' => 'Lavish Medical Spa']);
        $this->activate($card, $by);
        AuditLog::record($by, 'gift_card.promo', 'gift_card', $card->id, $reason, null, ['value' => $card->initial_value, 'recipient' => $card->recipient_name]);

        return [$card->refresh(), $code];
    }

    /**
     * Called when the card's bill is paid (desk, receipt check or Chapa). An e-gift card bought in the app then
     * waits for reception to activate it; every other card goes live straight away.
     */
    public function activate(GiftCard $card, ?User $by = null): GiftCard
    {
        if ($card->status !== 'pending_payment') {
            return $card;
        }
        if ($card->source === 'app') {
            $card->update(['status' => 'awaiting_activation']);
            $this->notifier->staff('giftcards.activate', new LavishNotification('gift_card.to_activate', 'E-gift card to activate',
                ($card->purchaser_name ?: 'A client').' bought '.$card->label().' for '.($card->recipient_name ?: 'someone').'. Check it and activate it.',
                'card_giftcard', null, '/giftcards?tab=activate'));
            if ($card->purchaser) {
                $this->notifier->client($card->purchaser, new LavishNotification('gift_card.paid', 'Gift card paid',
                    'Thank you! Lavish will activate the card and send it to '.($card->recipient_name ?: 'the recipient').'.', 'card_giftcard', ['screen' => 'gift_card', 'id' => $card->id]));
            }

            return $card->refresh();
        }

        return $this->goLive($card, $by);
    }

    /**
     * Reception activates a card. An e-gift card that isn't paid yet can be paid and activated in one step
     * by giving the payment method (money received at the desk or the receipt checked).
     */
    public function activateByStaff(GiftCard $card, User $by, ?PaymentMethod $method, PaymentService $payments): GiftCard
    {
        return DB::transaction(function () use ($card, $by, $method, $payments) {
            $card = GiftCard::query()->whereKey($card->id)->lockForUpdate()->first();
            if ($card->status === 'pending_payment') {
                if (! $method) {
                    throw BusinessRuleException::make('gift_card_unpaid', 'This card isn’t paid yet. Choose how it was paid to record the payment and activate it.');
                }
                $bill = $card->payment ?? ($card->purchaser ? $payments->createFor($card, $card->purchaser, $card->initial_value, $card->label()) : null);
                if ($bill) {
                    $payments->recordManual($bill, $method, $by); // → activate() → awaiting_activation
                } else {
                    $card->update(['status' => 'awaiting_activation']);
                }
                $card->refresh();
            }
            if ($card->status !== 'awaiting_activation') {
                throw BusinessRuleException::make('gift_card_not_awaiting', 'This card is '.str_replace('_', ' ', $card->status).' — only paid e-gift cards waiting for activation can be activated.');
            }
            $this->goLive($card, $by);
            AuditLog::record($by, 'gift_card.activated', 'gift_card', $card->id, null, ['status' => 'awaiting_activation'], ['status' => 'active']);

            return $card->refresh();
        });
    }

    /** The card becomes usable: valid from today until the expiry, value in the ledger, recipient told. */
    private function goLive(GiftCard $card, ?User $by): GiftCard
    {
        $s = LoyaltySetting::current();
        $months = $card->type === 'promo' ? $s->promo_card_expiry_months : $s->gift_card_expiry_months;
        $card->update([
            'status' => 'active', 'activated_at' => now(), 'activated_by' => $by?->id,
            'expires_at' => $card->expires_at ?? now()->addMonths($months)->endOfDay(),
        ]);
        $this->entry($card, 'issue', $card->initial_value, $by, reason: match ($card->source) {
            'promo' => 'Promotional card', 'app' => 'E-gift card activated', 'card_stock' => 'Printed card sold', default => 'Card purchased',
        });

        if ($card->delivery === 'print') {
            // The buyer holds the card: tell the recipient it is active if we have their phone / email.
            $card->update(['delivered_at' => now()]);
            $this->sendActivated($card->refresh(), $card->code_encrypted);
            if ($card->recipient) {
                $this->notifier->client($card->recipient, new LavishNotification('gift_card.received', 'Your Lavish gift card is active',
                    $card->label().' '.$card->maskedCode().' can be used until '.$card->expires_at?->format('j M Y').'. Show the card at the front desk.', 'card_giftcard', ['screen' => 'gift_card', 'id' => $card->id]));
            }
        } elseif (! $card->deliver_at || $card->deliver_at->isPast()) {
            $this->deliver($card->refresh());
        }

        return $card->refresh();
    }

    /** "Your gift card is activated — use it from … to …" by email and / or SMS (with the code when we have it). */
    /** Staff: send the "your gift card is activated" message again (email and / or SMS). */
    public function resendActivated(GiftCard $card, User $by): int
    {
        if ($card->status !== 'active') {
            throw BusinessRuleException::make('gift_card_not_active', 'Only active cards can be sent to the recipient.');
        }
        if (! $card->recipient_email && ! $card->recipient_phone) {
            throw BusinessRuleException::make('no_recipient_contact', 'This card has no recipient email or phone.');
        }
        $before = $card->outboundMessages()->count();
        $this->sendActivated($card, $card->code_encrypted);
        AuditLog::record($by, 'gift_card.resend', 'gift_card', $card->id);

        return $card->outboundMessages()->count() - $before;
    }

    private function sendActivated(GiftCard $card, ?string $code): void
    {
        $from = $card->purchaser_name ?: 'Someone special';
        $value = $card->type === 'service' ? 'a '.($card->service?->name ?? 'treatment') : 'ETB '.number_format($card->initial_value);
        $period = 'from '.($card->activated_at ?? now())->format('j M Y').' to '.$card->expires_at?->format('j M Y');
        $name = $card->recipient_name ? 'Dear '.$card->recipient_name.', y' : 'Y';
        $codeLine = match (true) {
            $code && $card->physical => " Card number: {$code}. Bring the card with you — we scan its barcode at the front desk.",
            (bool) $code => " Your gift card code: {$code}. Show this code (or its QR code in the Lavish app) at the front desk.",
            default => ' Bring the card with you — we scan its barcode at the front desk.',
        };
        // A surprise: the recipient may not know about the gift until this message arrives.
        $first = $card->recipient_name ? trim(explode(' ', $card->recipient_name)[0]) : null;
        $text = '🎁 Surprise'.($first ? ", {$first}" : '')."! {$from} sent you a Lavish Medical Spa gift card: {$value}".($card->message ? " — “{$card->message}”" : '').
            ". Use it for Lavish treatments {$period}.{$codeLine}";

        if ($card->recipient_email) {
            $subject = "🎁 Surprise! {$from} sent you a gift";
            $html = view('emails.gift-card', [
                'subject' => $subject, 'name' => $first, 'from' => $from, 'message' => $card->message,
                'value' => $card->type === 'service' ? ($card->service?->name ?? 'A Lavish treatment') : 'ETB '.number_format($card->initial_value),
                'period' => $period, 'physical' => (bool) $card->physical,
                'code' => $code, 'barcode' => $code ? \App\Support\Code128::html(self::normalize($code)) : '',
                'appUrl' => config('lavish.app_download_url'),
            ])->render();
            $this->messenger->emailHtml($card->recipient_email, $subject, $html, $text."\n\nLavish Medical Spa", $card);
        }
        if ($card->recipient_phone) {
            $this->messenger->sms($card->recipient_phone, $text, $card, logAs: $code ? str_replace($code, '[gift card code]', $text) : null);
        }
    }

    /**
     * At the visit: use the card for a treatment without a bill in the system (the card covers it). A service
     * card is used up in one go; a money card loses the amount used. Returns the card with its history.
     */
    public function useAtDesk(GiftCard $card, int $amount, ?Service $service, ?string $note, User $by): GiftCard
    {
        return DB::transaction(function () use ($card, $amount, $service, $note, $by) {
            $card = GiftCard::query()->whereKey($card->id)->lockForUpdate()->first();
            $this->assertUsable($card);
            if ($card->type === 'service') {
                if ($service && $service->id !== $card->service_id) {
                    throw BusinessRuleException::make('gift_card_wrong_service', 'This card is for '.($card->service?->name ?? 'another treatment').' only.');
                }
                $amount = $card->balance;
                $service ??= $card->service;
            }
            if ($amount < 1) {
                throw BusinessRuleException::make('amount_required', 'Enter the amount to use.');
            }
            if ($amount > $card->balance) {
                throw BusinessRuleException::make('gift_card_balance', 'Only ETB '.number_format($card->balance).' is left on this card.', ['balance' => $card->balance]);
            }
            $card->balance -= $amount;
            $card->status = $card->balance === 0 ? 'redeemed' : 'active';
            $card->save();
            $what = trim(($service?->name ?? 'Used at Lavish').($note ? " — {$note}" : ''));
            $this->entry($card, 'redeem', -$amount, $by, reason: $what);
            AuditLog::record($by, 'gift_card.used', 'gift_card', $card->id, $what, ['balance' => $card->balance + $amount], ['balance' => $card->balance]);

            if ($card->recipient) {
                $this->notifier->client($card->recipient, new LavishNotification('gift_card.used', 'Gift card used',
                    'ETB '.number_format($amount)." used for {$what}. ".($card->balance > 0 ? 'ETB '.number_format($card->balance).' left on the card.' : 'The card is now fully used.'),
                    'card_giftcard', ['screen' => 'gift_card', 'id' => $card->id]));
            }

            return $card->refresh();
        });
    }

    /** Sends the activated card (with its code) to the recipient by email / SMS and links it to their account. */
    public function deliver(GiftCard $card): void
    {
        if ($card->delivered_at || $card->status !== 'active' || ! $card->code_encrypted) {
            return;
        }
        $code = $card->code_encrypted;
        $from = $card->purchaser_name ?: 'Someone special';
        $value = $card->type === 'service' ? 'a '.($card->service?->name ?? 'treatment') : 'ETB '.number_format($card->initial_value);
        $this->sendActivated($card, $code);

        $recipient = $card->recipient_client_id ? Client::find($card->recipient_client_id)
            : ($card->recipient_phone ? Client::query()->where('phone', $card->recipient_phone)->first() : null);
        $card->update(['delivered_at' => now(), 'recipient_client_id' => $recipient?->id ?? $card->recipient_client_id]);

        if ($recipient) {
            $this->notifier->client($recipient, new LavishNotification('gift_card.received', 'Your gift card is active',
                "{$from} sent you {$value} gift card. Use it until ".$card->expires_at?->format('j M Y').' — it’s in My Gift Cards.', 'card_giftcard', ['screen' => 'gift_card', 'id' => $card->id]));
        }
        if ($card->purchaser && $card->purchaser->id !== $recipient?->id) {
            $this->notifier->client($card->purchaser, new LavishNotification('gift_card.delivered', 'Your gift card was activated',
                "{$card->recipient_name}’s gift card is active and was sent to them.", 'mark_email_read', ['screen' => 'gift_card', 'id' => $card->id]));
        }
    }

    // ------------------------------------------------------------------ recipient

    /** The recipient adds a card to their account by typing the code or scanning the QR. */
    public function claim(Client $client, string $code): GiftCard
    {
        $card = $this->findByCode($code);
        if (! $card || $card->status === 'pending_payment') {
            throw BusinessRuleException::make('gift_card_not_found', 'We couldn’t find a gift card with that code.');
        }
        if ($card->status === 'awaiting_activation') {
            throw BusinessRuleException::make('gift_card_not_activated', 'This gift card is paid and waiting for Lavish to activate it. You’ll get a message when it is active.');
        }
        if ($card->status === 'in_stock') {
            throw BusinessRuleException::make('gift_card_not_activated', 'This card hasn’t been activated yet. It becomes active when it is paid for at the Lavish front desk.');
        }
        if ($card->recipient_client_id && $card->recipient_client_id !== $client->id && $card->claimed_at) {
            throw BusinessRuleException::make('gift_card_claimed', 'This gift card has already been added to another account.');
        }
        $card->update(['recipient_client_id' => $client->id, 'claimed_at' => $card->claimed_at ?? now()]);

        return $card->refresh();
    }

    // ------------------------------------------------------------------ spending

    /**
     * Takes up to $max ETB off the card for a bill. A service card covers its own treatment in full.
     * Returns the amount taken. Must run inside the checkout transaction.
     */
    public function redeem(GiftCard $card, int $max, Payment $payment, ?User $by = null): int
    {
        $card = GiftCard::query()->whereKey($card->id)->lockForUpdate()->first();
        $this->assertUsable($card);
        $payment->loadMissing('payable');
        $appointment = $payment->payable_type === 'appointment' ? $payment->payable : null;

        if ($card->type === 'service') {
            if (! $appointment || $appointment->service_id !== $card->service_id) {
                throw BusinessRuleException::make('gift_card_wrong_service', 'This card is for '.($card->service?->name ?? 'another treatment').' only.');
            }
            $take = $max;                 // covers that treatment in full …
            $debit = $card->balance;      // … and closes the card
        } else {
            $take = min($card->balance, $max);
            $debit = $take;
        }
        if ($take <= 0) {
            return 0;
        }

        $card->balance -= $debit;
        $card->status = $card->balance === 0 ? 'redeemed' : 'active';
        $card->save();
        $this->entry($card, 'redeem', -$debit, $by, $payment, reason: $payment->description);

        $low = (int) ceil($card->initial_value * LoyaltySetting::current()->gift_card_low_balance_percent / 100);
        if ($card->balance > 0 && $card->balance <= $low && $card->recipient) {
            $this->notifier->client($card->recipient, new LavishNotification('gift_card.balance_low', 'Gift card balance is low',
                'ETB '.number_format($card->balance).' left on your Lavish gift card '.$card->maskedCode().'.', 'card_giftcard', ['screen' => 'gift_card', 'id' => $card->id]));
        }

        return $take;
    }

    /** Puts money back on a card (bill cancelled or refunded). Re-opens a closed card. */
    public function restore(GiftCard $card, int $amount, Payment $payment, string $why, ?User $by = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $card = GiftCard::query()->whereKey($card->id)->lockForUpdate()->first();
        $card->balance = min($card->initial_value, $card->balance + $amount);
        if ($card->status === 'redeemed') {
            $card->status = 'active';
        }
        $card->save();
        $this->entry($card, 'restore', $amount, $by, $payment, reason: $why);
    }

    public function assertUsable(GiftCard $card): void
    {
        if ($card->status === 'in_stock') {
            throw BusinessRuleException::make('gift_card_not_activated', 'This printed card hasn’t been sold yet — sell it first under Gift cards → Sell a printed card.');
        }
        if (in_array($card->status, ['pending_payment', 'awaiting_activation'], true)) {
            throw BusinessRuleException::make('gift_card_not_activated', $card->status === 'pending_payment'
                ? 'This gift card isn’t paid yet.' : 'This e-gift card is paid but not activated yet — activate it first.');
        }
        if ($card->status === 'void') {
            throw BusinessRuleException::make('gift_card_void', 'This gift card was cancelled.');
        }
        if ($card->expires_at && $card->expires_at->isPast()) {
            throw BusinessRuleException::make('gift_card_expired', 'This gift card has expired.');
        }
        if (! $card->usable()) {
            throw BusinessRuleException::make('gift_card_empty', 'This gift card has no balance left.');
        }
    }

    // ------------------------------------------------------------------ manager tools

    /** A lost card: cancel it and (optionally) move its balance to a new code. Returns [new card, new code] or nulls. */
    public function void(GiftCard $card, string $reason, User $by, bool $reissue = true): array
    {
        if (in_array($card->status, ['void', 'pending_payment'], true)) {
            throw BusinessRuleException::make('gift_card_not_voidable', 'This card can’t be voided.');
        }

        return DB::transaction(function () use ($card, $reason, $by, $reissue) {
            $card = GiftCard::query()->whereKey($card->id)->lockForUpdate()->first();
            $balance = $card->balance;
            $card->update(['status' => 'void', 'balance' => 0, 'code_encrypted' => null]);
            $this->entry($card, 'void', -$balance, $by, reason: $reason);

            $new = null;
            $code = null;
            if ($reissue && $balance > 0) {
                $code = $this->newCode();
                $new = GiftCard::create([
                    ...$card->only(['type', 'service_id', 'expires_at', 'purchaser_client_id', 'purchaser_name', 'recipient_name', 'recipient_phone',
                        'recipient_email', 'recipient_client_id', 'message', 'design', 'claimed_at']),
                    'code_hash' => self::hash($code), 'code_last4' => substr(self::normalize($code), -4),
                    'initial_value' => $balance, 'balance' => $balance, 'status' => 'active', 'delivery' => 'print',
                    'delivered_at' => now(), 'source' => 'front_desk', 'replaces_id' => $card->id, 'sold_by' => $by->id,
                ]);
                $this->entry($new, 'reissue', $balance, $by, reason: "Reissued from {$card->maskedCode()}: {$reason}");
            }
            AuditLog::record($by, 'gift_card.void', 'gift_card', $card->id, $reason, ['balance' => $balance, 'status' => 'active'],
                ['status' => 'void', 'reissued_as' => $new?->id]);

            return [$new, $code];
        });
    }

    /** Scheduled: deliver cards whose delivery date has come; expire cards past their date. */
    public function deliverDue(): int
    {
        $due = GiftCard::query()->where('status', 'active')->whereNull('delivered_at')->where('delivery', '!=', 'print')
            ->where(fn ($q) => $q->whereNull('deliver_at')->orWhere('deliver_at', '<=', now()))->with('service', 'purchaser')->get();
        $due->each(fn ($c) => $this->deliver($c));

        return $due->count();
    }

    public function expireDue(): int
    {
        $cards = GiftCard::query()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now())->get();
        foreach ($cards as $card) {
            DB::transaction(function () use ($card) {
                $left = $card->balance;
                $card->update(['status' => 'expired', 'balance' => 0]);
                $this->entry($card, 'expire', -$left, null, reason: 'Card expired');
            });
        }

        return $cards->count();
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: GiftCard, 1: string} */
    private function make(array $data, array $extra): array
    {
        $type = $data['type'] ?? 'monetary';
        $service = $type === 'service' ? Service::findOrFail($data['service_id']) : null;
        $value = $service ? $service->price : (int) $data['amount'];
        if ($value < 100) {
            throw BusinessRuleException::make('gift_card_amount', 'A gift card must be worth at least ETB 100.');
        }
        $code = $this->newCode();
        $phone = ! empty($data['recipient_phone']) ? (Phone::normalize($data['recipient_phone']) ?? $data['recipient_phone']) : null;

        $card = GiftCard::create([
            'code_hash' => self::hash($code), 'code_last4' => substr(self::normalize($code), -4), 'code_encrypted' => $code,
            'type' => $type, 'service_id' => $service?->id, 'initial_value' => $value, 'balance' => $value,
            'recipient_name' => $data['recipient_name'] ?? null, 'recipient_phone' => $phone, 'recipient_email' => $data['recipient_email'] ?? null,
            'recipient_client_id' => $data['recipient_client_id'] ?? ($phone ? Client::query()->where('phone', $phone)->value('id') : null),
            'message' => $data['message'] ?? null, 'design' => $data['design'] ?? 'classic',
            'delivery' => $data['delivery'] ?? ($phone ? 'sms' : (! empty($data['recipient_email']) ? 'email' : 'print')),
            'deliver_at' => $data['deliver_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            ...$extra,
        ]);

        return [$card->load('service'), $code];
    }

    private function entry(GiftCard $card, string $type, int $amount, ?User $by, ?Payment $payment = null, ?string $reason = null): GiftCardLedger
    {
        $payable = $payment?->payable_type;

        return GiftCardLedger::create([
            'gift_card_id' => $card->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $card->balance,
            'payment_id' => $payment?->id,
            'appointment_id' => $payable === 'appointment' ? $payment->payable_id : null,
            'order_id' => $payable === 'order' ? $payment->payable_id : null,
            'created_by' => $by?->id, 'reason' => $reason,
        ]);
    }
}
