<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Messages for every chat in Lavish (client ↔ doctor, student ↔ teachers, staff groups and direct messages).
 *
 * Real-time: poll GET /conversations/{id}/messages?after_id=<last id you have> every few seconds
 * while the chat is open (cheap: returns only new messages). Push notifications tell the app
 * when to refresh otherwise.
 */
class ConversationController extends Controller
{
    private const WITH = ['users', 'doctor.user', 'client', 'enrollment.course', 'enrollment.student', 'latestMessage', 'participants'];

    public function __construct(private ChatService $chat) {}

    /** ?type=client_doctor|student_teacher|group|direct */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $list = Conversation::query()->for($user)->with(self::WITH)
            ->when($request->string('type')->value(), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('last_message_at')->orderByDesc('id')->get()
            ->each(fn (Conversation $c) => $this->decorate($c, $request));

        return ConversationResource::collection($list);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeView($request, $conversation);

        return new ConversationResource($this->decorate($conversation->load(self::WITH), $request));
    }

    /**
     * Newest first, 30 per page.
     *   ?before_id=123  older messages (scrolling up)
     *   ?after_id=456   only messages newer than 456 (polling)
     */
    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorizeView($request, $conversation);
        $messages = $conversation->messages()->with('sender', 'attachments')
            ->when($request->integer('before_id'), fn ($q, $id) => $q->where('id', '<', $id))
            ->when($request->integer('after_id'), fn ($q, $id) => $q->where('id', '>', $id))
            ->orderByDesc('id')->limit(min($request->integer('limit', 30), 100))->get();

        return MessageResource::collection($messages);
    }

    /** multipart/form-data for files: body + files[] (images or PDF, 10 MB each, up to 4). */
    public function send(Request $request, Conversation $conversation): MessageResource
    {
        $data = $request->validate([
            'body' => ['required_without:files', 'nullable', 'string', 'max:4000'],
            'files' => ['nullable', 'array', 'max:4'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ]);

        return new MessageResource($this->chat->send($conversation, $request->user(), $data['body'] ?? '', $request->file('files', [])));
    }

    public function read(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeView($request, $conversation);
        $this->chat->markRead($conversation, $request->user());

        return new ConversationResource($this->decorate($conversation->load(self::WITH), $request));
    }

    private function authorizeView(Request $request, Conversation $c): void
    {
        $user = $request->user();
        $groupManager = $c->type->value === 'group' && $user->hasPermission('team.groups');
        abort_unless($groupManager || $c->hasParticipant($user), 404);
    }

    private function decorate(Conversation $c, Request $request): Conversation
    {
        $c->unread = $this->chat->unreadCount($c, $request->user());
        $c->locked = $c->type->value === 'client_doctor' && $request->user()->isClient() && ! $c->client->treatedBy($c->doctor_id);

        return $c;
    }
}
