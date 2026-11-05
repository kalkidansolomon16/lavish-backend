<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Sign-in for everybody.
 *  - Web portal (staff):     POST /auth/login with app=web
 *  - Mobile app (client):    POST /auth/register → POST /auth/register/verify (SMS code) → token
 *                            or POST /auth/login with app=mobile
 *  - Mobile app (student):   POST /auth/login with app=mobile using the username/temporary password
 *                            from the academy, then POST /auth/password/change
 * Send the token as "Authorization: Bearer <token>" on every other request.
 */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],   // phone in any format, or email (staff)
            'password' => ['required', 'string'],
            'app' => ['required', Rule::in(['web', 'mobile'])],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = str_contains($data['login'], '@')
            ? User::query()->where('email', strtolower($data['login']))->first()
            : User::query()->where('phone', Phone::normalize($data['login']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw BusinessRuleException::make('invalid_credentials', 'Phone number or password is incorrect.', status: 401);
        }

        $allowed = $data['app'] === 'web' ? [UserType::Staff] : [UserType::Client, UserType::Student];
        if (! in_array($user->type, $allowed, true)) {
            throw BusinessRuleException::make('wrong_app', $data['app'] === 'web'
                ? 'This account is for the Lavish mobile app.'
                : 'Staff accounts sign in on the Lavish web portal.', status: 403);
        }
        if ($user->status === UserStatus::PendingVerification) {
            try {
                $this->otp->send($user->phone, OtpPurpose::Register);
            } catch (BusinessRuleException $e) {
                // A code was sent less than a minute ago — the client can still use that one.
                throw_unless($e->errorCode === 'otp_too_soon', $e);
            }
            throw BusinessRuleException::make('phone_not_verified', 'Please verify your phone number with the code we sent by SMS.', ['phone' => $user->phone], 403);
        }
        if (! $user->canSignIn()) {
            throw BusinessRuleException::make('account_inactive', 'Your account is not active. Please contact Lavish.', status: 403);
        }

        return $this->issueToken($user, $data['app'], $data['device_name'] ?? null);
    }

    /** Client sign-up. Sends an SMS code; the account is active after /auth/register/verify. */
    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'gender' => ['nullable', Rule::in(['Female', 'Male'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'preferred_branch_id' => ['nullable', 'exists:branches,id'],
        ], ['phone.regex' => 'Enter a valid phone number, e.g. 0911 22 33 44.']);

        $existing = User::query()->where('phone', $data['phone'])->first();
        if ($existing && ! ($existing->isClient() && $existing->status === UserStatus::PendingVerification)) {
            throw BusinessRuleException::make('phone_taken', 'An account with this phone number already exists. Please log in.');
        }
        if (! empty($data['email']) && User::query()->where('email', strtolower($data['email']))->whereKeyNot($existing?->id)->exists()) {
            throw BusinessRuleException::make('email_taken', 'This email is already used by another account.');
        }

        DB::transaction(function () use ($data, $existing) {
            $user = $existing ?? new User;
            $user->fill([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => isset($data['email']) ? strtolower($data['email']) : null,
                'type' => UserType::Client,
                'status' => UserStatus::PendingVerification,
                'password' => $data['password'],
            ])->save();

            // A walk-in client record with this phone becomes this person's account.
            $client = Client::withTrashed()->where('phone', $data['phone'])->first() ?? new Client(['phone' => $data['phone'], 'source' => 'app']);
            $client->fill([
                'user_id' => $user->id,
                'name' => $client->exists ? $client->name : $data['name'],
                'email' => $client->email ?? ($data['email'] ?? null),
                'gender' => $client->gender ?? ($data['gender'] ?? null),
                'date_of_birth' => $client->date_of_birth ?? ($data['date_of_birth'] ?? null),
                'preferred_branch_id' => $data['preferred_branch_id'] ?? $client->preferred_branch_id,
                'deleted_at' => null,
            ])->save();
        });

        $this->otp->send($data['phone'], OtpPurpose::Register);

        return response()->json(['message' => 'We sent a 6-digit code to your phone.', 'phone' => $data['phone']], 201);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $phone = Phone::normalize($data['phone']);
        $user = User::query()->where('phone', $phone)->where('type', UserType::Client)->first();
        if (! $user) {
            throw BusinessRuleException::make('otp_invalid', 'The code is not correct.');
        }
        if ($user->status === UserStatus::Active) {
            throw BusinessRuleException::make('already_verified', 'This phone is already verified. Please log in.');
        }

        $this->otp->verify($phone, OtpPurpose::Register, $data['code']);
        $user->update(['status' => UserStatus::Active, 'phone_verified_at' => now()]);

        return $this->issueToken($user, 'mobile', $data['device_name'] ?? null, 201);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'purpose' => ['required', Rule::in(OtpPurpose::values())],
        ]);
        $phone = Phone::normalize($data['phone']);
        $user = $phone ? User::query()->where('phone', $phone)->first() : null;
        $purpose = OtpPurpose::from($data['purpose']);

        // Answer the same way whether or not the account exists, so phone numbers can't be probed.
        $eligible = $user && ($purpose === OtpPurpose::Register
            ? $user->status === UserStatus::PendingVerification
            : $user->isClient() && $user->status === UserStatus::Active);
        if ($eligible) {
            $this->otp->send($phone, $purpose);
        }

        return response()->json(['message' => 'If the number is registered, a new code is on its way.']);
    }

    /** Clients reset with an SMS code. Students call the academy; staff ask their manager. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string']]);
        $phone = Phone::normalize($data['phone']);
        $user = $phone ? User::query()->where('phone', $phone)->first() : null;

        if ($user?->isStudent()) {
            return response()->json(['message' => 'Please call Lavish Academy on '.config('lavish.academy_phone').' to get a new password.', 'code' => 'call_academy']);
        }
        if ($user?->isClient() && $user->status === UserStatus::Active) {
            $this->otp->send($phone, OtpPurpose::ResetPassword);
        }

        return response()->json(['message' => 'If the number is registered, we sent a code to reset your password.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $phone = Phone::normalize($data['phone']);
        $user = User::query()->where('phone', $phone)->where('type', UserType::Client)->first();
        if (! $user) {
            throw BusinessRuleException::make('otp_invalid', 'The code is not correct.');
        }
        $this->otp->verify($phone, OtpPurpose::ResetPassword, $data['code']);

        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        $user->tokens()->delete();

        return $this->issueToken($user, 'mobile', $data['device_name'] ?? null);
    }

    /** Also used at first login to replace a temporary password. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ]);
        $user = $request->user();
        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password updated.', 'user' => new UserResource($user->fresh())]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateMe(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['sometimes', Rule::in(['en', 'am'])],
            'gender' => ['sometimes', 'nullable', Rule::in(['Female', 'Male'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'preferred_branch_id' => ['sometimes', 'nullable', 'exists:branches,id'],
        ]);

        $user->update(array_intersect_key($data, array_flip(['name', 'email', 'locale'])));
        if ($user->isClient() && $user->client) {
            $user->client->update(array_intersect_key($data, array_flip(['name', 'email', 'gender', 'date_of_birth', 'preferred_branch_id'])));
        }

        return new UserResource($user->fresh());
    }

    public function uploadAvatar(Request $request): UserResource
    {
        $request->validate(['avatar' => ['required', 'image', 'max:4096']]);
        $user = $request->user();
        $user->update(['avatar_path' => $request->file('avatar')->store('avatars', 'public')]);

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->filled('device_token')) {
            DeviceToken::query()->where('token', $request->input('device_token'))->delete();
        }
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Registers the phone's Firebase token for push notifications (call after login and on token refresh). */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
        ]);
        DeviceToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_used_at' => now(),
        ]);

        return response()->json(['message' => 'Device registered.']);
    }

    private function issueToken(User $user, string $app, ?string $device, int $status = 200): JsonResponse
    {
        $expires = $app === 'web' ? now()->addHours(config('lavish.tokens.web_hours')) : now()->addDays(config('lavish.tokens.mobile_days'));
        $token = $user->createToken($device ?: "{$app}-{$user->type->value}", [$user->type->value], $expires);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expires->toIso8601String(),
            'user' => new UserResource($user),
        ], $status);
    }
}
