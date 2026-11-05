# Lavish API v1 — guide for the mobile (Flutter) and web developers

Base URL: `https://<api-host>/api/v1` (local: `http://127.0.0.1:8000/api/v1`; from an Android emulator: `http://10.0.2.2:8000/api/v1`).

Everything here is real and covered by the automated tests in `tests/Feature/Api`.

---

## 1. Conventions

| Topic | Rule |
|---|---|
| Format | JSON in, JSON out. Send `Accept: application/json`. File uploads use `multipart/form-data`. |
| Auth | `Authorization: Bearer <token>` (Laravel Sanctum). No cookies. |
| Keys | `snake_case`. |
| Dates | ISO-8601 with the Addis Ababa offset, e.g. `2026-11-09T16:00:00+03:00`. Appointments also give `date` (`Y-m-d`) and `time` (`HH:mm`) for convenience. Send dates as `Y-m-d`, times as `HH:mm`. |
| Money | Whole Ethiopian Birr as integers (`4000` = ETB 4,000). Currency is always `ETB`. |
| Phone | Accepted in any format (`0911 22 33 44`, `911223344`, `+251911223344`). Stored/returned in E.164 (`+251911223344`); `phone_local` gives `0911223344`. |
| Enums | Returned as a string value, or `{ "value": "...", "label": "..." }` when there is a display label. Values are listed in §9. |
| Single item | `{ "data": { ... } }` |
| Lists | `{ "data": [ ... ] }`. Paginated lists add `links` and `meta` (`current_page`, `last_page`, `per_page`, `total`). Use `?page=2&per_page=50`. |

### Errors

Every error has the same shape. **Branch on `code`, show `message`.**

```json
{ "message": "That time was just taken. Please pick another slot.", "code": "slot_taken", "errors": {}, "details": {} }
```

| HTTP | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Token missing/expired/revoked → go to login. |
| 401 | `invalid_credentials` | Wrong phone/password. |
| 403 | `password_change_required` | Temporary password — show the “Set password” screen. |
| 403 | `wrong_app` | Staff account on mobile, or client/student on web. |
| 403 | `account_inactive` | Suspended account (or doctor without access). |
| 403 | `phone_not_verified` | Client registered but has not entered the SMS code (`details.phone`). |
| 403 | `forbidden` / `wrong_account_type` | Not allowed. |
| 404 | `not_found` | Missing — or someone else's record (we never reveal which). |
| 422 | `validation_failed` | Field errors in `errors`: `{ "phone": ["..."] }`. |
| 422 | *business codes* | See §8. |
| 429 | `otp_too_soon` or plain throttling | Wait (`details.retry_after` seconds for OTP). |

---

## 2. Accounts and signing in

There are three account types (`user.type`): `staff` (web portal), `client` and `student` (mobile app).

### Login (everyone)
`POST /auth/login`
```json
{ "login": "0911223344", "password": "password", "app": "mobile", "device_name": "Pixel 8" }
```
`app` must be `mobile` in the Flutter app and `web` in the portal. Response:
```json
{
  "token": "12|xxxxxxxx", "token_type": "Bearer", "expires_at": "2027-02-03T16:06:37+03:00",
  "user": { "id": 15, "name": "Tigist Worku", "phone": "+251911223344", "phone_local": "0911223344",
            "email": "tigist.w@gmail.com", "type": "client", "status": "active", "must_change_password": false,
            "avatar_url": null, "locale": "en", "client_id": 1, "gender": "Female", "date_of_birth": null,
            "preferred_branch": { "id": 1, "name": "Addis Ababa", "address": "Bole Road, Addis Ababa", "phone": null, "is_main": true },
            "joined_at": "2026-08-14T00:00:00+03:00" }
}
```
Open the client or student home depending on `user.type`. Mobile tokens last 90 days, web tokens 12 hours (configurable). Store the token securely (`flutter_secure_storage`).

### Client sign-up (phone + SMS code)
1. `POST /auth/register` `{ name, phone, password, password_confirmation, email?, gender? (Female|Male), date_of_birth?, preferred_branch_id? }` → `201 { message, phone }`, SMS sent.
2. `POST /auth/register/verify` `{ phone, code, device_name? }` → `201` same body as login (token + user).
3. Resend: `POST /auth/otp/resend` `{ phone, purpose: "register" }` (max once a minute).

If reception already booked this person as a walk-in, the new account is linked to their existing record (history, appointments) automatically.

