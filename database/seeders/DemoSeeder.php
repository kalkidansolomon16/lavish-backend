<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ClassSession;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Doctor;
use App\Models\Enrollment;
use App\Models\ExerciseSubmission;
use App\Models\News;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ChatService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data matching the web and mobile prototypes (same people, products, courses).
 * Every demo account's password is "password". See README → Demo accounts.
 */
class DemoSeeder extends Seeder
{
    private string $password;

    private CarbonImmutable $today;

    /** @var array<string, Branch> */
    private array $branch = [];

    /** @var array<string, Service> */
    private array $service = [];

    /** @var array<string, Doctor> */
    private array $doctor = [];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, Client> */
    private array $client = [];

    /** @var array<int, Product> */
    private array $product = [];

    public function run(): void
    {
        $this->password = Hash::make('password');
        $this->today = CarbonImmutable::today();

        $this->branches();
        $this->services();
        $this->people();
        $this->clients();
        $this->products();
        $this->appointments();
        $this->orders();
        $this->prescriptions();
        $this->complaints();
        $this->news();
        $this->academy();
        $this->team();
    }

    private function at(int $days, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($this->today->addDays($days)->toDateString().' '.$time);
    }

    private function branches(): void
    {
        foreach ([['Addis Ababa', 'Bole Road, Addis Ababa', true], ['Adama', 'Main Street, Adama', false], ['Dire Dawa', 'Kezira, Dire Dawa', false]] as $i => [$name, $address, $main]) {
            $this->branch[$name] = Branch::create(['name' => $name, 'address' => $address, 'is_main' => $main, 'sort' => $i]);
        }
    }

    private function services(): void
    {
        $list = [
            ['Signature HydraFacial', 4500, 60, 'spa'],
            ['PRP Skin Rejuvenation', 9000, 75, 'water_drop'],
            ['Morpheus8 & Microneedling', 12000, 90, 'auto_awesome'],
            ['Advanced Dermatology Treatments', 3500, 45, 'healing'],
            ['Botox & Dermal Fillers', 18500, 45, 'vaccines'],
            ['HIFU Face & Neck Lift', 15000, 90, 'face_retouching_natural'],
            ['Hair Restoration Solutions', 45000, 240, 'face'],
            ['Body Contouring & Skin Tightening', 20000, 120, 'accessibility_new'],
        ];
        foreach ($list as $i => [$name, $price, $mins, $icon]) {
            $this->service[$name] = Service::create(['name' => $name, 'slug' => Str::slug($name), 'price' => $price, 'duration_minutes' => $mins, 'icon' => $icon, 'is_online' => false, 'sort' => $i]);
        }
        $this->service['Online Consultation'] = Service::create([
            'name' => 'Online Consultation', 'slug' => 'online-consultation', 'price' => 4000, 'duration_minutes' => 45,
            'icon' => 'video_call', 'is_online' => true, 'sort' => 99,
            'description' => 'A 45-minute video call with a Lavish doctor. Reception confirms the time; the video link appears in the app once paid.',
        ]);
    }

    private function user(string $name, string $phone, UserType $type, array $extra = []): User
    {
        return User::create([
            'name' => $name, 'phone' => $phone, 'type' => $type, 'password' => $this->password,
            'status' => UserStatus::Active, 'phone_verified_at' => now(), 'last_login_at' => now()->subDay(),
        ] + $extra);
    }

