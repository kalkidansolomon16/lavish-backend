<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\ConversationType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\ConversationResource;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lavish Team — internal staff communication: people directory, announcements, groups, direct messages.
 * Group and direct messages use the shared /conversations endpoints.
 */
class TeamController extends StaffController
{
    public function __construct(private ChatService $chat) {}

    /** Everyone with an active staff account (for groups and direct messages). */
    public function people(): JsonResponse
    {
        $people = User::query()->where('type', UserType::Staff)->where('status', UserStatus::Active)
            ->with('roles', 'staffProfile', 'doctor')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canSignIn())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roles->sortBy('level')->first()?->key,
                'title' => $u->doctor?->specialty ?? $u->staffProfile?->position,
                'avatar_url' => $u->avatarUrl(),
            ])->values();

        return response()->json(['data' => $people]);
    }

    public function announcements(Request $request): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(
            Announcement::query()->visibleTo($request->user())->with('author', 'group', 'readers:id')->latest()->paginate($this->perPage($request, 30))
        );
    }

    /** Body: title, body, group_id (omit for all staff). */
    public function announce(Request $request): AnnouncementResource
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'group_id' => ['nullable', Rule::exists('conversations', 'id')->where('type', ConversationType::Group->value)],
        ]);
        $me = $request->user();
        $a = Announcement::create(['title' => $data['title'], 'body' => $data['body'], 'conversation_id' => $data['group_id'] ?? null, 'author_id' => $me->id]);
        $a->readers()->attach($me->id, ['read_at' => now()]);

        $audience = $a->conversation_id
            ? $a->group->users()->whereKeyNot($me->id)->get()
            : User::query()->where('type', UserType::Staff)->where('status', UserStatus::Active)->whereKeyNot($me->id)->get();
        Notification::send($audience, new LavishNotification('team.announcement', $a->title, Str::limit($a->body, 100), 'campaign', null, '/team?announcement='.$a->id));

        return new AnnouncementResource($a->load('author', 'group', 'readers:id'));
    }

    public function readAnnouncement(Request $request, Announcement $announcement): AnnouncementResource
    {
        abort_unless(Announcement::query()->visibleTo($request->user())->whereKey($announcement->id)->exists(), 404);
        $announcement->readers()->syncWithoutDetaching([$request->user()->id => ['read_at' => now()]]);

        return new AnnouncementResource($announcement->load('author', 'group', 'readers:id'));
    }

    public function deleteAnnouncement(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(['message' => 'Announcement removed.']);
    }

    /** All groups (managers see every group; others only the ones they're in). */
    public function groups(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $groups = Conversation::query()->where('type', ConversationType::Group)
            ->when(! $user->hasPermission('team.groups'), fn ($q) => $q->for($user))
            ->with('users', 'latestMessage', 'participants')->orderBy('name')->get()
            ->each(fn ($g) => $g->unread = $g->hasParticipant($user) ? $this->chat->unreadCount($g, $user) : 0);

        return ConversationResource::collection($groups);
    }

    /** Body: name, about, member_ids[]. The creator is always a member. */
    public function createGroup(Request $request): ConversationResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'about' => ['nullable', 'string', 'max:255'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('type', UserType::Staff->value)],
        ]);
        $g = $this->chat->createGroup($request->user(), $data['name'], $data['about'] ?? null, $data['member_ids']);

        return new ConversationResource($g->load('users', 'latestMessage'));
    }

    public function updateGroup(Request $request, Conversation $group): ConversationResource
    {
        abort_unless($group->type === ConversationType::Group, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'about' => ['sometimes', 'nullable', 'string', 'max:255'],
            'member_ids' => ['sometimes', 'array', 'min:1'],
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('type', UserType::Staff->value)],
        ]);
        $group->update(collect($data)->only('name', 'about')->all());
        if (isset($data['member_ids'])) {
            $this->chat->syncParticipants($group, $data['member_ids'], exclusive: true);
        }

        return new ConversationResource($group->load('users', 'latestMessage'));
    }

    public function deleteGroup(Conversation $group): JsonResponse
    {
        abort_unless($group->type === ConversationType::Group, 404);
        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    /** Opens (or creates) a direct conversation with another staff member. */
    public function direct(Request $request, User $user): ConversationResource
    {
        abort_unless($user->isStaff() && $user->status === UserStatus::Active, 404);

        return new ConversationResource($this->chat->direct($request->user(), $user)->load('users', 'latestMessage'));
    }
}
