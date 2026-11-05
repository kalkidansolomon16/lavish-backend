<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\ComplaintResource;
use App\Models\Attachment;
use App\Models\Complaint;
use App\Notifications\LavishNotification;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComplaintController extends ClientController
{
    private const WITH = ['messages.user', 'attachments', 'client'];

    public function __construct(private Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ComplaintResource::collection($this->client($request)->complaints()->with(self::WITH)->latest()->get());
    }

    /** multipart/form-data when sending photos (photos[] — up to 4 images, 5 MB each). */
    public function store(Request $request): ComplaintResource
    {
        $client = $this->client($request);
        $data = $request->validate([
            'category' => ['required', Rule::in(ComplaintCategory::values())],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:4000'],
            'priority' => ['nullable', Rule::in(ComplaintPriority::values())],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('client_id', $client->id)],
            'order_id' => ['nullable', Rule::exists('orders', 'id')->where('client_id', $client->id)],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['image', 'max:5120'],
        ]);

        $complaint = DB::transaction(function () use ($client, $data, $request) {
            $c = $client->complaints()->create([
                ...collect($data)->except('photos')->all(),
                'priority' => $data['priority'] ?? ComplaintPriority::Medium->value,
                'status' => ComplaintStatus::Open,
            ]);
            foreach ($request->file('photos', []) as $photo) {
                Attachment::store($c, $photo, $request->user()->id, 'complaints/'.$c->id);
            }

            return $c;
        });

        $this->notifier->staff('complaints.manage', new LavishNotification('complaint.new', 'New complaint',
            "{$client->name}: {$complaint->subject}".($complaint->priority === ComplaintPriority::High ? ' (urgent)' : ''), 'support_agent', null, '/complaints?focus='.$complaint->id));

        return new ComplaintResource($complaint->load(self::WITH));
    }

    public function show(Request $request, Complaint $complaint): ComplaintResource
    {
        $this->own($request, $complaint);

        return new ComplaintResource($complaint->load(self::WITH));
    }

    /** The client adds to the conversation with client care. */
    public function message(Request $request, Complaint $complaint): ComplaintResource
    {
        $this->own($request, $complaint);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $complaint->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);
        if ($complaint->status === ComplaintStatus::Resolved) {
            $complaint->update(['status' => ComplaintStatus::InReview, 'resolved_at' => null]);
        }
        $this->notifier->staff('complaints.manage', new LavishNotification('complaint.message', 'Complaint reply',
            "{$complaint->client->name} replied on “{$complaint->subject}”.", 'support_agent', null, '/complaints?focus='.$complaint->id));

        return new ComplaintResource($complaint->load(self::WITH));
    }

    public function feedback(Request $request, Complaint $complaint): ComplaintResource
    {
        $this->own($request, $complaint);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        if ($complaint->status !== ComplaintStatus::Resolved) {
            throw BusinessRuleException::make('not_resolved', 'You can rate the help you got once the complaint is resolved.');
        }
        $complaint->update(['feedback_rating' => $data['rating'], 'feedback_comment' => $data['comment'] ?? null]);

        return new ComplaintResource($complaint->load(self::WITH));
    }
}
