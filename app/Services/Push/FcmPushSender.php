<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * Setup (PUSH_DRIVER=fcm): create a Firebase project, add the Android/iOS apps, then in
 * Project settings → Service accounts → "Generate new private key" download the JSON file and put
 * its path in FIREBASE_CREDENTIALS (e.g. storage/app/firebase.json — never commit it).
 *
 * Each message carries the notification (shown by the phone even when the app is closed) and
 * `data` {type, screen, id} so tapping it opens the right screen. Tokens Firebase reports as no
 * longer valid (app uninstalled / reinstalled) are deleted.
 */
class FcmPushSender implements PushSender
{
    public function __construct(private string $credentialsPath) {}

    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()->get();
        if ($tokens->isEmpty()) {
            return;
        }
        $cred = $this->credentials();
        $access = $this->accessToken($cred);
        $url = "https://fcm.googleapis.com/v1/projects/{$cred['project_id']}/messages:send";

        foreach ($tokens as $device) {
            $res = Http::withToken($access)->timeout(10)->post($url, ['message' => [
                'token' => $device->token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => (object) array_map('strval', $data), // FCM wants a JSON object, even when empty
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'lavish_updates', 'sound' => 'default']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);
            if ($res->successful()) {
                $device->forceFill(['last_used_at' => now()])->save();

                continue;
            }
            $status = $res->json('error.details.0.errorCode') ?? $res->json('error.status');
            if (in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true) || $res->status() === 404) {
                $device->delete(); // the app was removed from that phone
            } else {
                Log::warning("FCM push failed ({$res->status()}): ".$res->body());
            }
        }
    }

    /** @return array{project_id: string, client_email: string, private_key: string} */
    private function credentials(): array
    {
        $path = str_starts_with($this->credentialsPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $this->credentialsPath)
            ? $this->credentialsPath : base_path($this->credentialsPath);
        if (! is_file($path)) {
            throw new RuntimeException("Firebase credentials not found at {$path} (FIREBASE_CREDENTIALS).");
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('Firebase credentials file is not a service-account key.');
        }

        return $json;
    }

    /** OAuth access token for FCM, cached for 50 minutes (Google issues them for 60). */
    private function accessToken(array $cred): string
    {
        return Cache::remember('fcm_access_token_'.md5($cred['client_email']), now()->addMinutes(50), function () use ($cred) {
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $signature = '';
            if (! openssl_sign("{$head}.{$claims}", $signature, $cred['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the Firebase token request (check the private key).');
            }
            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$head}.{$claims}.".$b64($signature),
            ]);
            if (! $res->successful()) {
                throw new RuntimeException('Firebase sign-in failed: '.$res->body());
            }

            return (string) $res->json('access_token');
        });
    }
}
