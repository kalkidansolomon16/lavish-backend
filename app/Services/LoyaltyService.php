<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLoyalty;
use App\Models\EarnRule;
use App\Models\LoyaltyCampaign;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\PointsLedger;
use App\Models\PointsRequest;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Service;
use App\Models\User;
use App\Notifications\LavishNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lavish Rewards.
 *
 * Earning: points are credited by the server only when a visit is BOTH completed (doctor) and paid
 * (whichever happens second triggers it), so nobody can earn and then cancel. Shop orders earn when
 * delivered and paid. Points are earned on the money + gift card part of a bill, never on the part
 * covered by a voucher, tier price or points.
 *
 * Balances: every change is a points_ledger row. Positive rows are lots that are spent oldest-first,
 * so expiry is exact. The cached balance on client_loyalty may go negative after a refund of points
 * that were already spent (the spec's chosen behaviour).
 *
 * Tier = points earned in the last 12 months — spending points never lowers it.
 *
 * Membership is by enrolment: signing up in the app does NOT join the program. Reception / GM / CEO
 * (rewards.enroll) enrol the clients who want it, and only members earn points. Members use points by
 * sending a request from the app (PointsRequestService); points asked for are held until it is handled.
 */
class LoyaltyService
{
    public function __construct(private Notifier $notifier) {}

    public function settings(): LoyaltySetting
    {
        return LoyaltySetting::current();
    }

    // ------------------------------------------------------------------ membership

    /** The client's loyalty row, created on first use. Having a row is not membership — joined_at is. */
    public function membership(Client $client): ClientLoyalty
    {
        $m = ClientLoyalty::query()->find($client->id);
        if ($m) {
            return $m;
        }

        return ClientLoyalty::create([
            'client_id' => $client->id,
            'tier_id' => LoyaltyTier::forPoints(0)?->id,
            'referral_code' => $this->newReferralCode($client),
        ])->refresh();
    }

    public function isMember(Client $client): bool
    {
        return ClientLoyalty::query()->whereKey($client->id)->whereNotNull('joined_at')->exists();
    }

    /**
     * Enrols a client in Lavish Rewards (done by staff with rewards.enroll). Gives the one-time welcome points
     * if the "welcome" earning rule is on, and tells the client in the app.
     */
    public function join(Client $client, ?User $by = null, ?string $note = null): ClientLoyalty
    {
        $m = $this->membership($client);
        if ($m->joined_at) {
            return $m;
        }
        $m->update(['joined_at' => now(), 'enrolled_by' => $by?->id, 'enrol_note' => $note, 'requested_at' => null, 'declined_reason' => null, 'declined_at' => null]);
        $rule = EarnRule::general('signup');
        $welcome = $rule && $rule->points > 0 ? $rule->points : 0;
        if ($welcome) {
            $this->award($client, $welcome, 'signup', $client->id, 'Welcome to Lavish Rewards', $by, notify: false);
        }
        if ($by) {
            AuditLog::record($by, 'rewards.enrol', 'client', $client->id, $note, null, ['welcome_points' => $welcome]);
        }
        $this->notifier->client($client, new LavishNotification('rewards.enrolled', 'Welcome to Lavish Rewards',
            'You now collect points on your Lavish treatments and shop orders'.($welcome ? " — we added {$welcome} welcome points" : '').
            '. See your points and use them in the Rewards tab.', 'stars', ['screen' => 'rewards']));

        return $m->refresh();
    }

    /** The client asks to join from the app; reception enrols them (or declines with a reason). */
    public function requestMembership(Client $client, ?string $note = null): ClientLoyalty
    {
        $m = $this->membership($client);
        if ($m->joined_at) {
            throw BusinessRuleException::make('already_member', 'You are already a Lavish Rewards member.');
        }
        if ($m->requested_at) {
            return $m;
        }
        $m->update(['requested_at' => now(), 'request_note' => $note, 'declined_reason' => null, 'declined_at' => null]);
        $this->notifier->staff('rewards.enroll', new LavishNotification('rewards.join_requested', 'Wants to join Lavish Rewards',
            "{$client->name} asked to join Lavish Rewards from the app".($note ? ": “{$note}”" : '.'), 'stars', null, '/rewards?tab=clients'));

        return $m->refresh();
    }

    /** Reception declines a membership request; the client sees the reason and may ask again later. */
    public function declineMembership(Client $client, User $by, string $reason): ClientLoyalty
    {
        $m = $this->membership($client);
        if ($m->joined_at || ! $m->requested_at) {
            throw BusinessRuleException::make('no_request', 'There is no open request to join for this client.');
        }
        $m->update(['requested_at' => null, 'declined_reason' => $reason, 'declined_at' => now()]);
        AuditLog::record($by, 'rewards.request_declined', 'client', $client->id, $reason);
        $this->notifier->client($client, new LavishNotification('rewards.join_declined', 'Lavish Rewards request',
            "We couldn’t enrol you in Lavish Rewards right now: {$reason}", 'info', ['screen' => 'rewards']));

        return $m->refresh();
    }

    /**
     * The client taps "I want to become a Lavish Rewards member" in the app: enrolled straight away (no approval);
     * reception / managers are told and see them in the members list.
     */
    public function joinFromApp(Client $client): ClientLoyalty
    {
        if ($this->isMember($client)) {
            throw BusinessRuleException::make('already_member', 'You are already a Lavish Rewards member.');
        }
        $m = $this->join($client, null, 'Joined in the app');
        $this->notifier->staff('rewards.enroll', new LavishNotification('rewards.joined_app', 'New Lavish Rewards member',
            "{$client->name} joined Lavish Rewards in the app. Member no. ".self::memberNumber($client->id).'.', 'stars', null, '/rewards?tab=clients'));

        return $m;
    }

    /** The number on the member's barcode card (scanned at reception to find the member). */
    public static function memberNumber(int $clientId): string
    {
        return 'LVM'.str_pad((string) $clientId, 7, '0', STR_PAD_LEFT);
    }

    /** "LVM0000012" → 12, or null. */
    public static function clientIdFromMemberNumber(string $s): ?int
    {
        return preg_match('/^\s*LVM0*(\d+)\s*$/i', $s, $m) ? (int) $m[1] : null;
    }

    /** Points held by requests that are still open (not yet taken, but not available either). */
    public function heldPoints(Client $client): int
    {
        return (int) PointsRequest::query()->where('client_id', $client->id)->whereIn('status', PointsRequest::OPEN)->sum('points');
    }

    /** Balance minus held points — what a new request may ask for. */
    public function availablePoints(Client $client): int
    {
        return max(0, $this->membership($client)->points_balance - $this->heldPoints($client));
    }

    // ------------------------------------------------------------------ earning

    /**
     * Credits a visit once it is completed AND paid. Safe to call any number of times
     * (from completion and from payment) — it only ever credits once.
     */
    public function creditVisit(Appointment $appt): ?PointsLedger
    {
        $appt->loadMissing('payment', 'service', 'client');
        $payment = $appt->payment;
        if ($appt->status !== AppointmentStatus::Completed || ! $payment || $payment->status !== PaymentStatus::Paid) {
            return null;
        }
        if ($this->alreadyCredited('appointment', $appt->id)) {
            return null;
        }

        $client = $appt->client;
        if (! $this->isMember($client)) {
            $this->completeReferral($client, $appt);

            return null;
        }
        $m = $this->membership($client);
        $points = $this->visitPoints($client, $appt->service, $this->earnableAmount($payment), $appt->source === BookingSource::App, $m->tier_id);
        $row = $points > 0
            ? $this->award($client, $points, 'appointment', $appt->id, "{$appt->service->name} visit")
            : null;

        $this->completeReferral($client, $appt);

        return $row;
    }

    /** Skincare/product orders earn when they are delivered and paid. */
    public function creditOrder(Order $order): ?PointsLedger
    {
        $order->loadMissing('payment', 'client');
        if ($order->status !== OrderStatus::Delivered || $order->payment?->status !== PaymentStatus::Paid) {
            return null;
        }
        if ($this->alreadyCredited('order', $order->id) || ! $this->isMember($order->client)) {
            return null;
        }
        $rule = EarnRule::general('product_spend');
        if (! $rule || ! $rule->per_etb) {
            return null;
        }
        $m = $this->membership($order->client);
        $earnable = max(0, $this->earnableAmount($order->payment) - (int) $order->delivery_fee);
        $base = intdiv($earnable, $rule->per_etb) * $rule->points;
        $points = $this->capDaily($order->client, (int) floor($base * $this->multiplier(null, $m->tier_id)));

        return $points > 0 ? $this->award($order->client, $points, 'order', $order->id, "Shop order #{$order->id}") : null;
    }

    /** A one-time bonus after reviewing a completed visit. */
    public function creditReview(Appointment $appt): ?PointsLedger
    {
        $rule = EarnRule::general('review');
        if (! $rule || $rule->points <= 0 || $this->alreadyCredited('review', $appt->id) || ! $this->isMember($appt->client)) {
            return null;
        }

        return $this->award($appt->client, $rule->points, 'review', $appt->id, 'Thanks for your review');
    }

    /** Points a visit would earn — shown on the booking summary before the client books. */
    public function estimate(Client $client, Service $service, ?int $amount = null, bool $viaApp = true): int
    {
        if (! $this->isMember($client)) {
            return 0;
        }
        $m = $this->membership($client);

        return $this->visitPoints($client, $service, $amount ?? $service->price, $viaApp, $m->tier_id, capDaily: false);
    }

    private function visitPoints(Client $client, Service $service, int $earnable, bool $viaApp, ?int $tierId, bool $capDaily = true): int
    {
        $rules = EarnRule::query()->live()->get();
        $spend = $rules->first(fn ($r) => $r->type === 'spend' && ! $r->service_id);
        $visit = $rules->first(fn ($r) => $r->type === 'visit' && ! $r->service_id);
        $bonus = $rules->filter(fn ($r) => $r->type === 'visit' && $r->service_id === $service->id)->sum('points');
        $app = $viaApp ? (int) $rules->first(fn ($r) => $r->type === 'app_booking' && ! $r->service_id)?->points : 0;

        $spendPoints = $spend && $spend->per_etb ? intdiv($earnable, $spend->per_etb) * $spend->points : 0;
        if ($spend?->cap_per_visit) {
            $spendPoints = min($spendPoints, $spend->cap_per_visit);
        }
        $base = $spendPoints + (int) $visit?->points + (int) $bonus;
        $points = (int) floor($base * $this->multiplier($service->id, $tierId)) + $app;
        $points = min($points, $this->settings()->max_points_per_visit);

        return $capDaily ? $this->capDaily($client, $points) : $points;
    }

    /** Tier multiplier × the best running campaign that covers this service and tier. */
    public function multiplier(?int $serviceId, ?int $tierId): float
    {
        $tier = $tierId ? LoyaltyTier::find($tierId) : null;
        $campaign = LoyaltyCampaign::query()->running()->get()
            ->filter(fn ($c) => $c->covers($serviceId, $tierId))->max('multiplier') ?? 1;

        return ($tier?->earn_multiplier ?? 1) * max(1, (float) $campaign);
    }

    /** Money + gift card part of a bill — never the voucher / tier price / points part. */
    public function earnableAmount($payment): int
    {
        return max(0, (int) $payment->amount - (int) $payment->discount_amount);
    }

    private function capDaily(Client $client, int $points): int
    {
        $today = PointsLedger::query()->where('client_id', $client->id)->where('type', 'earn')
            ->whereIn('source_type', ['appointment', 'order'])->where('created_at', '>=', now()->startOfDay())->sum('points');

        return max(0, min($points, $this->settings()->max_points_per_day - (int) $today));
    }

    private function alreadyCredited(string $sourceType, int $id): bool
    {
        return PointsLedger::query()->where('type', 'earn')->where('source_type', $sourceType)->where('source_id', $id)->exists();
    }

    // ------------------------------------------------------------------ referrals

    /** The new client was referred by someone (code entered at sign-up or within the first days). */
    public function applyReferralCode(Client $client, string $code): Referral
    {
        $code = Str::upper(trim($code));
        $owner = ClientLoyalty::query()->where('referral_code', $code)->first();
        if (! $owner || $owner->client_id === $client->id) {
            throw BusinessRuleException::make('referral_invalid', 'That referral code isn’t valid.');
        }
        if (Referral::query()->where('referred_client_id', $client->id)->exists()) {
            throw BusinessRuleException::make('referral_used', 'You have already used a referral code.');
        }
        if ($client->appointments()->where('status', AppointmentStatus::Completed)->exists()) {
            throw BusinessRuleException::make('referral_too_late', 'Referral codes are for new clients before their first visit.');
        }

        return Referral::create(['referrer_id' => $owner->client_id, 'referred_client_id' => $client->id, 'code' => $code]);
    }

    private function completeReferral(Client $client, Appointment $appt): void
    {
        $ref = Referral::query()->where('referred_client_id', $client->id)->where('status', 'pending')->first();
        if (! $ref) {
            return;
        }
        $ref->update(['status' => 'completed', 'appointment_id' => $appt->id, 'completed_at' => now()]);

        $rule = EarnRule::general('referral');
        $thisYear = Referral::query()->where('referrer_id', $ref->referrer_id)->where('status', 'rewarded')
            ->where('completed_at', '>=', now()->startOfYear())->count();
        if (! $rule || $rule->points <= 0 || $thisYear >= $this->settings()->max_referrals_per_year || ! $this->isMember($ref->referrer)) {
            return;
        }
        $this->award($ref->referrer, $rule->points, 'referral', $ref->id, "{$client->name} completed their first visit");
        $ref->update(['status' => 'rewarded']);
    }

    // ------------------------------------------------------------------ the ledger

    /** Adds points (a new lot that expires after the configured months). */
    public function award(Client $client, int $points, string $sourceType, ?int $sourceId, string $description, ?User $by = null, ?string $reason = null, string $type = 'earn', bool $notify = true): PointsLedger
    {
        $s = $this->settings();

        [$row, $before, $m] = DB::transaction(function () use ($client, $points, $sourceType, $sourceId, $description, $by, $reason, $type, $s) {
            $m = ClientLoyalty::query()->whereKey($this->membership($client)->client_id)->lockForUpdate()->first();
            $before = $m->points_balance;
            // Pay back any negative balance first (a refund of points that were already spent).
            $remaining = $points + min(0, $before);
            $row = PointsLedger::create([
                'client_id' => $client->id, 'type' => $type, 'points' => $points, 'remaining' => max(0, $remaining),
                'source_type' => $sourceType, 'source_id' => $sourceId, 'description' => $description,
                'expires_at' => now()->addMonths($s->points_expiry_months), 'created_by' => $by?->id, 'reason' => $reason,
            ]);
            $m->points_balance += $points;
            if ($type === 'earn') {
                $m->lifetime_points += $points;
            }
            $m->save();
            $row->update(['balance_after' => $m->points_balance]);

            return [$row, $before, $m];
        });

        $this->refreshTier($m);
        if ($notify && $type === 'earn') {
            $this->notifyEarned($client, $points, $description, $before, $m->refresh()->points_balance);
        }

        return $row;
    }

    /**
     * Takes points away: spends the oldest lots first. Only reversals may push the balance below zero.
     */
    public function debit(Client $client, int $points, string $type, string $sourceType, ?int $sourceId, string $description, ?User $by = null, ?string $reason = null, ?array $preferLotIds = null): PointsLedger
    {
        return DB::transaction(function () use ($client, $points, $type, $sourceType, $sourceId, $description, $by, $reason, $preferLotIds) {
            $m = ClientLoyalty::query()->whereKey($this->membership($client)->client_id)->lockForUpdate()->first();
            if ($type !== 'reverse' && $m->points_balance < $points) {
                throw BusinessRuleException::make('not_enough_points', "Only {$m->points_balance} points available.", ['balance' => $m->points_balance]);
            }

            $left = $points;
            $lots = PointsLedger::query()->where('client_id', $client->id)->where('remaining', '>', 0)
                ->orderByRaw($preferLotIds ? 'CASE WHEN id IN ('.implode(',', array_map('intval', $preferLotIds)).') THEN 0 ELSE 1 END' : '1')
                ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($lots as $lot) {
                if ($left <= 0) {
                    break;
                }
                $take = min($lot->remaining, $left);
                $lot->decrement('remaining', $take);
                $left -= $take;
            }

            $m->points_balance -= $points;
            $m->save();

            return PointsLedger::create([
                'client_id' => $client->id, 'type' => $type, 'points' => -$points, 'remaining' => 0, 'balance_after' => $m->points_balance,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'description' => $description,
                'created_by' => $by?->id, 'reason' => $reason,
            ]);
        });
    }

    /** A refund removes the points that source earned — even if they were spent (balance may go negative). */
    public function reverseFor(string $sourceType, int $sourceId, string $why, ?User $by = null): ?PointsLedger
    {
        $earned = PointsLedger::query()->where('type', 'earn')->where('source_type', $sourceType)->where('source_id', $sourceId)->get();
        $reversed = (int) -PointsLedger::query()->where('type', 'reverse')->where('source_type', $sourceType)->where('source_id', $sourceId)->sum('points');
        $points = (int) $earned->sum('points') - $reversed;
        if ($points <= 0) {
            return null;
        }
        $client = $earned->first()->client;
        $row = $this->debit($client, $points, 'reverse', $sourceType, $sourceId, "Points removed — {$why}", $by, $why, $earned->pluck('id')->all());
        $this->refreshTier($this->membership($client));

        return $row;
    }

    /** Manager adds or removes points by hand (always with a reason, always audited). */
    public function adjust(Client $client, int $points, string $reason, User $by): PointsLedger
    {
        if ($points === 0) {
            throw BusinessRuleException::make('zero_adjustment', 'Enter a number of points to add or remove.');
        }
        if (! $this->isMember($client)) {
            throw BusinessRuleException::make('not_member', "{$client->name} isn’t enrolled in Lavish Rewards yet. Enrol them first.");
        }
        $before = $this->membership($client)->points_balance;
        $row = $points > 0
            ? $this->award($client, $points, 'manual', null, 'Added by Lavish', $by, $reason, type: 'adjust', notify: false)
            : $this->debit($client, -$points, 'adjust', 'manual', null, 'Removed by Lavish', $by, $reason);
        $after = $this->membership($client)->points_balance;
        AuditLog::record($by, 'points.adjust', 'client', $client->id, $reason, ['points_balance' => $before], ['points_balance' => $after, 'change' => $points]);
        $this->notifier->client($client, new LavishNotification('rewards.adjusted', 'Your points were updated',
            ($points > 0 ? "We added {$points} points" : 'We removed '.(-$points).' points')." — {$reason}.", 'stars', ['screen' => 'rewards']));

        return $row;
    }

    // ------------------------------------------------------------------ tiers

    /** Recomputes points earned in the last 12 months and moves the client to the matching tier. */
    public function refreshTier(ClientLoyalty $m): ClientLoyalty
    {
        $since = now()->subMonths(12);
        $earned = (int) PointsLedger::query()->where('client_id', $m->client_id)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->where('type', 'earn')->orWhere(fn ($q) => $q->where('type', 'adjust')->where('points', '>', 0)))->sum('points');
        $reversed = (int) -PointsLedger::query()->where('client_id', $m->client_id)->where('created_at', '>=', $since)->where('type', 'reverse')->sum('points');
        $points12m = max(0, $earned - $reversed);

        $old = $m->tier_id ? LoyaltyTier::find($m->tier_id) : null;
        $new = LoyaltyTier::forPoints($points12m);
        $m->points_12m = $points12m;
        if ($new && $new->id !== $m->tier_id) {
            $up = ! $old || $new->min_points_12m > $old->min_points_12m;
            $m->tier_id = $new->id;
            $m->tier_changed_at = now();
            if ($up && $old) {
                $m->tier_up_seen = false;
                $this->notifier->client($m->client, new LavishNotification('rewards.tier_up', "You’re now {$new->name}!",
                    "Welcome to {$new->name}: you earn {$new->earn_multiplier}× points".($new->benefits ? ' and unlock '.Str::lower(implode(', ', array_slice($new->benefits, 0, 2))) : '').'.',
                    'workspace_premium', ['screen' => 'rewards']));
            }
        }
        $m->save();

        return $m;
    }

    // ------------------------------------------------------------------ rewards & vouchers

    /** Spend points on a reward → a voucher in the client's wallet. */
    public function redeem(Client $client, Reward $reward, ?User $by = null): RewardRedemption
    {
        $m = $this->membership($client)->load('tier');
        if (! $reward->active || ($reward->available_until && $reward->available_until->isPast())) {
            throw BusinessRuleException::make('reward_unavailable', 'This reward is no longer available.');
        }
        if ($reward->available_from && $reward->available_from->isFuture()) {
            throw BusinessRuleException::make('reward_unavailable', 'This reward is available from '.$reward->available_from->format('j M').'.');
        }
        if ($reward->tier_required_id) {
            $required = LoyaltyTier::find($reward->tier_required_id);
            if (($m->tier?->min_points_12m ?? 0) < $required->min_points_12m) {
                throw BusinessRuleException::make('tier_required', "This reward is for {$required->name} members.");
            }
        }
        if ($reward->stock !== null && $reward->stock <= 0) {
            throw BusinessRuleException::make('reward_out_of_stock', 'This reward is out of stock right now.');
        }

        return DB::transaction(function () use ($client, $reward, $by) {
            $this->debit($client, $reward->points_cost, 'redeem', 'reward', $reward->id, "Redeemed: {$reward->name}", $by);
            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }
            $voucher = RewardRedemption::create([
                'client_id' => $client->id, 'reward_id' => $reward->id, 'points_spent' => $reward->points_cost,
                'voucher_code' => $this->newVoucherCode(), 'status' => 'issued',
                'expires_at' => now()->addDays($this->settings()->voucher_valid_days)->endOfDay(),
            ]);
            $this->notifier->client($client, new LavishNotification('rewards.voucher_ready', 'Your reward is ready',
                "{$reward->name} is in your wallet ({$voucher->voucher_code}). Use it at your next booking or checkout.", 'redeem', ['screen' => 'vouchers']));

            return $voucher->load('reward');
        });
    }

    /** Puts a voucher back (its bill was cancelled before the visit). */
    public function releaseVoucher(RewardRedemption $v): void
    {
        $v->update(['status' => $v->expires_at->isFuture() ? 'issued' : 'expired', 'payment_id' => null, 'used_on_appointment_id' => null, 'discount_applied' => null, 'used_at' => null]);
    }

    // ------------------------------------------------------------------ scheduled jobs

    /** Nightly: expire old points and vouchers, then refresh 12-month points and tiers. */
    public function nightly(): array
    {
        $expired = 0;
        $elite = LoyaltyTier::query()->where('points_never_expire', true)->pluck('id');
        PointsLedger::query()->where('remaining', '>', 0)->where('expires_at', '<', now())
            ->whereHas('client.loyalty', fn ($q) => $q->whereNotIn('tier_id', $elite)->orWhereNull('tier_id'))
            ->with('client')->get()->groupBy('client_id')
            ->each(function ($lots) use (&$expired) {
                $points = (int) $lots->sum('remaining');
                $this->debit($lots->first()->client, $points, 'expire', 'expiry', null, 'Points expired', preferLotIds: $lots->pluck('id')->all());
                $expired += $points;
            });

        $vouchers = RewardRedemption::query()->where('status', 'issued')->where('expires_at', '<', now())->update(['status' => 'expired']);

        ClientLoyalty::query()->with('client')->get()->each(fn ($m) => $this->refreshTier($m));

        return ['points_expired' => $expired, 'vouchers_expired' => $vouchers];
    }

    /**
     * Every few minutes: when a campaign with "notify members" starts, send its notice to every member
     * it covers (all members, or only the tiers it targets). Each campaign is announced once.
     */
    public function announceCampaigns(): array
    {
        $sent = 0;
        LoyaltyCampaign::query()->running()->where('notify_members', true)->whereNull('notified_at')->get()
            ->each(function (LoyaltyCampaign $c) use (&$sent) {
                $c->update(['notified_at' => now()]); // claim first so an overlapping run can't send twice
                $title = $c->notice_title ?: $c->name;
                $times = rtrim(rtrim(number_format($c->multiplier, 2), '0'), '.');
                $body = $c->notice_body ?: ($c->description ?: "{$times}× points until ".$c->ends_at->format('j M').'.');
                $count = 0;
                ClientLoyalty::query()->whereNotNull('joined_at')
                    ->when(! empty($c->tier_ids), fn ($q) => $q->whereIn('tier_id', $c->tier_ids))
                    ->with('client.user')
                    ->chunkById(200, function ($members) use ($title, $body, $c, &$count) {
                        foreach ($members as $m) {
                            if ($m->client?->user) {
                                $this->notifier->client($m->client, new LavishNotification('rewards.campaign', $title, $body, 'celebration', ['screen' => 'rewards', 'campaign_id' => $c->id]));
                                $count++;
                            }
                        }
                    }, 'client_id');
                $c->update(['notified_count' => $count]);
                $sent += $count;
            });

        return ['campaign_notices' => $sent];
    }

    /** Daily: warn about points expiring soon and give birthday rewards. */
    public function daily(): array
    {
        $s = $this->settings();
        $reminded = 0;
        PointsLedger::query()->where('remaining', '>', 0)->whereNull('reminded_at')
            ->whereBetween('expires_at', [now(), now()->addDays($s->expiry_reminder_days)])
            ->with('client')->get()->groupBy('client_id')
            ->each(function ($lots) use (&$reminded) {
                $points = (int) $lots->sum('remaining');
                $when = $lots->min('expires_at');
                $this->notifier->client($lots->first()->client, new LavishNotification('rewards.points_expiring', 'Points expiring soon',
                    "{$points} points expire on ".Carbon::parse($when)->format('j M').'. Use them before then: Rewards → Use my points.', 'hourglass_bottom', ['screen' => 'rewards']));
                PointsLedger::query()->whereKey($lots->pluck('id'))->update(['reminded_at' => now()]);
                $reminded++;
            });

        $birthdays = 0;
        $rule = EarnRule::general('birthday');
        if ($rule && $rule->points > 0) {
            Client::query()->whereNotNull('date_of_birth')->whereMonth('date_of_birth', now()->month)
                ->whereHas('loyalty', fn ($q) => $q->whereNotNull('joined_at')->where(fn ($q) => $q->whereNull('birthday_year')->orWhere('birthday_year', '<', now()->year)))
                ->get()->each(function (Client $c) use ($rule, &$birthdays) {
                    $this->award($c, $rule->points, 'birthday', now()->year, 'Happy birthday from Lavish', notify: false);
                    $this->membership($c)->update(['birthday_year' => now()->year]);
                    $this->notifier->client($c, new LavishNotification('rewards.birthday', 'Happy birthday, '.Str::before($c->name, ' ').'!',
                        "We added {$rule->points} birthday points to your Lavish Rewards.", 'cake', ['screen' => 'rewards']));
                    $birthdays++;
                });
        }

        return ['reminded' => $reminded, 'birthdays' => $birthdays];
    }

    // ------------------------------------------------------------------ app views

    /** Everything the Rewards dashboard needs in one call. */
    public function dashboard(Client $client): array
    {
        $m = $this->membership($client)->load('tier');
        $tiers = LoyaltyTier::ladder();
        $next = $tiers->first(fn ($t) => $t->min_points_12m > $m->points_12m);
        $rewards = Reward::query()->available()->orderBy('points_cost')->get();
        $nextReward = $rewards->first(fn ($r) => $r->points_cost > $m->points_balance);
        $expiring = PointsLedger::query()->where('client_id', $client->id)->where('remaining', '>', 0)
            ->where('expires_at', '<=', now()->addDays(30))->orderBy('expires_at')->get();

        $held = $this->heldPoints($client);

        return [
            'member' => (bool) $m->joined_at,
            'member_number' => $m->joined_at ? self::memberNumber($client->id) : null,   // shown as a barcode in the app
            'joined_at' => $m->joined_at?->toIso8601String(),
            'requested_at' => $m->requested_at?->toIso8601String(),   // asked to join in the app, waiting for reception
            'declined_reason' => $m->declined_reason,
            'points_balance' => $m->points_balance,
            'held_points' => $held,                                  // asked for in open requests
            'available_points' => max(0, $m->points_balance - $held),
            'point_value_etb' => (float) $this->settings()->point_value_etb,
            'open_requests' => PointsRequest::query()->where('client_id', $client->id)->whereIn('status', PointsRequest::OPEN)->count(),
            'points_value_etb' => $this->settings()->pointsToEtb(max(0, $m->points_balance)),
            'points_12m' => $m->points_12m,
            'lifetime_points' => $m->lifetime_points,
            'tier' => $m->tier ? self::tierArray($m->tier) : null,
            'next_tier' => $next ? [...self::tierArray($next), 'points_needed' => $next->min_points_12m - $m->points_12m,
                'progress' => $next->min_points_12m > 0 ? round(min(1, $m->points_12m / $next->min_points_12m), 3) : 1] : null,
            'next_reward' => $nextReward ? ['id' => $nextReward->id, 'name' => $nextReward->name, 'points_cost' => $nextReward->points_cost,
                'points_needed' => $nextReward->points_cost - max(0, $m->points_balance), 'progress' => round(max(0, $m->points_balance) / $nextReward->points_cost, 3)] : null,
            'expiring_soon' => $expiring->isEmpty() ? null : ['points' => (int) $expiring->sum('remaining'), 'on' => $expiring->first()->expires_at->toDateString()],
            'vouchers_available' => RewardRedemption::query()->where('client_id', $client->id)->where('status', 'issued')->where('expires_at', '>', now())->count(),
            'referral_code' => $m->referral_code,
            'show_tier_up' => ! $m->tier_up_seen,
            'tiers' => $tiers->map(fn ($t) => self::tierArray($t))->values(),
        ];
    }

    public static function tierArray(LoyaltyTier $t): array
    {
        return [
            'id' => $t->id, 'key' => $t->key, 'name' => $t->name, 'min_points_12m' => $t->min_points_12m,
            'earn_multiplier' => $t->earn_multiplier, 'service_discount_percent' => $t->service_discount_percent,
            'points_never_expire' => $t->points_never_expire, 'benefits' => $t->benefits ?? [],
        ];
    }

    /** "Ways to earn" in plain words, from the live rules and running campaigns. */
    public function waysToEarn(): array
    {
        $rules = EarnRule::query()->live()->with('service')->orderBy('id')->get();
        $words = fn (EarnRule $r) => $r->label ?: match ($r->type) {
            'spend' => "{$r->points} points per ETB ".number_format($r->per_etb).' you spend on treatments',
            'product_spend' => "{$r->points} points per ETB ".number_format($r->per_etb).' you spend in the shop',
            'visit' => $r->service ? "Bonus {$r->points} points on {$r->service->name}" : "{$r->points} points for every completed visit",
            'referral' => "{$r->points} points when a friend you refer completes their first visit",
            'review' => "{$r->points} points for reviewing a visit",
            'birthday' => "{$r->points} points in your birthday month",
            'signup' => "{$r->points} welcome points when you are enrolled",
            'app_booking' => "{$r->points} extra points when you book in the app",
            default => $r->type,
        };

        return [
            'rules' => $rules->map(fn ($r) => ['type' => $r->type, 'points' => $r->points, 'per_etb' => $r->per_etb, 'service' => $r->service?->name, 'text' => $words($r)])->values(),
            'campaigns' => LoyaltyCampaign::query()->where('active', true)->where('ends_at', '>=', now())->orderBy('starts_at')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'multiplier' => $c->multiplier,
                    'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(), 'running' => $c->starts_at->isPast()])->values(),
            'not_earning' => ['Buying a gift card (you earn when the card is spent on a treatment)', 'Tips and taxes', 'The part of a bill paid with a voucher or points'],
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function notifyEarned(Client $client, int $points, string $for, int $before, int $after): void
    {
        $this->notifier->client($client, new LavishNotification('rewards.points_earned', "You earned {$points} points",
            "{$for}. Your balance is now ".number_format($after).' points.', 'stars', ['screen' => 'rewards']));
    }

    private function newReferralCode(Client $client): string
    {
        $stem = Str::upper(Str::limit(preg_replace('/[^A-Za-z]/', '', Str::before($client->name, ' ')), 6, '')) ?: 'LAVISH';
        do {
            $code = $stem.'-'.Str::upper(Str::random(4));
        } while (ClientLoyalty::query()->where('referral_code', $code)->exists());

        return $code;
    }

    private function newVoucherCode(): string
    {
        do {
            $code = 'LV-'.Str::upper(Str::random(6));
        } while (RewardRedemption::query()->where('voucher_code', $code)->exists());

        return $code;
    }
}