    private function people(): void
    {
        $roles = Role::all()->keyBy('key');
        $staff = [
            ['ceo', 'Dr. Melat Solomon', '+251911001122', 'Chief Executive Officer', 'Addis Ababa', 'ceo@lavish.et'],
            ['gm', 'Dawit Haile', '+251911009900', 'General Manager', 'Addis Ababa', 'gm@lavish.et'],
            ['reception', 'Hana Tadesse', '+251911003344', 'Front Desk', 'Addis Ababa', 'reception@lavish.et'],
            ['reception', 'Tsion Alemayehu', '+251911005566', 'Front Desk', 'Adama', null],
            ['reception', 'Mekdes Tolera', '+251251117788', 'Front Desk', 'Dire Dawa', null],
            ['ecommerce', 'Abel Girma', '+251911112233', 'E-commerce & Inventory', 'Addis Ababa', 'shop@lavish.et'],
            ['team', 'Selam Getahun', '+251911445566', 'Nurse', 'Addis Ababa', null],
            ['team', 'Ruth Alemayehu', '+251911778899', 'Skin Therapist', 'Addis Ababa', null],
            ['team', 'Yared Bekele', '+251922334455', 'Delivery & Logistics', 'Addis Ababa', null],
        ];
        foreach ($staff as [$role, $name, $phone, $position, $branch, $email]) {
            $u = $this->user($name, $phone, UserType::Staff, ['email' => $email]);
            if ($name === 'Mekdes Tolera') {
                $u->update(['status' => UserStatus::Suspended]);
            }
            $u->roles()->attach($roles[$role]->id);
            $u->staffProfile()->create(['position' => $position, 'branch_id' => $this->branch[$branch]->id]);
            $this->staff[$name] = $u;
        }

        $doctors = [
            ['Dr. Meron Alemu', '+251911500001', 'Dermatology', ['Signature HydraFacial', 'PRP Skin Rejuvenation', 'Morpheus8 & Microneedling', 'Advanced Dermatology Treatments'], 'Addis Ababa', 'available', 'active', 9, 185, 'Board-certified dermatologist focused on skin health, acne scarring and non-surgical rejuvenation.', 'Mon – Sat', 'doctor@lavish.et'],
            ['Dr. Yonas Tesfaye', '+251911500002', 'Aesthetic Medicine', ['Botox & Dermal Fillers', 'HIFU Face & Neck Lift', 'Signature HydraFacial'], 'Addis Ababa', 'available', 'active', 7, 25, 'Aesthetic physician known for natural-looking injectables and lifting treatments.', 'Tue – Sun', null],
            ['Dr. Liya Getachew', '+251911500003', 'Hair Transplant', ['Hair Restoration Solutions'], 'Adama', 'in_surgery', 'active', 11, 340, 'Hair restoration surgeon specialising in FUE transplants and PRP scalp therapy.', 'Mon – Fri', null],
            ['Dr. Biruk Mengistu', '+251911500004', 'Body Transformation', ['Body Contouring & Skin Tightening'], 'Dire Dawa', 'off_today', 'active', 6, 140, 'Body contouring specialist using non-invasive fat reduction and skin tightening.', 'Mon – Sat', null],
            ['Dr. Sara Kebede', '+251911500005', 'Laser & Skin Resurfacing', ['Advanced Dermatology Treatments'], 'Adama', 'available', 'pending', 5, 260, null, 'Mon – Fri', null],
        ];
        foreach ($doctors as [$name, $phone, $specialty, $skills, $branch, $availability, $access, $years, $hue, $bio, $days, $email]) {
            $u = $this->user($name, $phone, UserType::Staff, ['email' => $email]);
            $u->roles()->attach($roles['doctor']->id);
            $u->staffProfile()->create(['position' => 'Doctor', 'branch_id' => $this->branch[$branch]->id]);
            $d = $u->doctor()->create([
                'specialty' => $specialty, 'availability' => $availability, 'access' => $access,
                'years_experience' => $years, 'hue' => $hue, 'bio' => $bio, 'working_days' => $days,
            ]);
            $d->services()->sync(collect($skills)->map(fn ($s) => $this->service[$s]->id));
            $this->doctor[$name] = $d->setRelation('user', $u);
        }
    }

    private function clients(): void
    {
        $rows = [
            ['Tigist Worku', '+251911223344', 'tigist.w@gmail.com', -83, 'Dr. Meron Alemu', true, 'Female', ['Walk-in', 'Dull, dehydrated skin before a wedding', 'Dry', 'None known', 'None', 'None', 'No previous aesthetic treatments', 'No']],
            ['Rahel Assefa', '+251922115566', 'rahel.assefa@gmail.com', -64, 'Dr. Meron Alemu', true, 'Female', null],
            ['Mulu Desta', '+251933447788', 'mulu.desta@yahoo.com', -25, 'Dr. Yonas Tesfaye', true, 'Female', null],
            ['Frehiwot Kebede', '+251944901234', 'frehiwot.k@gmail.com', -16, 'Dr. Yonas Tesfaye', true, 'Female', ['Phone call', 'Fine lines around the eyes and forehead', 'Combination', 'Lidocaine (mild)', 'None', 'None', 'Botox 2025 (another clinic)', 'No']],
            ['Saron Mekonnen', '+251955678901', 'saron.mekonnen@gmail.com', -8, 'Dr. Liya Getachew', true, 'Female', null],
            ['Betty Yilma', '+251966234567', 'betty.yilma@outlook.com', -98, 'Dr. Meron Alemu', true, 'Female', null],
            ['Almaz Tesfaye', '+251977314590', null, 0, 'Dr. Meron Alemu', false, 'Female', ['Walk-in', 'Dark spots on cheeks (melasma)', 'Oily', 'Penicillin', 'Oral contraceptives', 'None', 'Chemical peel 2024', 'No']],
        ];
        foreach ($rows as [$name, $phone, $email, $joined, $doctor, $app, $gender, $history]) {
            $user = $app ? $this->user($name, $phone, UserType::Client, ['email' => $email]) : null;
            $c = Client::create([
                'user_id' => $user?->id, 'name' => $name, 'phone' => $phone, 'email' => $email, 'gender' => $gender,
                'source' => $app ? 'app' : 'walk_in', 'primary_doctor_id' => $this->doctor[$doctor]->id,
                'preferred_branch_id' => $this->branch['Addis Ababa']->id,
                'created_at' => $this->today->addDays($joined), 'created_by' => $app ? null : $this->staff['Hana Tadesse']->id,
            ]);
            if ($history) {
                $c->history()->create(array_combine(['source', 'concern', 'skin_type', 'allergies', 'medications', 'conditions', 'previous_treatments', 'pregnant'], $history)
                    + ['recorded_by' => $this->staff['Hana Tadesse']->id]);
            }
            $this->client[$name] = $c;
        }
    }

