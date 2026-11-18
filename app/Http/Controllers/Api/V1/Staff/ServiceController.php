<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Treatments page — what clients can book in the app.
 *   page.treatments      list (with hidden ones and which doctors offer each)
 *   treatments.create    add
 *   treatments.edit      name, price, length, description, icon, shown/hidden, order, doctors
 *   treatments.delete    remove (only when never booked — otherwise hide it, so history stays intact)
 * The online consultation (is_online) can be edited but not removed or added twice.
 */
class ServiceController extends StaffController
{
    /** Icons the app can show (Material Symbols names). */
    public const ICONS = ['spa', 'water_drop', 'auto_awesome', 'healing', 'vaccines', 'face_retouching_natural', 'face',
        'self_improvement', 'accessibility_new', 'fitness_center', 'bolt', 'content_cut', 'science', 'diamond', 'favorite', 'medical_services', 'video_call'];

    public function index(): JsonResponse
    {
        $services = Service::query()->withCount('appointments')->with('doctors:id,user_id')->orderBy('sort')->orderBy('name')->get();

        return response()->json([
            'data' => $services->map(fn (Service $s) => $this->row($s))->values(),
            'meta' => [
                'icons' => self::ICONS,
                'doctors' => Doctor::query()->with('user:id,name')->get()->map(fn (Doctor $d) => ['id' => $d->id, 'name' => $d->name, 'specialty' => $d->specialty])->sortBy('name')->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $service = Service::create([
            ...collect($data)->except('doctor_ids')->all(),
            'slug' => $this->slug($data['name']),
            'is_online' => false,
            'sort' => $data['sort'] ?? ((int) Service::query()->max('sort') + 1),
        ]);
        $service->doctors()->sync($data['doctor_ids'] ?? []);
        AuditLog::record($request->user(), 'treatment.create', 'service', $service->id, null, null, $service->toArray());

        return response()->json(['data' => $this->row($service->loadCount('appointments')->load('doctors'))], 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $data = $this->validated($request, $service);
        $before = $service->toArray();
        $service->fill(collect($data)->except('doctor_ids')->all());
        if ($service->isDirty('name')) {
            $service->slug = $this->slug($service->name, $service->id);
        }
        $service->save();
        if (array_key_exists('doctor_ids', $data)) {
            $service->doctors()->sync($data['doctor_ids'] ?? []);
        }
        AuditLog::record($request->user(), 'treatment.update', 'service', $service->id, null, $before, $service->toArray());

        return response()->json(['data' => $this->row($service->loadCount('appointments')->load('doctors'))]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        if ($service->is_online) {
            throw BusinessRuleException::make('treatment_required', 'The online consultation can’t be removed — hide it instead if you don’t offer it now.');
        }
        if ($service->appointments()->exists()) {
            throw BusinessRuleException::make('treatment_in_use', 'This treatment has bookings, so it can’t be deleted. Hide it instead — clients won’t see it, and its history stays.');
        }
        AuditLog::record($request->user(), 'treatment.delete', 'service', $service->id, null, $service->toArray(), null);
        $service->doctors()->detach();
        $service->delete();

        return response()->json(['message' => 'Treatment removed.']);
    }

    private function validated(Request $request, ?Service $service): array
    {
        $req = $service ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:120', Rule::unique('services', 'name')->ignore($service?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => [$req, 'integer', 'min:0', 'max:1000000'],
            'duration_minutes' => [$req, 'integer', 'min:15', 'max:600'],
            'icon' => ['nullable', Rule::in(self::ICONS)],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'doctor_ids' => ['sometimes', 'nullable', 'array'],
            'doctor_ids.*' => ['integer', 'exists:doctors,id'],
        ]);
    }

    private function slug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'treatment';
        $slug = $base;
        for ($i = 2; Service::query()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function row(Service $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'price' => $s->price,
            'duration_minutes' => $s->duration_minutes, 'icon' => $s->icon, 'is_online' => $s->is_online,
            'active' => $s->active, 'sort' => $s->sort,
            'bookings' => (int) ($s->appointments_count ?? 0),
            'doctor_ids' => $s->relationLoaded('doctors') ? $s->doctors->pluck('id')->values() : [],
        ];
    }
}
