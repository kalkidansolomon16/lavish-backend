<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderPaymentMethod;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\DoctorResource;
use App\Http\Resources\NewsResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ServiceResource;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Doctor;
use App\Models\News;
use App\Models\Product;
use App\Models\Service;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalogue — no login needed. Used by the app's Home, Doctors, Shop and Courses tabs
 * and by the web portal's lookups.
 */
class CatalogController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    /** App-wide constants the client needs (fees, slots, payment options…). */
    public function config(): JsonResponse
    {
        $online = Service::query()->where('is_online', true)->first();

        return response()->json(['data' => [
            'currency' => config('lavish.currency'),
            'delivery_fee' => config('lavish.delivery_fee'),
            'academy_phone' => config('lavish.academy_phone'),
            'booking_slots' => config('lavish.booking.slots'),
            'booking_horizon_days' => config('lavish.booking.horizon_days'),
            'online_consultation' => $online ? new ServiceResource($online) : null,
            'payment_methods' => collect(PaymentMethod::online())->map(fn ($m) => ['value' => $m, 'label' => PaymentMethod::from($m)->label()]),
            // false → hide Chapa; clients pay by any means and upload the receipt (POST /client/payments/{id}/receipt)
            'online_payments_enabled' => config('lavish.payments.online_enabled'),
            'payment_instructions' => config('lavish.payments.instructions'),
            'receipt_methods' => collect(PaymentMethod::desk())->map(fn ($m) => ['value' => $m, 'label' => PaymentMethod::from($m)->label()]),
            'order_payment_methods' => collect(OrderPaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'product_categories' => Product::query()->active()->distinct()->orderBy('category')->pluck('category'),
            'news_tags' => News::TAGS,
        ]]);
    }

    public function branches(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::ordered());
    }

    public function services(Request $request): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->active()
                ->when($request->has('online'), fn ($q) => $q->where('is_online', $request->boolean('online')))
                ->orderBy('sort')->orderBy('name')->get()
        );
    }

    /** Filters: service_id, branch_id, q (name or specialty). Only doctors with active access are listed. */
    public function doctors(Request $request): AnonymousResourceCollection
    {
        $doctors = Doctor::query()->bookable()
            ->with('user.staffProfile.branch', 'services')
            ->withAvg('reviews', 'rating')->withCount('reviews')
            ->when($request->integer('service_id'), function ($q, $serviceId) {
                $service = Service::find($serviceId);
                if ($service && ! $service->is_online) {
                    $q->whereHas('services', fn ($s) => $s->whereKey($serviceId));
                }
            })
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->whereHas('user.staffProfile', fn ($p) => $p->where('branch_id', $b)))
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('specialty', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))))
            ->get()
            ->sortBy('user.name')->values();

        return DoctorResource::collection($doctors);
    }

    public function doctor(Doctor $doctor): DoctorResource
    {
        abort_unless(Doctor::query()->bookable()->whereKey($doctor->id)->exists(), 404);
        $doctor->load('user.staffProfile.branch', 'services')->loadAvg('reviews', 'rating')->loadCount('reviews');

        return new DoctorResource($doctor);
    }

    /**
     * Free start times. GET /catalog/availability?service_id=1&date=2026-11-10[&doctor_id=1][&branch_id=1]
     * Without doctor_id the slot is free if any suitable doctor is free (reception will assign one).
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'doctor_id' => ['nullable', 'exists:doctors,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);
        $service = Service::findOrFail($data['service_id']);
        $doctor = isset($data['doctor_id']) ? Doctor::query()->bookable()->findOrFail($data['doctor_id']) : null;
        $branch = isset($data['branch_id']) ? Branch::find($data['branch_id']) : null;

        $slots = $this->schedule->slots($service, $data['date'], $doctor, $branch);

        return response()->json(['data' => [
            'date' => $data['date'],
            'service_id' => $service->id,
            'doctor_id' => $doctor?->id,
            'duration_minutes' => $service->duration_minutes,
            'slots' => $slots,
            'free_count' => collect($slots)->where('available', true)->count(),
        ]]);
    }

    /** Filters: category, q, in_stock=1. */
    public function products(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()->active()->withStock()
            ->when($request->string('category')->value(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderBy('name')->get();

        if ($request->boolean('in_stock')) {
            $products = $products->filter(fn (Product $p) => $p->available() > 0)->values();
        }

        return ProductResource::collection($products);
    }

    public function product(Product $product): ProductResource
    {
        abort_unless($product->active, 404);

        return new ProductResource(Product::query()->withStock()->findOrFail($product->id));
    }

    public function news(Request $request): AnonymousResourceCollection
    {
        return NewsResource::collection(
            News::query()->published()->latest('published_at')->paginate(min($request->integer('per_page', 20), 50))
        );
    }

    public function newsItem(News $news): NewsResource
    {
        abort_unless($news->published, 404);

        return new NewsResource($news);
    }

    /** Academy catalogue. Filter: level. */
    public function courses(Request $request): AnonymousResourceCollection
    {
        return CourseResource::collection(
            Course::query()->where('active', true)->with('teachers.user', 'modules:id,course_id,title,week', 'branch')
                ->when($request->string('level')->value(), fn ($q, $l) => $q->where('level', $l))
                ->orderBy('starts_on')->get()
        );
    }

    public function course(Course $course): CourseResource
    {
        abort_unless($course->active, 404);

        return new CourseResource($course->load('teachers.user', 'modules:id,course_id,title,week', 'branch'));
    }
}
