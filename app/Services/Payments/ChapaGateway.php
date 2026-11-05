<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * Chapa (https://developer.chapa.co) — Telebirr, CBE Birr and cards.
 *
 * Flow:
 *  1. initialize() → Chapa returns a checkout_url; the app opens it (WebView / browser).
 *  2. The client pays. Chapa calls our callback (GET) and webhook (POST), and redirects the
 *     browser to return_url.
 *  3. We never trust those calls on their own: verify() asks Chapa for the transaction status.
 *
 * mode=fake (default outside production): checkout_url is a signed local page that marks the payment paid.
 */
class ChapaGateway
{
    public function __construct(private array $config) {}

    public function isFake(): bool
    {
        return $this->config['mode'] !== 'live';
    }

    /** @return string checkout URL */
    public function initialize(Payment $payment): string
    {
        if ($this->isFake()) {
            if (app()->isProduction()) {
                throw BusinessRuleException::make('payments_unavailable', 'Online payment is not configured.', status: 503);
            }

            return URL::temporarySignedRoute('payments.fake-checkout', now()->addHour(), ['txRef' => $payment->tx_ref]);
        }

        $client = $payment->client;
        [$first, $last] = array_pad(explode(' ', trim($client->name), 2), 2, '');

        $response = Http::withToken($this->config['secret_key'])->acceptJson()->timeout(20)
            ->post($this->url('/transaction/initialize'), array_filter([
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'email' => $client->email,
                'first_name' => $first,
                'last_name' => $last ?: $first,
                'phone_number' => Phone::local($client->phone),
                'tx_ref' => $payment->tx_ref,
                'callback_url' => route('webhooks.chapa'),
                'return_url' => $this->config['return_url'].'?tx_ref='.$payment->tx_ref,
                'customization' => ['title' => 'Lavish', 'description' => mb_substr($payment->description, 0, 50)],
            ]));

        $url = $response->json('data.checkout_url');
        if (! $response->successful() || ! $url) {
            report(new \RuntimeException('Chapa initialize failed: '.$response->body()));
            throw BusinessRuleException::make('payment_init_failed', 'We could not start the payment. Please try again.', status: 502);
        }

        return $url;
    }

    /**
     * @return array{paid: bool, amount: ?float, currency: ?string, reference: ?string, method: ?string}
     */
    public function verify(Payment $payment): array
    {
        if ($this->isFake()) {
            return ['paid' => (bool) ($payment->meta['fake_paid'] ?? false), 'amount' => (float) $payment->amount, 'currency' => $payment->currency, 'reference' => 'FAKE-'.$payment->tx_ref, 'method' => null];
        }

        $response = Http::withToken($this->config['secret_key'])->acceptJson()->timeout(20)
            ->get($this->url('/transaction/verify/'.urlencode($payment->tx_ref)));

        $data = $response->json('data') ?? [];

        return [
            'paid' => $response->successful() && ($data['status'] ?? null) === 'success',
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'reference' => $data['reference'] ?? null,
            'method' => $data['method'] ?? $data['payment_method'] ?? null,
        ];
    }

    /** Optional webhook signature check (defence in depth — verify() is what we rely on). */
    public function validSignature(string $rawBody, ?string $signature, ?string $chapaSignature): bool
    {
        $secret = $this->config['webhook_secret'];
        if (! $secret) {
            return true;
        }

        return ($signature && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature))
            || ($chapaSignature && hash_equals(hash_hmac('sha256', $secret, $secret), $chapaSignature));
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/').$path;
    }
}
