<?php

namespace App\Providers;

use App\Models;
use App\Services\Payments\ChapaGateway;
use App\Services\Push\FcmPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Push\PushSender;
use App\Services\Sms\AfroMessageSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, fn () => match (config('lavish.sms.driver')) {
            'afromessage' => new AfroMessageSmsSender(config('lavish.sms.afromessage')),
            default => new LogSmsSender,
        });

        $this->app->singleton(PushSender::class, fn () => match (config('lavish.push.driver')) {
            'fcm' => new FcmPushSender((string) config('lavish.push.fcm_credentials')),
            default => new LogPushSender,
        });

        $this->app->singleton(ChapaGateway::class, fn () => new ChapaGateway(config('lavish.chapa')));
    }

    public function boot(): void
    {
        // Short, stable type names in polymorphic columns (payments.payable_type = "appointment" | "order").
        Relation::enforceMorphMap([
            'user' => Models\User::class,
            'appointment' => Models\Appointment::class,
            'order' => Models\Order::class,
            'enrollment' => Models\Enrollment::class,
            'complaint' => Models\Complaint::class,
            'message' => Models\Message::class,
            'exercise_submission' => Models\ExerciseSubmission::class,
            'gift_card' => Models\GiftCard::class,
            'payment_receipt' => Models\PaymentReceipt::class,
            'prescription' => Models\Prescription::class,
        ]);

        // Log N+1 queries while developing (storage/logs/laravel.log) so they can be fixed with eager loading.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(fn ($model, $relation) => logger()->debug('Lazy loaded '.get_class($model).'::'.$relation));

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        // Login / password attempts: per IP and per account.
        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by('auth-ip:'.$r->ip()),
            Limit::perMinute(5)->by('auth-login:'.strtolower((string) $r->input('login', $r->input('phone')))),
        ]);
        RateLimiter::for('otp', fn (Request $r) => Limit::perHour(10)->by('otp:'.$r->ip()));
        // Gift card codes: stop guessing — per account and per IP.
        RateLimiter::for('giftcode', fn (Request $r) => [
            Limit::perMinute(10)->by('giftcode-user:'.($r->user()?->id ?? 'guest')),
            Limit::perHour(60)->by('giftcode-ip:'.$r->ip()),
        ]);
    }
}