    private function products(): void
    {
        $list = [
            [1, 'Vitamin C Brightening Serum', 'Skincare', 2800, 34, 'LV-SER-001', '30 ml', 32, 'A lightweight 15% vitamin C serum that fades dark spots and evens skin tone.', 'Apply 3–4 drops every morning before moisturiser and sunscreen.'],
            [2, 'Hyaluronic Hydra Cream', 'Skincare', 3200, 21, 'LV-CRM-002', '50 ml', 190, 'Deeply hydrating cream with three weights of hyaluronic acid for plump, calm skin.', 'Massage onto clean skin morning and night.'],
            [3, 'SPF 50 Mineral Sunscreen', 'Sun care', 1900, 5, 'LV-SUN-003', '50 ml', 45, 'Broad-spectrum mineral protection with no white cast. Safe after treatments.', 'Apply generously every morning; reapply every 3 hours outdoors.'],
            [4, 'Post-Treatment Recovery Balm', 'Aftercare', 2400, 18, 'LV-AFT-004', '40 ml', 160, 'Soothes redness and supports the skin barrier after peels, lasers and microneedling.', 'Thin layer on treated area twice daily for 5 days.'],
            [5, 'Scalp Revive Hair Tonic', 'Hair', 2100, 0, 'LV-HAI-005', '100 ml', 280, 'Peptide tonic that supports hair density between PRP sessions.', 'Spray onto dry scalp each evening and massage in.'],
            [6, 'Gentle Foam Cleanser', 'Skincare', 1600, 40, 'LV-CLE-006', '150 ml', 200, 'pH-balanced cleanser that removes makeup and sunscreen without stripping.', 'Lather with water, massage for 30 seconds, rinse.'],
            [7, 'Retinol Night Repair', 'Skincare', 3600, 12, 'LV-RET-007', '30 ml', 260, 'Encapsulated retinol for smoother texture and fewer fine lines.', 'Use 2–3 nights a week, then nightly. Always wear SPF.'],
            [8, 'Soothing Aloe Gel', 'Aftercare', 1200, 25, 'LV-ALO-008', '100 ml', 120, 'Cooling aloe gel for sun exposure and post-treatment warmth.', 'Apply as needed to calm the skin.'],
        ];
        $ceo = $this->staff['Dr. Melat Solomon'];
        foreach ($list as [$key, $name, $category, $price, $stock, $sku, $size, $hue, $desc, $use]) {
            $p = Product::create([
                'sku' => $sku, 'name' => $name, 'slug' => Str::slug($name), 'category' => $category, 'price' => $price,
                'size' => $size, 'hue' => $hue, 'description' => $desc, 'usage' => $use, 'reorder_level' => 10,
            ]);
            // Most stock at Addis Ababa so transfers have something to move.
            $a = (int) ceil($stock * 0.6);
            $b = intdiv($stock - $a, 2);
            foreach ([['Addis Ababa', $a], ['Adama', $b], ['Dire Dawa', $stock - $a - $b]] as [$branch, $qty]) {
                BranchStock::create(['product_id' => $p->id, 'branch_id' => $this->branch[$branch]->id, 'quantity' => $qty]);
            }
            StockMovement::create([
                'product_id' => $p->id, 'type' => StockMovementType::In, 'quantity' => $stock, 'balance_after' => $stock,
                'to_branch_id' => $this->branch['Addis Ababa']->id, 'reason' => 'Stock received', 'reference' => 'Opening stock',
                'user_id' => $ceo->id, 'created_at' => $this->today->subDays(20),
            ]);
            $this->product[$key] = $p;
        }
    }

    private function appointment(string $client, ?string $doctor, string $service, int $day, string $time, AppointmentStatus $status, array $extra = [], ?array $payment = null): Appointment
    {
        $s = $this->service[$service];
        $start = $this->at($day, $time);
        $d = $doctor ? $this->doctor[$doctor] : null;
        $a = Appointment::create([
            'client_id' => $this->client[$client]->id,
            'doctor_id' => $d?->id,
            'service_id' => $s->id,
            'branch_id' => $s->is_online ? null : ($d ? $d->user->staffProfile->branch_id : $this->branch['Addis Ababa']->id),
            'is_online' => $s->is_online,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($s->duration_minutes),
            'duration_minutes' => $s->duration_minutes,
            'price' => $s->price,
            'status' => $status,
            'approved_by' => in_array($status, [AppointmentStatus::Approved, AppointmentStatus::Completed]) ? $this->staff['Hana Tadesse']->id : null,
            'approved_at' => in_array($status, [AppointmentStatus::Approved, AppointmentStatus::Completed]) ? $start->subDays(2) : null,
            'completed_at' => $status === AppointmentStatus::Completed ? $start->addMinutes($s->duration_minutes) : null,
        ] + $extra);

        Payment::create([
            'client_id' => $a->client_id, 'payable_type' => 'appointment', 'payable_id' => $a->id,
            'description' => $s->is_online ? 'Online Consultation (45 min)' : $s->name, 'amount' => $s->price,
            'status' => $payment['status'] ?? PaymentStatus::Unpaid,
            'method' => $payment['method'] ?? null,
            'provider' => $payment['provider'] ?? null,
            'tx_ref' => $payment['tx_ref'] ?? null,
            'paid_at' => isset($payment['status']) && $payment['status'] === PaymentStatus::Paid ? $start->subDay() : null,
            'recorded_by' => isset($payment['provider']) ? null : (isset($payment['status']) ? $this->staff['Hana Tadesse']->id : null),
        ]);

        return $a;
    }