**Development:** with `SMS_DRIVER=log` (not production) every code is **`123456`**.

### Forgot password (clients)
`POST /auth/password/forgot { phone }` → SMS code. Then `POST /auth/password/reset { phone, code, password, password_confirmation }` → token + user.
For students the forgot call answers `code: "call_academy"` — show the academy phone (`/catalog/config` → `academy_phone`).

### Students
Students never sign up. Staff create the account when they enrol and give a **username (local phone, e.g. `0912456789`) and a temporary password**. After login `user.must_change_password` is `true` and every other endpoint answers `403 password_change_required` until:

`POST /auth/password/change { current_password, password, password_confirmation }`

(The same endpoint changes the password any time later.)

### Profile, devices, logout
| | |
|---|---|
| `GET /auth/me` | Current user (same shape as login `user`). |
| `PATCH /auth/me` | `name, email, locale (en|am), gender, date_of_birth, preferred_branch_id` |
| `POST /auth/me/avatar` | multipart `avatar` (image ≤ 4 MB) |
| `POST /auth/devices` | `{ token: "<FCM token>", platform: "android"|"ios" }` — call after login and on token refresh |
| `POST /auth/logout` | `{ device_token? }` revokes the token (and unregisters the device) |

---

## 3. Public catalogue (no token)

| Endpoint | Notes |
|---|---|
| `GET /catalog/config` | `delivery_fee`, `academy_phone`, `booking_slots` (e.g. `["09:00",…,"16:00"]`), `booking_horizon_days`, `online_consultation` (service), `payment_methods`, `order_payment_methods`, `product_categories`, `news_tags`. Load once at start-up. |
| `GET /catalog/branches` | Addis Ababa, Adama, Dire Dawa. |
| `GET /catalog/services` | Treatments with `price`, `duration_minutes`, `icon` (Material Symbols name → `Icons.<name>_rounded`), `is_online`. `?online=0` hides the online consultation. |
| `GET /catalog/doctors` | Bookable doctors. Filters: `service_id`, `branch_id`, `q`. Each has `availability {value,label}`, `branch`, `rating`, `reviews_count`, `services[]`, `hue` (avatar colour). |
| `GET /catalog/doctors/{id}` | One doctor (404 if not bookable). |
| `GET /catalog/availability?service_id=&date=[&doctor_id=][&branch_id=]` | `slots: [{ time, starts_at, available }]`, `free_count`. Without `doctor_id` a slot is free when any suitable doctor is free (reception will assign). |
| `GET /catalog/products` | Shop. Filters `category`, `q`, `in_stock=1`. Each has `price`, `in_stock`, `available`, `size`, `description`, `usage`, `image_url`, `hue`. |
| `GET /catalog/products/{id}` | |
| `GET /catalog/news` / `{id}` | Published news for the Home screen (paginated). |
| `GET /catalog/courses` / `{id}` | Academy catalogue with `teachers` and `module_titles`. Filter `level`. |

Example doctor:
```json
{ "id": 1, "name": "Dr. Meron Alemu", "specialty": "Dermatology", "bio": "…", "years_experience": 9,
  "working_days": "Mon – Sat", "availability": { "value": "available", "label": "Available" },
  "branch": { "id": 1, "name": "Addis Ababa" }, "photo_url": null, "hue": 185, "rating": 4.9, "reviews_count": 12,
  "services": [ { "id": 1, "name": "Signature HydraFacial", "price": 4500, "duration_minutes": 60, "icon": "spa", "is_online": false } ] }
```

---

## 4. Client app (`/client/*`, client token)

### Appointments
| | |
|---|---|
| `GET /client/appointments?tab=upcoming|requests|past` | No tab = all, newest first. |
| `POST /client/appointments` | `{ service_id, date, time, doctor_id?, branch_id?, note? }` |
| `GET /client/appointments/{id}` | |
| `POST /client/appointments/{id}/cancel` | Pending or approved, before it starts. |
| `POST /client/appointments/{id}/review` | `{ rating 1-5, comment? }` once, after completion. |

Booking rules (server enforced):
- `time` must be one of `booking_slots`; at least 60 min ahead; within 60 days.
- With `doctor_id`: the doctor must offer the service and be free (`slot_taken` otherwise).
- Without `doctor_id` (“Let reception choose”): clinic visits need `branch_id`.
- Online consultation = the service with `is_online: true` (45 min, ETB 4,000). No branch. `doctor_id` optional.
- A booking is created **pending** with an **unpaid payment** (`appointment.payment`).

