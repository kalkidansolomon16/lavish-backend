<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\ComplaintStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ClassSessionResource;
use App\Http\Resources\ProductResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ClassSession;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Doctor;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\ChatService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /staff/dashboard[?portal=teacher]
 * The cards and panels match the web prototype's dashboard for each portal.
 * A person with several portals (doctor + teacher) picks one with ?portal=.
 */
class DashboardController extends StaffController
{
    public function __construct(private ChatService $chat) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('roles', 'doctor');
        $roles = $user->roles->sortBy('level');
        $portal = $request->string('portal')->value();
        $portal = $roles->firstWhere('key', $portal)?->key ?? $roles->first()?->key ?? 'nurse';
        $doctor = $user->doctor;
        $today = CarbonImmutable::today();

        $data = [
            'portal' => $portal,
            'stats' => $this->stats($portal, $user, $doctor, $today),
        ];

        $mine = fn ($q) => $portal === 'doctor' && $doctor ? $q->where('doctor_id', $doctor->id) : $q;
        if (in_array($portal, ['ceo', 'reception', 'doctor', 'nurse'], true)) {
            $data['today_schedule'] = AppointmentResource::collection($mine(Appointment::query())->with('client', 'doctor.user', 'service', 'branch')
                ->where('status', AppointmentStatus::Approved)->whereBetween('starts_at', [$today, $today->endOfDay()])->orderBy('starts_at')->get());
            $data['upcoming'] = AppointmentResource::collection($mine(Appointment::query())->with('client', 'doctor.user', 'service', 'branch')
                ->holding()->where('starts_at', '>=', now())->orderBy('starts_at')->limit(5)->get());
            $data['treatment_mix'] = $mine(Appointment::query())->join('services', 'services.id', '=', 'appointments.service_id')
                ->where('appointments.starts_at', '>=', $today->subDays(30))
                ->selectRaw('services.name as label, COUNT(*) as value')->groupBy('services.name')->orderByDesc('value')->get()
                ->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value]);
        }
        if ($user->hasPermission('finance.revenue') && in_array($portal, ['ceo', 'reception'], true)) {
            $data['revenue_week'] = $this->revenueWeek($today);
            $data['branch_appointments'] = Branch::ordered()->map(fn ($b) => [
                'label' => $b->name,
                'value' => Appointment::query()->where('branch_id', $b->id)->where('starts_at', '>=', $today->subDays(30))->whereIn('status', ['approved', 'completed'])->count(),
            ]);
        }
        if (in_array($portal, ['ceo', 'gm'], true)) {
            $data['complaint_mix'] = collect(ComplaintStatus::cases())->map(fn ($s) => ['label' => $s->value, 'value' => Complaint::query()->where('status', $s)->count()]);
        }
        if (in_array($portal, ['ceo', 'gm', 'ecommerce'], true)) {
            $data['branch_stock'] = Branch::ordered()->map(fn ($b) => ['label' => $b->name, 'value' => (int) BranchStock::query()->where('branch_id', $b->id)->sum('quantity')]);
            $data['low_stock'] = ProductResource::collection(Product::query()->active()->withStock()->get()->filter(fn ($p) => $p->stockTotal() <= $p->reorder_level)->values());
        }
        if ($portal === 'teacher' && $doctor) {
            $data['classes'] = ClassSessionResource::collection(ClassSession::query()->with('course')->where('teacher_id', $doctor->id)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(8)->get());
        }

        $data['notifications'] = $user->notifications()->limit(5)->get()->map(fn ($n) => [
            'id' => $n->id, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '', 'path' => $n->data['path'] ?? null,
            'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    private function stats(string $portal, User $user, ?Doctor $doctor, CarbonImmutable $today): array
    {
        $stat = fn (string $key, string $label, int $value, bool $highlight = false, string $format = 'number') => compact('key', 'label', 'value', 'highlight', 'format');
        $openOrders = fn () => Order::query()->whereIn('status', OrderStatus::openValues())->count();
        $openComplaints = fn () => Complaint::query()->where('status', '!=', ComplaintStatus::Resolved)->count();
        $newMembers = fn () => \App\Models\ClientLoyalty::query()->where('joined_at', '>=', now()->subDays(7))->count();
        $lowStock = fn () => Product::query()->active()->withStock()->get()->filter(fn ($p) => $p->stockTotal() <= $p->reorder_level)->count();

        return match ($portal) {
            'ceo' => [
                $stat('revenue_week', 'Revenue this week', (int) collect($this->revenueWeek($today))->sum('value'), true, 'money'),
                $stat('pending_appointments', 'Pending appointments', Appointment::query()->where('status', AppointmentStatus::Pending)->count()),
                $stat('orders_in_progress', 'Orders in progress', $openOrders()),
                $stat('open_complaints', 'Open complaints', $openComplaints()),
                $stat('new_members', 'New Rewards members (7 days)', $newMembers()),
            ],
            'gm' => [
                $stat('open_complaints', 'Open complaints', $openComplaints(), true),
                $stat('staff', 'Staff & doctors', User::query()->where('type', 'staff')->where('status', 'active')->count()),
                $stat('reorder', 'Products to reorder', $lowStock()),
                $stat('certificates_to_issue', 'Certificates to issue', Enrollment::query()->where('status', EnrollmentStatus::Completed)->doesntHave('certificate')->count()),
                $stat('new_members', 'New Rewards members (7 days)', $newMembers()),
            ],
            'reception' => [
                $stat('awaiting_approval', 'Awaiting approval', Appointment::query()->where('status', AppointmentStatus::Pending)->count(), true),
                $stat('today', 'Today’s appointments', Appointment::query()->where('status', AppointmentStatus::Approved)->whereBetween('starts_at', [$today, $today->endOfDay()])->count()),
                $stat('unpaid', 'Unpaid invoices', Payment::query()->whereIn('status', [PaymentStatus::Unpaid, PaymentStatus::Failed])->count()),
                $stat('not_on_app', 'Not on the app yet', Client::query()->whereNull('user_id')->count()),
                $stat('new_members', 'New Rewards members (7 days)', $newMembers()),
            ],
            'ecommerce' => [
                $stat('orders_in_progress', 'Orders in progress', $openOrders(), true),
                $stat('unpaid_orders', 'Unpaid orders', Payment::query()->where('payable_type', 'order')->whereIn('status', [PaymentStatus::Unpaid, PaymentStatus::Pending, PaymentStatus::Failed])->count()),
                $stat('low_stock', 'Low stock', Product::query()->active()->withStock()->get()->filter(fn ($p) => $p->stockTotal() > 0 && $p->stockTotal() <= $p->reorder_level)->count()),
                $stat('out_of_stock', 'Out of stock', Product::query()->active()->withStock()->get()->filter(fn ($p) => $p->stockTotal() === 0)->count()),
            ],
            'doctor' => $doctor ? [
                $stat('patients', 'My patients', Client::query()->where(fn ($w) => $w->where('primary_doctor_id', $doctor->id)->orWhereHas('appointments', fn ($a) => $a->where('doctor_id', $doctor->id)))->count(), true),
                $stat('upcoming', 'Upcoming sessions', Appointment::query()->where('doctor_id', $doctor->id)->where('status', AppointmentStatus::Approved)->where('starts_at', '>=', now())->count()),
                $stat('awaiting_reception', 'Awaiting reception', Appointment::query()->where('doctor_id', $doctor->id)->where('status', AppointmentStatus::Pending)->count()),
                $stat('chats', 'Chats unlocked', Client::query()->whereHas('appointments', fn ($a) => $a->where('doctor_id', $doctor->id)->where('status', AppointmentStatus::Completed))->count()),
            ] : [],
            'teacher' => $doctor ? $this->teacherStats($user, $doctor, $stat) : [],
            'nurse' => [
                $stat('today_appointments', 'Appointments today', Appointment::query()->where('status', AppointmentStatus::Approved)->whereBetween('starts_at', [$today, $today->endOfDay()])->count(), true),
                $stat('pending_appointments', 'Waiting for approval', Appointment::query()->where('status', AppointmentStatus::Pending)->count()),
            ],
            default => [],
        };
    }

    private function teacherStats(User $user, Doctor $doctor, callable $stat): array
    {
        $courseIds = $doctor->courses()->pluck('courses.id');
        $enrollments = Enrollment::query()->with('conversation.latestMessage')->whereIn('course_id', $courseIds)->get();
        $unanswered = $enrollments->filter(fn ($e) => $e->conversation?->latestMessage && $e->conversation->latestMessage->user_id === $e->user_id)->count();

        return [
            $stat('students', 'My students', $enrollments->count(), true),
            $stat('courses', 'Courses I teach', $courseIds->count()),
            $stat('classes_week', 'Classes this week', ClassSession::query()->where('teacher_id', $doctor->id)->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()])->count()),
            $stat('unanswered', 'Unanswered messages', $unanswered),
        ];
    }

    /** Paid amounts per day for the last 7 days (Mon … Today). */
    private function revenueWeek(CarbonImmutable $today): array
    {
        $from = $today->subDays(6);
        $rows = Payment::query()->where('status', PaymentStatus::Paid)->where('paid_at', '>=', $from)->get(['amount', 'paid_at'])
            ->groupBy(fn ($p) => $p->paid_at->toDateString());

        return collect(range(0, 6))->map(function ($i) use ($from, $rows, $today) {
            $d = $from->addDays($i);

            return ['label' => $d->isSameDay($today) ? 'Today' : $d->format('D'), 'date' => $d->toDateString(), 'value' => (int) ($rows->get($d->toDateString())?->sum('amount') ?? 0)];
        })->all();
    }
}