    private function appointments(): void
    {
        $paidChapa = fn (PaymentMethod $m, string $ref) => ['status' => PaymentStatus::Paid, 'method' => $m, 'provider' => 'chapa', 'tx_ref' => $ref];

        $this->appointment('Mulu Desta', 'Dr. Yonas Tesfaye', 'HIFU Face & Neck Lift', 1, '10:00', AppointmentStatus::Pending, ['client_note' => 'Want a lift around the jawline.']);
        $this->appointment('Saron Mekonnen', 'Dr. Liya Getachew', 'Hair Restoration Solutions', 2, '14:30', AppointmentStatus::Pending);
        $this->appointment('Tigist Worku', 'Dr. Meron Alemu', 'Signature HydraFacial', 0, '09:00', AppointmentStatus::Approved, [], $paidChapa(PaymentMethod::Telebirr, 'LV-DEMO-7K2M9QX1'));
        $this->appointment('Betty Yilma', 'Dr. Meron Alemu', 'Morpheus8 & Microneedling', 0, '11:30', AppointmentStatus::Approved);
        $this->appointment('Frehiwot Kebede', 'Dr. Yonas Tesfaye', 'Botox & Dermal Fillers', -3, '15:00', AppointmentStatus::Completed, [], $paidChapa(PaymentMethod::CbeBirr, 'LV-DEMO-B84LZ0TD'));
        // Clashes with Dr. Meron's 10:00 class tomorrow — reception sees the conflict before approving.
        $this->appointment('Rahel Assefa', 'Dr. Meron Alemu', 'PRP Skin Rejuvenation', 1, '11:00', AppointmentStatus::Pending);
        $this->appointment('Saron Mekonnen', null, 'Body Contouring & Skin Tightening', 1, '11:00', AppointmentStatus::Pending);
        $this->appointment('Mulu Desta', null, 'Signature HydraFacial', 1, '13:30', AppointmentStatus::Pending);
        $this->appointment('Betty Yilma', null, 'Hair Restoration Solutions', 2, '09:00', AppointmentStatus::Pending);
        // Past treatments that unlock chats.
        $this->appointment('Tigist Worku', 'Dr. Meron Alemu', 'Signature HydraFacial', -20, '09:00', AppointmentStatus::Completed, ['client_note' => 'Dull, dry skin before a wedding.'], $paidChapa(PaymentMethod::Telebirr, 'LV-DEMO-HF201'));
        $this->appointment('Rahel Assefa', 'Dr. Meron Alemu', 'PRP Skin Rejuvenation', -30, '10:00', AppointmentStatus::Completed, [], ['status' => PaymentStatus::Paid, 'method' => PaymentMethod::Cash]);
        $this->appointment('Betty Yilma', 'Dr. Meron Alemu', 'Morpheus8 & Microneedling', -40, '13:30', AppointmentStatus::Completed, [], ['status' => PaymentStatus::Paid, 'method' => PaymentMethod::Cash]);
        $this->appointment('Tigist Worku', 'Dr. Liya Getachew', 'Hair Restoration Solutions', -6, '14:30', AppointmentStatus::Declined, ['decline_reason' => 'Consultation only — please book a consultation first.']);
        // Online consultations booked from the app.
        $online = $this->appointment('Tigist Worku', 'Dr. Meron Alemu', 'Online Consultation', 4, '16:00', AppointmentStatus::Approved,
            ['client_note' => 'Follow-up on dryness after the HydraFacial — I will show my skin on camera.'], $paidChapa(PaymentMethod::Telebirr, 'LV-DEMO-Q3N8VD5E'));
        $online->update(['video_link' => 'https://meet.jit.si/Lavish-'.$online->id.'-demo']);
        $this->appointment('Tigist Worku', null, 'Online Consultation', 6, '14:00', AppointmentStatus::Pending, ['client_note' => 'Hair thinning at the crown — is PRP right for me?']);
        $this->appointment('Mulu Desta', 'Dr. Yonas Tesfaye', 'Online Consultation', 1, '15:00', AppointmentStatus::Pending);

        foreach (['Tigist Worku', 'Rahel Assefa', 'Betty Yilma', 'Frehiwot Kebede'] as $name) {
            $c = $this->client[$name];
            $c->update(['first_treated_at' => $c->appointments()->where('status', 'completed')->min('completed_at')]);
        }
    }