Appointment fields: `status`, `is_online`, `service`, `doctor` (null until assigned), `branch`, `date`, `time`, `starts_at`, `ends_at`, `duration_minutes`, `price`, `note`, `payment`, `video_link`, `video_link_locked`, `decline_reason`, `can_cancel`, `can_review`.

**Online session video link:** `video_link` is `null` until the session is approved **and** paid. When it is approved but unpaid, `video_link_locked` is `true` → show “Pay to get your video link”.

### Payments (Chapa: Telebirr, CBE Birr, card)
| | |
|---|---|
| `GET /client/payments` | All payments + `meta.outstanding`, `meta.paid`. |
| `GET /client/payments/{id}` | |
| `POST /client/payments/{id}/checkout` | `{ method: "telebirr"|"cbe_birr"|"card" }` → payment with `status: "pending"` and `checkout_url`. |
| `POST /client/payments/{id}/verify` | Re-checks with Chapa; returns the payment (`status: "paid"` when done). |

Flow: checkout → open `checkout_url` in a WebView → when the WebView reaches the return URL (`…/payment-complete?tx_ref=…`) or the user closes it → call **verify** → show the receipt (`method`, `provider_reference`, `paid_at`). The server also confirms by itself through Chapa's webhook, so a later `GET` will be right even if the app was closed.

**Development:** `CHAPA_MODE=fake` → `checkout_url` is a local test page that marks the payment paid. No real money.

“Pay at reception” = simply don't call checkout; reception records cash in the web portal. Not offered for online sessions.

### Shop
The cart lives on the phone. At checkout:

`POST /client/orders`
```json
{ "items": [ { "product_id": 1, "quantity": 1 }, { "product_id": 3, "quantity": 2 } ],
  "delivery_address": "Bole, around Edna Mall, Addis Ababa", "delivery_note": null, "contact_phone": null,
  "payment_method": "pay_on_delivery", "prescription_id": null }
```
The server re-prices, checks stock (`insufficient_stock` with `details.product_id`, `details.available`), adds the delivery fee and returns the order. For `telebirr|cbe_birr|card` then call `POST /client/payments/{order.payment.id}/checkout`.

| | |
|---|---|
| `GET /client/orders` / `{id}` | Order with `items`, totals, `payment`, `courier`, and `timeline` — the six tracker steps with `at` times (`null` = not reached). |
| `POST /client/products/{id}/notify-me` | “Notify me” on an out-of-stock product. |

### Prescriptions
`GET /client/prescriptions`, `GET /client/prescriptions/{id}` — items are either a Lavish `product` (with `in_stock`) or an outside medicine (`is_custom: true`, `custom_name`). “Order all items”: put the in-stock products in the cart and send `prescription_id` at checkout.

### Complaints
| | |
|---|---|
| `GET /client/complaints` / `{id}` | With `messages` (thread with client care), `latest_reply`, `attachments`, `can_give_feedback`. |
| `POST /client/complaints` | multipart: `category` (Treatment|Appointment|Order|Staff|App|Other), `subject`, `message`, `priority?` (low|medium|high), `appointment_id?`, `order_id?`, `photos[]?` (≤4 images, 5 MB). |
| `POST /client/complaints/{id}/messages` | `{ body }` |
| `POST /client/complaints/{id}/feedback` | `{ rating 1-5, comment? }` after it's resolved. |

### Chat with doctors
| | |
|---|---|
| `GET /client/chats` | Every doctor the client was booked with: `doctor`, `locked`, `conversation_id` (null if never opened), `last_message`, `unread`. |
| `POST /client/chats/{doctor_id}` | Opens the conversation → conversation object. `403 chat_locked` before the first completed treatment with that doctor. |

Messages themselves: §6.

---

## 5. Student app (`/student/*`, student token)

| | |
|---|---|
| `GET /student/enrollments` | Courses with `progress`, `current_week`, `status`, `paid`, `certificate`, `conversation_id`. |
| `GET /student/enrollments/{id}` | “My course”: `enrollment`, published `modules` (each with `video`, `exercise`, and my `submission`), `upcoming_sessions`. |
| `GET /student/modules/{id}` | Lesson screen. |
| `POST /student/modules/{id}/submission` | multipart `body` and/or `files[]` (≤4, 10 MB, pdf/doc/docx/jpg/png/mp4/mov). Resubmitting replaces it unless graded. |
| `GET /student/sessions` | Upcoming classes of all my active courses. |
| `GET /student/enrollments/{id}/certificate` | Certificate details (number, names, issued_at). |

