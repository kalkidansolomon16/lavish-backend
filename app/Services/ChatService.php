<?php

namespace App\Services;

use App\Enums\ConversationType;
use App\Exceptions\BusinessRuleException;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Doctor;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\User;
use App\Notifications\LavishNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    public function __construct(private Notifier $notifier) {}

    /** The conversation between a client and a doctor (created on first need). */
    public function clientDoctor(Client $client, Doctor $doctor): Conversation
    {
        $c = Conversation::firstOrCreate(
            ['type' => ConversationType::ClientDoctor, 'client_id' => $client->id, 'doctor_id' => $doctor->id],
        );
        $this->syncParticipants($c, array_filter([$client->user_id, $doctor->user_id]));

        return $c;
    }

    /** One conversation per enrolment: the student + every teacher of the course. */
    public function studentTeacher(Enrollment $enrollment): Conversation
    {
        $c = Conversation::firstOrCreate(['type' => ConversationType::StudentTeacher, 'enrollment_id' => $enrollment->id]);
        $teacherUserIds = $enrollment->course->teachers()->with('user:id')->get()->pluck('user.id')->filter()->all();
        $this->syncParticipants($c, [$enrollment->user_id, ...$teacherUserIds], exclusive: true);

        return $c;
    }

    public function direct(User $a, User $b): Conversation
    {
        if ($a->is($b)) {
            throw BusinessRuleException::make('chat_self', 'You can’t message yourself.');
        }
        $c = Conversation::firstOrCreate(['type' => ConversationType::Direct, 'direct_key' => Conversation::directKey($a->id, $b->id)], ['created_by' => $a->id]);
        $this->syncParticipants($c, [$a->id, $b->id]);

        return $c;
    }

    public function createGroup(User $by, string $name, ?string $about, array $memberIds): Conversation
    {
        return DB::transaction(function () use ($by, $name, $about, $memberIds) {
            $g = Conversation::create(['type' => ConversationType::Group, 'name' => $name, 'about' => $about, 'created_by' => $by->id]);
            $this->syncParticipants($g, array_unique([$by->id, ...$memberIds]), exclusive: true);

            return $g;
        });
    }

    /** Adds missing participants; with $exclusive, also removes anyone not listed. */
    public function syncParticipants(Conversation $c, array $userIds, bool $exclusive = false): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        $existing = $c->participants()->pluck('user_id')->all();
        foreach (array_diff($userIds, $existing) as $id) {
            $c->participants()->create(['user_id' => $id]);
        }
        if ($exclusive) {
            $c->participants()->whereNotIn('user_id', $userIds)->delete();
        }
    }

    /**
     * Can $user post in $c? Clients may only write to a doctor after a completed treatment;
     * doctors may always reply. Group posting: members, or anyone who manages groups.
     */
    public function assertCanPost(Conversation $c, User $user): void
    {
        $member = $c->hasParticipant($user);
        if ($c->type === ConversationType::Group) {
            if (! $member && ! $user->hasPermission('team.groups')) {
                throw BusinessRuleException::make('not_a_member', 'Only members of this group can post here.', status: 403);
            }

            return;
        }
        if (! $member) {
            throw BusinessRuleException::make('not_a_member', 'You are not part of this conversation.', status: 403);
        }
        if ($c->type === ConversationType::ClientDoctor && $user->isClient() && ! $c->client->treatedBy($c->doctor_id)) {
            throw BusinessRuleException::make('chat_locked', 'Chat with this doctor unlocks after your first treatment with them.', status: 403);
        }
    }

    /** @param  list<UploadedFile>  $files */
    public function send(Conversation $c, User $sender, string $body, array $files = []): Message
    {
        $this->assertCanPost($c, $sender);

        $message = DB::transaction(function () use ($c, $sender, $body, $files) {
            $m = $c->messages()->create(['user_id' => $sender->id, 'body' => $body]);
            foreach ($files as $file) {
                Attachment::store($m, $file, $sender->id, 'chat/'.$c->id);
            }
            $c->update(['last_message_at' => $m->created_at]);
            $c->participants()->where('user_id', $sender->id)->update(['last_read_at' => $m->created_at]);

            return $m;
        });

        $this->notifyRecipients($c, $sender, $body);

        return $message->load('sender', 'attachments');
    }

    /** A message "from" a doctor that the system writes, e.g. a restock suggestion. */
    public function systemMessage(Conversation $c, User $as, string $body): Message
    {
        $m = $c->messages()->create(['user_id' => $as->id, 'body' => $body]);
        $c->update(['last_message_at' => $m->created_at]);
        $this->notifyRecipients($c, $as, $body);

        return $m;
    }

    public function markRead(Conversation $c, User $user): void
    {
        $c->participants()->where('user_id', $user->id)->update(['last_read_at' => now()]);
    }

    public function unreadCount(Conversation $c, User $user): int
    {
        $p = $c->participants->firstWhere('user_id', $user->id) ?? $c->participants()->where('user_id', $user->id)->first();

        return $c->messages()
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->when($p?->last_read_at, fn ($q) => $q->where('created_at', '>', $p->last_read_at))
            ->count();
    }

    private function notifyRecipients(Conversation $c, User $sender, string $body): void
    {
        if ($c->type === ConversationType::Group) {
            return; // groups show unread counts instead of pinging everyone
        }
        $preview = Str::limit($body, 90);
        $recipients = $c->users()->whereKeyNot($sender->id)->get();

        foreach ($recipients as $user) {
            [$target, $path] = match (true) {
                $c->type === ConversationType::ClientDoctor && $user->isClient() => [['screen' => 'chat', 'id' => $c->doctor_id], null],
                $c->type === ConversationType::ClientDoctor => [null, '/chat?client='.$c->client_id],
                $c->type === ConversationType::StudentTeacher && $user->isStudent() => [['screen' => 's-chat', 'id' => $c->id], null],
                $c->type === ConversationType::StudentTeacher => [null, '/students?enrollment='.$c->enrollment_id],
                default => [null, '/team?conversation='.$c->id],
            };
            $this->notifier->user($user, new LavishNotification('message.new', $sender->name, $preview, 'chat', $target, $path));
        }
    }
}