    private function order(string $client, array $lines, OrderStatus $status, int $day, string $method, string $address, bool $paid, ?array $courier = null): Order
    {
        $c = $this->client[$client];
        $subtotal = collect($lines)->map(fn ($q, $pid) => $this->product[$pid]->price * $q)->sum();
        $o = Order::create([
            'client_id' => $c->id, 'status' => $status, 'subtotal' => $subtotal, 'delivery_fee' => 150, 'total' => $subtotal + 150,
            'payment_method' => $method, 'delivery_address' => $address, 'contact_phone' => $c->phone,
            'courier_name' => $courier[0] ?? null, 'courier_phone' => $courier[1] ?? null,
            'created_at' => $this->at($day, '08:05'), 'delivered_at' => $status === OrderStatus::Delivered ? $this->at($day + 1, '12:30') : null,
        ]);
        foreach ($lines as $pid => $q) {
            $p = $this->product[$pid];
            $o->items()->create(['product_id' => $p->id, 'product_name' => $p->name, 'unit_price' => $p->price, 'quantity' => $q, 'line_total' => $p->price * $q]);
        }
        $flow = OrderStatus::flow();
        foreach (array_slice($flow, 0, array_search($status, $flow, true) + 1) as $i => $s) {
            $o->events()->create(['status' => $s, 'user_id' => $i ? $this->staff['Abel Girma']->id : null, 'created_at' => $this->at($day, sprintf('%02d:%s', 8 + $i, $i % 2 ? '40' : '05'))]);
        }
        Payment::create([
            'client_id' => $c->id, 'payable_type' => 'order', 'payable_id' => $o->id, 'description' => "Order #{$o->id}", 'amount' => $o->total,
            'status' => $paid ? PaymentStatus::Paid : PaymentStatus::Unpaid,
            'method' => $paid ? ($method === 'pay_on_delivery' ? PaymentMethod::Cash : PaymentMethod::from($method)) : null,
            'provider' => $paid && $method !== 'pay_on_delivery' ? 'chapa' : null,
            'paid_at' => $paid ? $this->at($day, '08:10') : null,
        ]);

        return $o;
    }

    private function orders(): void
    {
        $this->order('Tigist Worku', [1 => 1, 3 => 2], OrderStatus::Out, 0, 'pay_on_delivery', 'Bole, around Edna Mall, Addis Ababa', false, ['Yared Bekele', '+251922334455']);
        $this->order('Frehiwot Kebede', [4 => 1], OrderStatus::Preparing, 0, 'telebirr', 'CMC, Addis Ababa', true);
        $this->order('Rahel Assefa', [2 => 2], OrderStatus::Delivered, -2, 'cbe_birr', 'Piassa, Addis Ababa', true, ['Yared Bekele', '+251922334455']);
        $this->order('Betty Yilma', [3 => 1], OrderStatus::Placed, 0, 'pay_on_delivery', 'Kazanchis, Addis Ababa', false);
    }

    private function prescriptions(): void
    {
        $meron = $this->doctor['Dr. Meron Alemu'];
        $rx = [
            ['Tigist Worku', $meron, -1, 'sent', 'Mild dryness after HydraFacial — keep skin hydrated, avoid exfoliants for a week.',
                [[2, null, 1, 'Apply twice daily, morning and night', false], [3, null, 2, 'Every morning, reapply after 3 hours outdoors', false]]],
            ['Frehiwot Kebede', $this->doctor['Dr. Yonas Tesfaye'], -3, 'ordered', 'Post-filler care.',
                [[4, null, 1, 'Thin layer on treated area 2× daily for 5 days', false]]],
            ['Betty Yilma', $meron, -12, 'sent', 'Protect the treated skin from the sun for 4 weeks.',
                [[3, null, 1, 'Every morning, reapply after 3 hours outdoors', true], [null, 'Tranexamic acid 250 mg tablets', 1, '1 tablet twice daily for 8 weeks', false]]],
            ['Rahel Assefa', $meron, -5, 'sent', 'Support hair growth between PRP sessions.',
                [[5, null, 1, 'Massage into scalp every evening', true]]],
        ];
        foreach ($rx as [$client, $doctor, $day, $status, $note, $items]) {
            $p = Prescription::create(['client_id' => $this->client[$client]->id, 'doctor_id' => $doctor->id, 'status' => $status, 'note' => $note, 'created_at' => $this->at($day, '10:00')]);
            foreach ($items as [$pid, $custom, $qty, $instructions, $waiting]) {
                $p->items()->create(['product_id' => $pid ? $this->product[$pid]->id : null, 'custom_name' => $custom, 'quantity' => $qty, 'instructions' => $instructions, 'was_out_of_stock' => $waiting]);
            }
        }
    }

