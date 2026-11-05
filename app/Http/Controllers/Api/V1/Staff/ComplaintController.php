<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\ComplaintStatus;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Notifications\LavishNotification;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ComplaintController extends StaffController
{
    private const WITH = ['client', 'messages.user', 'attachments'];

    public function __construct(private Notifier $notifier) {}

    /** Filters: status (comma list), priority, category, q. Open + high priority first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Complaint::query()->with(self::WITH)
            ->when($request->string('status')->value(), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->string('priority')->value(), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->string('category')->value(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('subject', 'like', "%{$t}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$t}%"))))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->latest();

        return ComplaintResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(Complaint $complaint): ComplaintResource
    {
        return new ComplaintResource($complaint->load(self::WITH));
    }

    /** Reply to the client (appears in the app). Optionally change status in the same call. */
    public function reply(Request $request, Complaint $complaint): ComplaintResource
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'status' => ['nullable', Rule::in(ComplaintStatus::values())],
        ]);
        $complaint->messages()->create(['user_id' => $request->user()->id, 'from_staff' => true, 'body' => $data['body']]);
        $status = isset($data['status']) ? ComplaintStatus::from($data['status']) : ($complaint->status === ComplaintStatus::Open ? ComplaintStatus::InReview : $complaint->status);
        $this->applyStatus($complaint, $status);

        $this->notifier->client($complaint->client, new LavishNotification('complaint.reply', 'Reply to your complaint',
            "Client care replied to “{$complaint->subject}”.", 'support_agent', ['screen' => 'complaint', 'id' => $complaint->id]));

        return new ComplaintResource($complaint->load(self::WITH));
    }

    public function status(Request $request, Complaint $complaint): ComplaintResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(ComplaintStatus::values())]]);
        $this->applyStatus($complaint, ComplaintStatus::from($data['status']));
        if ($complaint->status === ComplaintStatus::Resolved) {
            $this->notifier->client($complaint->client, new LavishNotification('complaint.resolved', 'Complaint resolved',
                "“{$complaint->subject}” is resolved. Tell us how we did.", 'task_alt', ['screen' => 'complaint', 'id' => $complaint->id]));
        }

        return new ComplaintResource($complaint->load(self::WITH));
    }

    private function applyStatus(Complaint $complaint, ComplaintStatus $status): void
    {
        $complaint->update(['status' => $status, 'resolved_at' => $status === ComplaintStatus::Resolved ? now() : null]);
    }
}
