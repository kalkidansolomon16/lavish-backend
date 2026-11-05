<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\AppointmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Doctor;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's Chat tab: every doctor they have booked with, unlocked or locked.
 * Messages themselves go through the shared /conversations endpoints.
 */
class ChatController extends ClientController
{
    public function __construct(private ChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $user = $request->user();

        $doctorIds = $client->appointments()->whereNotNull('doctor_id')
            ->whereIn('status', [AppointmentStatus::Approved, AppointmentStatus::Completed])
            ->distinct()->pluck('doctor_id');
        $treated = $client->appointments()->where('status', AppointmentStatus::Completed)->distinct()->pluck('doctor_id');
        $conversations = Conversation::query()->where('client_id', $client->id)
            ->with('latestMessage', 'participants')->get()->keyBy('doctor_id');

        $rows = Doctor::query()->with('user')->whereIn('id', $doctorIds->merge($conversations->keys())->unique())->get()
            ->map(function (Doctor $d) use ($treated, $conversations, $user) {
                $c = $conversations->get($d->id);

                return [
                    'doctor' => ['id' => $d->id, 'name' => $d->name, 'specialty' => $d->specialty, 'hue' => $d->hue, 'photo_url' => $d->photoUrl()],
                    'locked' => ! $treated->contains($d->id),
                    'conversation_id' => $c?->id,
                    'last_message' => $c?->latestMessage ? ['body' => $c->latestMessage->body, 'mine' => $c->latestMessage->user_id === $user->id, 'created_at' => $c->latestMessage->created_at->toIso8601String()] : null,
                    'unread' => $c ? $this->chat->unreadCount($c, $user) : 0,
                ];
            })
            ->sortByDesc(fn ($r) => $r['last_message']['created_at'] ?? '')->values();

        return response()->json(['data' => $rows]);
    }

    /** Opens (or creates) the conversation with a doctor. 403 chat_locked before the first treatment. */
    public function open(Request $request, Doctor $doctor): ConversationResource
    {
        $client = $this->client($request);
        if (! $client->treatedBy($doctor->id)) {
            throw BusinessRuleException::make('chat_locked', 'Chat with this doctor unlocks after your first treatment with them.', status: 403);
        }
        $c = $this->chat->clientDoctor($client, $doctor)->load('doctor.user', 'client', 'latestMessage', 'participants');
        $c->unread = $this->chat->unreadCount($c, $request->user());

        return new ConversationResource($c);
    }
}