    private function complaints(): void
    {
        $gm = $this->staff['Dawit Haile'];
        $rows = [
            ['Frehiwot Kebede', 'Treatment', 'Redness after filler session', 'The area is still red after 3 days. Should I be worried?', 'high', 'open', -1, null],
            ['Betty Yilma', 'Appointment', 'Long waiting time', 'I waited 40 minutes past my appointment time.', 'medium', 'in_review', -4, 'We are reviewing scheduling at our Addis Ababa branch.'],
            ['Rahel Assefa', 'Order', 'Wrong item delivered', 'I ordered the cream but got the serum.', 'low', 'resolved', -9, 'Replacement delivered. Apologies!'],
        ];
        foreach ($rows as [$client, $category, $subject, $message, $priority, $status, $day, $reply]) {
            $c = Complaint::create([
                'client_id' => $this->client[$client]->id, 'category' => $category, 'subject' => $subject, 'message' => $message,
                'priority' => $priority, 'status' => $status, 'created_at' => $this->at($day, '12:00'),
                'resolved_at' => $status === 'resolved' ? $this->at($day + 2, '12:00') : null,
            ]);
            if ($reply) {
                $c->messages()->create(['user_id' => $gm->id, 'from_staff' => true, 'body' => $reply, 'created_at' => $this->at($day + 1, '09:00')]);
            }
        }
    }

    private function news(): void
    {
        $rows = [
            ['New: Morpheus8 now at Adama', 'Our most requested skin-tightening treatment is now available at the Adama branch. Book it from the app with Dr. Meron or Dr. Yonas.', 'New treatment', -1, 'Dr. Melat Solomon', true],
            ['20% off aftercare this week', 'All Aftercare products are 20% off in the Lavish shop until Sunday. The discount is applied automatically at checkout.', 'Offer', -3, 'Dawit Haile', true],
            ['Lavish Academy: December intake', 'Enrolment for the December Medical Aesthetics Foundations intake is open. Visit the academy or call us to register — we’ll give you your student login.', 'Academy', -6, 'Dawit Haile', false],
        ];
        foreach ($rows as [$title, $body, $tag, $day, $by, $published]) {
            News::create(['title' => $title, 'body' => $body, 'tag' => $tag, 'published' => $published, 'published_at' => $published ? $this->at($day, '09:00') : null,
                'author_id' => $this->staff[$by]->id, 'created_at' => $this->at($day, '09:00')]);
        }
    }