Chat with teachers: the enrolment's `conversation_id` with the `/conversations` endpoints.

---

## 6. Messages and notifications (any signed-in account)

### Conversations
| | |
|---|---|
| `GET /conversations?type=client_doctor|student_teacher|group|direct` | My conversations with `title` (the other side's name), `last_message`, `unread`, `locked`. |
| `GET /conversations/{id}` | |
| `GET /conversations/{id}/messages` | Newest first, 30 per call. `?before_id=` older (scroll up). `?after_id=` only newer (polling). |
| `POST /conversations/{id}/messages` | `{ body }` or multipart `body` + `files[]` (images/PDF). Returns the message (`mine: true`). |
| `POST /conversations/{id}/read` | Mark as read (call when the chat screen is open). |

Message: `{ id, conversation_id, body, mine, sender: { id, name, type }, attachments: [ { url, name, mime, size, is_image } ], created_at }`.

**Real time:** while a chat screen is open, poll `GET …/messages?after_id=<last id>` every 3–5 s. Push notifications (`type: message.new`) tell the app to refresh otherwise. (WebSockets can be added later without changing these endpoints.)

### Notifications (in-app inbox + push)
| | |
|---|---|
| `GET /notifications[?unread=1]` | Paginated; `meta.unread` is the badge count. |
| `GET /notifications/unread-count` | |
| `POST /notifications/{id}/read` / `POST /notifications/read-all` | |

Item:
```json
{ "id": "9c1…", "type": "appointment.approved", "title": "Appointment approved",
  "body": "Signature HydraFacial on Sun 9 Nov, 10:00 is confirmed. Dr. Meron Alemu has been notified.",
  "icon": "event_available", "target": { "screen": "appointment", "id": 202 }, "path": null,
  "read": false, "created_at": "…" }
```
`target.screen` → where to navigate in the app: `appointment`, `order`, `prescription`, `complaint`, `chat` (id = doctor id), `product`, `lesson` (module id), `my-course` (course id), `certificate` (enrolment id), `s-chat` (conversation id). `path` is for the web portal.

Push payload (FCM `data`, all strings): `type`, `screen`, `id`, `path`. *The FCM sender is not connected yet (needs the Firebase project); everything already reaches the app through `GET /notifications`.*

Notification `type`s a client/student can receive: `appointment.approved`, `appointment.online_confirmed`, `appointment.declined`, `appointment.completed`, `payment.received`, `prescription.new`, `order.placed|confirmed|preparing|ready|out|delivered|cancelled`, `complaint.reply`, `complaint.resolved`, `product.back_in_stock`, `message.new`, `academy.lesson`, `academy.session`, `academy.graded`, `academy.certificate`.

---

## 7. Web portal (`/staff/*`, staff token)

Every route also needs a permission (shown in the table). `GET /auth/me` returns for staff: `roles`, `portal`, `level`, `permissions[]` and `pages[]` (the sidebar). Hide buttons with `permissions`, but the server checks every call anyway.

| Area | Endpoints (permission) |
|---|---|
| Dashboard | `GET staff/dashboard[?portal=teacher]` — stats/panels per portal · `GET staff/search?q=` |
| Appointments | `GET appointments` (page.appointments) filters `status, online, unassigned, doctor_id, client_id, from, to, q, sort` · `GET appointments/classes?from&to` · `GET appointments/{id}` · `GET appointments/{id}/check` (clashes, day plan, ranked doctor suggestions) · `POST appointments/{id}/approve {doctor_id?}` · `POST …/decline {reason?}` · `POST appointments/walk-in` (appointments.approve) · `POST …/complete` (appointments.complete, treating doctor) |
| Clients | `GET clients` filters `q, has_app, treated, doctor_id` · `GET clients/{id}` (history, appointments, payments, orders, prescriptions) · `PATCH clients/{id}` · `PUT clients/{id}/history` (clients.history) · `POST clients/{id}/invite` (clients.invite) |
| Payments | `GET payments` (page.payments) + `meta.totals` · `POST payments/{id}/record {method}` · `POST …/refund` · `POST …/verify` (payments.collect) |
| Products | `GET products`, `products/{id}`, `products/movements` · `POST products`, `PATCH`, `DELETE`, `POST …/image`, `…/receive {branch_id, quantity, reference}`, `…/adjust {branch_id, counted, reason}` (products.edit) · `POST …/transfer {from_branch_id, to_branch_id, quantity}` (products.transfer) |
| Orders | `GET orders` filters `status (or "open"), payment, q` · `GET orders/{id}` (history + emails) · `POST orders/{id}/advance {courier?}` → `meta.low_stock` · `…/cancel {reason}` · `…/mark-paid {method}` · `…/resend-email` (orders.deliver) |
| Prescriptions | `GET prescriptions` (doctors: own) filters `status, client_id, waiting` · `POST prescriptions` · `POST prescription-items/{id}/suggest` (prescriptions.create) · `POST prescriptions/{id}/fulfil` (prescriptions.fulfil) |
| Complaints | `GET complaints`, `{id}` · `POST complaints/{id}/reply {body, status?}` · `…/status {status}` (complaints.manage) |
| Academy | `GET academy/courses`, `academy/enrollments` (page.academy) · `POST academy/courses`, `PATCH …/{id}`, `PUT …/{id}/teachers {doctor_ids}`, `POST …/{id}/modules {title}`, `PATCH/DELETE academy/modules/{id}` (academy.paths) · `POST academy/enrollments` → one-time `credentials`, `POST academy/students/{user}/reset-password {send_sms}`, `POST academy/enrollments/{id}/mark-paid` (academy.students) · `POST academy/enrollments/{id}/certificate` (academy.certificates) |
| Teaching | `GET teaching/courses`, `PUT teaching/modules/{id} {video, exercise}`, `POST …/publish {published}`, `GET/POST teaching/sessions`, `DELETE teaching/sessions/{id}`, `GET teaching/students`, `PUT teaching/enrollments/{id}/progress {progress}`, `GET teaching/submissions`, `POST teaching/submissions/{id}/grade {status, grade, feedback}` (academy.teach) |
| Staff | `GET members` (page.staff) + `meta.creatable_roles` · `POST members` → one-time `temporary_password` · `PATCH members/{id}` · `POST members/{id}/status {status}` · `POST members/{id}/reset-password` · `POST doctors/{id}/access {access}` · `PATCH doctors/{id}` (availability/bio by the doctor; more by managers) |
| Permissions | `GET permissions` (matrix + `editable` per role) · `PUT permissions/{role}/{permission} {granted}` · `POST permissions/reset` (permissions.manage) |
| News | `GET news` · `POST news` (multipart image), `POST news/{id}` (update), `DELETE news/{id}` (news.publish) |
| Lavish Team | `GET team/people`, `team/announcements`, `team/groups` · `POST team/announcements {title, body, group_id?}` / `DELETE` (team.announce) · `POST/PATCH/DELETE team/groups` (team.groups) · `POST team/announcements/{id}/read` · `POST team/direct/{user}` (page.team) |

---

## 8. Business error codes

`slot_taken`, `too_soon`, `too_far`, `invalid_slot`, `in_the_past`, `branch_required`, `doctor_unavailable`, `doctor_not_offering`, `client_double_booked`, `not_cancellable`, `cannot_review`,
`not_pending`, `doctor_required`, `doctor_busy` (`details.clashes`), `not_open`, `not_approved`, `not_your_patient`,
`payment_not_open`, `method_not_online`, `payment_init_failed`, `payments_unavailable`, `payment_not_refundable`,
`insufficient_stock` (`details.product_id`, `details.available`), `product_unavailable`, `empty_order`, `prescription_unavailable`, `order_closed`, `courier_required`, `already_ordered`, `nothing_to_order`, `insufficient_branch_stock`, `same_branch`, `invalid_quantity`,
`nothing_to_suggest`, `still_out_of_stock`, `client_not_on_app`, `not_your_prescription`,
`chat_locked`, `not_a_member`, `chat_self`, `not_resolved`,
`otp_invalid`, `otp_expired`, `otp_locked`, `otp_too_soon`, `phone_taken`, `email_taken`, `already_verified`, `phone_in_use`,
`already_enrolled`, `not_a_student`, `not_your_course`, `teacher_busy` (`details.clashes`), `exercise_unavailable`, `already_graded`, `not_completed`, `already_issued`,
`cannot_grant`, `cannot_create_role`, `hierarchy`.

---

## 9. Enum values

| Field | Values |
|---|---|
| user.type | `staff`, `client`, `student` |
| user.status | `active`, `pending_verification`, `suspended` |
| appointment.status | `pending`, `approved`, `declined`, `cancelled`, `completed` |
| appointment.source | `app`, `walk_in`, `phone_call`, `referral` |
| payment.status | `unpaid`, `pending`, `paid`, `failed`, `refund_due`, `refunded`, `cancelled` |
| payment.method | `cash`, `telebirr`, `cbe_birr`, `card`, `bank_transfer` |
| order.status | `placed` → `confirmed` → `preparing` → `ready` → `out` → `delivered`; or `cancelled` |
| order.payment_method | `pay_on_delivery`, `telebirr`, `cbe_birr`, `card` |
| prescription.status | `sent`, `ordered` |
| complaint.status / priority | `open`, `in_review`, `resolved` / `low`, `medium`, `high` |
| doctor.availability | `available`, `in_surgery`, `off_today`, `on_leave` |
| doctor.access (staff only) | `pending`, `active`, `banned` |
| enrollment.status | `active`, `completed`, `withdrawn` |
| submission.status | `submitted`, `graded`, `returned` |
| conversation.type | `client_doctor`, `student_teacher`, `group`, `direct` |

---

## 10. Screen → endpoint map (mobile prototype)

| Screen | Calls |
|---|---|
| Splash | token saved? `GET /auth/me` (401 → Welcome) · `GET /catalog/config` |
| Register / OTP | `POST /auth/register` → `POST /auth/register/verify` (resend: `/auth/otp/resend`) |
| Login / Set password | `POST /auth/login` → if `must_change_password` → `POST /auth/password/change` |
| Home | `GET /client/appointments?tab=upcoming`, `?tab=requests`, `GET /client/prescriptions`, `GET /catalog/news`, `GET /catalog/services`, `GET /notifications/unread-count` |
| Doctors / Doctor | `GET /catalog/doctors[?service_id]`, `GET /catalog/doctors/{id}` |
| Book | `GET /catalog/availability` on every date change |
| Book review / success | `POST /client/appointments` (+ checkout if “pay now”) |
| Appointments / detail | `GET /client/appointments?tab=…`, `GET /client/appointments/{id}`, cancel, review |
| Pay / receipt | `POST /client/payments/{id}/checkout` → WebView → `POST …/verify` |
| Payments | `GET /client/payments` |
| Chats / Chat room | `GET /client/chats`, `POST /client/chats/{doctor}`, `/conversations/{id}/messages` |
| Shop / product / cart / checkout | `GET /catalog/products`, `GET /catalog/products/{id}`, `POST /client/orders` |
| Orders / tracking | `GET /client/orders`, `GET /client/orders/{id}` (`timeline`) |
| Prescriptions | `GET /client/prescriptions[/id]` |
| Complaints | `GET/POST /client/complaints`, `GET …/{id}`, messages, feedback |
| Notifications | `GET /notifications`, `POST /notifications/read-all` |
| Profile | `GET/PATCH /auth/me`, avatar, logout |
| Student home / My course / Lesson / Certificate | `GET /student/enrollments`, `/student/enrollments/{id}`, `/student/modules/{id}`, `POST …/submission`, `/student/enrollments/{id}/certificate` |
| Courses / course | `GET /catalog/courses[/id]` |
| Teacher chat | `/conversations/{enrollment.conversation_id}/messages` |

---

## 11. Demo accounts (password `password` for all)

| Who | Login | App |
|---|---|---|
| Tigist Worku — client with history, chats, orders | `0911223344` | mobile |
| Mulu Desta — client, no treatment yet (chat locked) | `0933447788` | mobile |
| Eden Fikru — student (activated, certificate) | `0912456789` | mobile |
| Kidist Amare — student, must set password | `0914882107` | mobile |
| Dr. Melat Solomon — CEO | `0911001122` / `ceo@lavish.et` | web |
| Dawit Haile — General Manager | `0911009900` / `gm@lavish.et` | web |
| Hana Tadesse — Reception | `0911003344` / `reception@lavish.et` | web |
| Abel Girma — E-commerce | `0911112233` / `shop@lavish.et` | web |
| Dr. Meron Alemu — Doctor + Academy teacher | `0911500001` / `doctor@lavish.et` | web |
| Selam Getahun — Nurse (Lavish Team only) | `0911445566` | web |

SMS codes in development: `123456`. Test payments: the fake Chapa checkout page.
