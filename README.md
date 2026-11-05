# Lavish — Backend API (Laravel 12)

One API for both Lavish apps:

- **Web staff portal** (`lavish_front`, Vue 3 + Tailwind) — CEO, General Manager, Reception, E-commerce, Doctors, Academy teachers, Lavish Team.
- **Mobile app** (Flutter) — clients (patients) and academy students.

👉 **Mobile / web developers: read [`docs/API.md`](docs/API.md).** It lists every endpoint, the request and response shapes, error codes and the screen-by-screen flows.

## Requirements

PHP 8.2+, Composer, MySQL 8 / MariaDB 10.4+ (XAMPP works), Node is not needed for the API.

## Setup (local)

```bash
composer install
cp .env.example .env          # then check DB_* (XAMPP MariaDB here runs on port 3307)
php artisan key:generate
# create the database once:  CREATE DATABASE lavish CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed    # roles/permissions + demo data (same people as the prototypes)
php artisan storage:link      # public URLs for uploaded photos
php artisan serve             # http://127.0.0.1:8000/api/v1
```

Demo logins (password `password`) are in `docs/API.md §11`. SMS codes are `123456` while `SMS_DRIVER=log`; payments use a fake Chapa checkout while `CHAPA_MODE=fake`.

Reset everything: `php artisan migrate:fresh --seed`.

### Tests

```bash
php artisan test     # 32 feature tests: auth, booking, online sessions, payments, shop & stock, permissions, academy, smoke test of every screen
```
Tests run on an in-memory SQLite database and never touch MySQL.

## Production checklist

1. `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, HTTPS.
2. `php artisan migrate --force` then `php artisan db:seed --class=RolePermissionSeeder --force` (no demo data in production) and `php artisan lavish:create-ceo`.
3. `LAVISH_CORS_ORIGINS=https://<portal domain>`.
4. SMS: `SMS_DRIVER=afromessage` + `AFROMESSAGE_*` keys (verify the request format with your AfroMessage account first).
5. Payments: `CHAPA_MODE=live`, `CHAPA_SECRET_KEY`, `CHAPA_WEBHOOK_SECRET`; set the webhook URL in the Chapa dashboard to `https://<api>/api/v1/webhooks/chapa`. Test with Chapa **test keys** before live keys.
6. Mail: configure `MAIL_*` (order status emails).
7. Queue worker + scheduler if you later move notifications to the queue.

## Architecture

```
app/
  Enums/                 every status/type value (mirrored in docs/API.md §9)
  Models/                Eloquent models (one table each)
  Services/              ALL business rules — controllers stay thin
    AppointmentService     booking, walk-in, approve/assign, decline, cancel, complete
    ScheduleService        one calendar per doctor (treatments + academy classes), free slots, doctor suggestions
    Payments/              PaymentService + ChapaGateway (Telebirr, CBE Birr, card)
    OrderService           checkout, delivery tracker, emails, cancel
    InventoryService       per-branch stock, receive / transfer / adjust / deduct, back-in-stock alerts
    PrescriptionService    prescriptions, restock suggestions
    ChatService            client↔doctor, student↔teachers, staff groups, direct messages
    AcademyService         enrolment & student logins, modules, classes, grading, certificates
    StaffService           staff accounts, doctor access, hierarchy
    PermissionService      role permissions with the hierarchy rule
    OtpService, Messenger (SMS/email + log), Notifier (who gets which notification)
  Http/
    Controllers/Api/V1/    Auth, Catalog, Conversation, Notification, PaymentWebhook
      Client/  Student/  Staff/
    Middleware/            type:staff|client|student · permission:key · password.changed
    Resources/             JSON shapes returned to the apps
config/lavish.php          business settings (slots, fees, OTP, SMS, Chapa…)
config/permissions.php     roles, permission catalogue, default grants (server copy of permissions.js)
routes/api.php             all routes, with the permission each one needs
```

Key decisions:
- **Bearer tokens (Sanctum) for web and mobile.** The portal is hosted on a different domain (Cloudflare), so cookies would be fragile; tokens work the same for both apps. Web tokens expire after 12 h, mobile after 90 days.
- **One `users` table, phone number as login.** `type` = staff/client/student. A client record can exist without an account (walk-ins); registering in the app with that phone links them.
- **Permissions are data.** Roles and the catalogue come from `config/permissions.php`; grants are edited in the web Permissions page and enforced on every route.
- **Money in whole ETB integers. Times in Africa/Addis_Ababa.**
- **Every business refusal is a 422 with a stable `code`** (e.g. `slot_taken`) so the apps can react without parsing messages.

## Not done yet (next steps)

- Push notifications through Firebase (FCM): needs the Firebase project & service-account file. Device tokens are already stored; notifications are already in `GET /notifications`.
- Live chat over WebSockets (Laravel Reverb). Polling with `after_id` works today.
- Certificate PDF file generation (data endpoint exists).
- Real SMS/Chapa credentials (drivers written; need keys and a test run).