    private function academy(): void
    {
        $meron = $this->doctor['Dr. Meron Alemu'];
        $yonas = $this->doctor['Dr. Yonas Tesfaye'];
        $liya = $this->doctor['Dr. Liya Getachew'];
        $teacherRole = Role::query()->where('key', 'teacher')->firstOrFail();
        foreach ([$meron, $yonas, $liya] as $d) {
            $d->user->roles()->syncWithoutDetaching([$teacherRole->id]);
        }

        $courses = [
            'foundations' => ['Medical Aesthetics Foundations', 8, 35000, 'Beginner', 28, 185, null, [$meron], 'The essential start for a career in medical aesthetics: skin science, hygiene, consultations and core treatments.', 'In person · Addis Ababa', [
                ['Skin anatomy & types', 'Layers of the skin', 18, 'Label the skin layers', -28, true],
                ['Hygiene & infection control', 'Clinic sterilisation walkthrough', 22, 'Hygiene checklist quiz', -21, true],
                ['Client consultation', 'Running a consultation', 25, 'Role-play recording', -14, true],
                ['Facials & extractions', 'Extraction technique', 31, 'Practical log — 3 facials', -7, true],
                ['Chemical peels', 'Peel depth & selection', 27, 'Peel case study (500 words)', 3, true],
                ['Microdermabrasion', null, null, null, null, false],
                ['Aftercare & product advice', null, null, null, null, false],
                ['Final practical exam', null, null, null, null, false],
            ]],
            'hydra' => ['HydraFacial & Skin Analysis', 4, 18000, 'Intermediate', 14, 200, null, [$meron, $yonas], 'Hands-on training on HydraFacial systems and digital skin analysis.', 'In person · Addis Ababa', [
                ['Skin analysis devices', 'Using the skin scanner', 15, 'Analyse 2 client scans', -10, true],
                ['HydraFacial protocol', 'Full HydraFacial protocol', 34, 'Protocol quiz', -3, true],
                ['Boosters & add-ons', null, null, null, null, false],
                ['Practical assessment', null, null, null, null, false],
            ]],
            'injectables' => ['Injectables: Botox & Fillers', 6, 52000, 'Advanced', 45, 25, 'For licensed doctors and nurses only', [$yonas], 'Advanced injection techniques with live-model practice under supervision.', 'In person · Addis Ababa', [
                ['Facial anatomy', 'Danger zones of the face', 40, 'Anatomy mapping', 5, true],
                ['Neurotoxins', null, null, null, null, false],
                ['Hyaluronic fillers', null, null, null, null, false],
            ]],
            'laser' => ['Laser Hair Removal Technician', 5, 28000, 'Beginner', 21, 160, null, [$liya], 'Learn laser physics, safety and treatment for all skin types.', 'In person · Adama', [
                ['Laser physics & safety', null, null, null, null, false],
                ['Skin typing', null, null, null, null, false],
                ['Treatment protocols', null, null, null, null, false],
                ['Practical sessions', null, null, null, null, false],
                ['Certification', null, null, null, null, false],
            ]],
        ];

        $made = [];
        foreach ($courses as $key => [$title, $weeks, $price, $level, $starts, $hue, $req, $teachers, $about, $mode, $modules]) {
            $c = Course::create([
                'title' => $title, 'slug' => Str::slug($title), 'weeks' => $weeks, 'price' => $price, 'level' => $level,
                'starts_on' => $this->today->addDays($starts), 'hue' => $hue, 'requirement' => $req, 'about' => $about, 'mode' => $mode,
                'branch_id' => str_contains($mode, 'Adama') ? $this->branch['Adama']->id : $this->branch['Addis Ababa']->id,
            ]);
            $c->teachers()->sync(collect($teachers)->pluck('id'));
            foreach ($modules as $i => [$mTitle, $video, $mins, $exercise, $due, $published]) {
                $c->modules()->create([
                    'week' => $i + 1, 'title' => $mTitle, 'video_title' => $video, 'video_minutes' => $mins,
                    'video_url' => $video ? 'https://videos.lavish.et/demo/'.Str::slug($video) : null,
                    'exercise_title' => $exercise, 'exercise_due_on' => $due !== null ? $this->today->addDays($due) : null, 'published' => $published,
                ]);
            }
            $made[$key] = $c;
        }

        $sessions = [
            ['foundations', $meron, 1, '10:00', 'Chemical peels — practical', 'Practical', 'Addis Ababa training room'],
            ['hydra', $meron, 2, '14:00', 'HydraFacial protocol — demo', 'Practical', 'Addis Ababa training room'],
            ['foundations', $meron, 4, '14:00', 'Microdermabrasion — theory', 'Theory', 'Online (Zoom)'],
            ['injectables', $yonas, 3, '09:00', 'Facial anatomy — cadaver lab', 'Practical', 'Adama lab'],
        ];
        foreach ($sessions as [$course, $teacher, $day, $time, $title, $kind, $room]) {
            $start = $this->at($day, $time);
            ClassSession::create(['course_id' => $made[$course]->id, 'teacher_id' => $teacher->id, 'title' => $title, 'kind' => $kind,
                'starts_at' => $start, 'ends_at' => $start->addMinutes(120), 'room' => $room]);
        }

        $ceo = $this->staff['Dr. Melat Solomon'];
        $students = [
            ['Eden Fikru', '+251912456789', 'foundations', 62, 'active', 'in_person', true],
            ['Nahom Tilahun', '+251913221044', 'injectables', 15, 'active', 'phone_call', true],
            ['Kidist Amare', '+251914882107', 'hydra', 0, 'active', 'phone_call', false],
            ['Bethel Zewdu', '+251915603312', 'foundations', 0, 'active', 'in_person', false],
            ['Hanna Mulugeta', '+251916740955', 'hydra', 100, 'completed', 'in_person', true],
            ['Selamawit Girma', '+251917129830', 'hydra', 48, 'active', 'phone_call', true],
        ];
        $chat = app(ChatService::class);
        foreach ($students as [$name, $phone, $course, $progress, $status, $via, $activated]) {
            // Activated students have logged in and set their own password ("password" in the demo);
            // the others still hold a temporary password (also "password" here) they must change.
            $u = $this->user($name, $phone, UserType::Student, ['must_change_password' => ! $activated, 'last_login_at' => $activated ? now()->subDay() : null]);
            $e = Enrollment::create([
                'user_id' => $u->id, 'course_id' => $made[$course]->id, 'status' => $status, 'progress' => $progress,
                'enrolled_via' => $via, 'enrolled_by' => $ceo->id, 'fee' => $made[$course]->price, 'paid_at' => now()->subDays(30),
                'completed_at' => $status === 'completed' ? now()->subDays(2) : null,
            ]);
            $chat->studentTeacher($e->load('course'));
        }

        // Eden — a completed earlier course with a certificate, graded exercises and a teacher chat.
        $eden = User::query()->where('phone', '+251912456789')->first();
        $laser = Enrollment::create(['user_id' => $eden->id, 'course_id' => $made['laser']->id, 'status' => 'completed', 'progress' => 100,
            'enrolled_via' => 'in_person', 'enrolled_by' => $ceo->id, 'fee' => 28000, 'paid_at' => now()->subDays(120), 'completed_at' => now()->subDays(40)]);
        $laser->certificate()->create(['number' => 'LA-'.now()->year.'-0001', 'issued_at' => now()->subDays(40), 'issued_by' => $this->staff['Dawit Haile']->id]);
        $chat->studentTeacher($laser->load('course'));

        $grades = [1 => ['graded', '18/20'], 2 => ['graded', '9/10'], 3 => ['graded', 'Pass'], 4 => ['submitted', null]];
        foreach ($made['foundations']->modules()->orderBy('week')->get() as $m) {
            if (isset($grades[$m->week])) {
                ExerciseSubmission::create(['course_module_id' => $m->id, 'user_id' => $eden->id, 'body' => 'Submitted from the app.',
                    'status' => $grades[$m->week][0], 'grade' => $grades[$m->week][1], 'submitted_at' => now()->subDays(30 - $m->week * 7),
                    'graded_at' => $grades[$m->week][1] ? now()->subDays(28 - $m->week * 7) : null, 'graded_by' => $grades[$m->week][1] ? $meron->user_id : null]);
            }
        }
        $edenFoundations = Enrollment::query()->where('user_id', $eden->id)->where('course_id', $made['foundations']->id)->first();
        $c = $edenFoundations->conversation;
        $this->messages($c, [
            [$meron->user_id, 'Welcome to Foundations, Eden! I’m Dr. Meron, your teacher for this course.', -60],
            [$eden->id, 'Thank you! Can I reschedule Friday’s practical?', -1],
        ]);
    }

    private function team(): void
    {
        $chat = app(ChatService::class);
        $s = fn (string $name) => ($this->staff[$name] ?? $this->doctor[$name]->user)->id;

        // Client ↔ doctor chats for treated clients.
        foreach (['Tigist Worku', 'Rahel Assefa', 'Betty Yilma'] as $name) {
            $chat->clientDoctor($this->client[$name], $this->doctor['Dr. Meron Alemu']);
        }
        $chat->clientDoctor($this->client['Frehiwot Kebede'], $this->doctor['Dr. Yonas Tesfaye']);
        $tigist = Conversation::query()->where('client_id', $this->client['Tigist Worku']->id)->first();
        $this->messages($tigist, [
            [$s('Dr. Meron Alemu'), 'Hello Tigist, how is your skin feeling after the HydraFacial?', -3],
            [$this->client['Tigist Worku']->user_id, 'Hi Doctor, it feels a bit dry since the facial. Is that normal?', -3],
            [$s('Dr. Meron Alemu'), 'Mild dryness is normal for 48h. I sent you a prescription — use the Hydra Cream twice daily.', -3],
        ]);
        $betty = Conversation::query()->where('client_id', $this->client['Betty Yilma']->id)->first();
        $this->messages($betty, [[$this->client['Betty Yilma']->user_id, 'Thank you for today!', -40]]);

        $groups = [
            ['All Doctors', 'Clinical updates, protocols and case discussions', 'Dr. Melat Solomon', ['Dr. Melat Solomon', 'Dawit Haile', 'Dr. Meron Alemu', 'Dr. Yonas Tesfaye', 'Dr. Liya Getachew', 'Dr. Biruk Mengistu'],
                [['Dr. Melat Solomon', 'Please review the updated consent form before Monday.', -2], ['Dr. Yonas Tesfaye', 'Done — looks good.', -2]]],
            ['Addis Ababa Front Desk & Care', 'Daily coordination at the Addis Ababa branch', 'Dawit Haile', ['Dawit Haile', 'Hana Tadesse', 'Selam Getahun', 'Ruth Alemayehu', 'Dr. Meron Alemu'],
                [['Hana Tadesse', 'Morning! 3 walk-ins already booked for this afternoon.', 0], ['Selam Getahun', 'Treatment room 2 is ready.', 0]]],
            ['Shop & Delivery', 'Orders, stock and deliveries', 'Dr. Melat Solomon', ['Dr. Melat Solomon', 'Abel Girma', 'Yared Bekele'],
                [['Abel Girma', 'Order is packed and ready for pickup.', -1]]],
        ];
        $made = [];
        foreach ($groups as [$name, $about, $by, $members, $messages]) {
            $g = $chat->createGroup(User::find($s($by)), $name, $about, array_map($s, $members));
            $this->messages($g, array_map(fn ($m) => [$s($m[0]), $m[1], $m[2]], $messages));
            $made[$name] = $g;
        }

        $dm = $chat->direct(User::find($s('Hana Tadesse')), User::find($s('Selam Getahun')));
        $this->messages($dm, [[$s('Hana Tadesse'), 'Can you prep room 1 for Tigist at 9?', 0], [$s('Selam Getahun'), 'On it 👍', 0]]);

        $a1 = Announcement::create(['title' => 'Welcome to Lavish Team', 'body' => 'This is our new space for staff news, groups and direct messages. Please check it at the start of every shift.',
            'author_id' => $s('Dr. Melat Solomon'), 'created_at' => $this->at(-2, '08:00')]);
        $a1->readers()->attach([$s('Dr. Melat Solomon') => ['read_at' => now()], $s('Dawit Haile') => ['read_at' => now()]]);
        $a2 = Announcement::create(['title' => 'Addis Ababa team meeting — Friday 8:30', 'body' => 'Short stand-up before opening: new HydraFacial booster protocol and weekend staffing.',
            'conversation_id' => $made['Addis Ababa Front Desk & Care']->id, 'author_id' => $s('Dawit Haile'), 'created_at' => $this->at(-1, '08:00')]);
        $a2->readers()->attach([$s('Dawit Haile') => ['read_at' => now()]]);
    }

    /** @param  list<array{0: int, 1: string, 2: int}>  $rows  [user id, text, day offset] */
    private function messages(Conversation $c, array $rows): void
    {
        $i = 0;
        foreach ($rows as [$userId, $body, $day]) {
            $at = $this->at($day, '09:00')->addMinutes(7 * $i++);
            $c->messages()->create(['user_id' => $userId, 'body' => $body, 'created_at' => $at, 'updated_at' => $at]);
            $c->update(['last_message_at' => $at]);
        }
        $c->participants()->update(['last_read_at' => $c->last_message_at]);
    }
}
