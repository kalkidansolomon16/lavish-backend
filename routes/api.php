<?php

/*
|--------------------------------------------------------------------------
| Lavish API v1 — every route below is prefixed with /api/v1
|--------------------------------------------------------------------------
| Audiences:
|   public            /auth/*, /catalog/*, /webhooks/*
|   any signed-in     /auth/me…, /notifications, /conversations
|   mobile — client   /client/*   (token of a client account)
|   mobile — student  /student/*  (token of a student account)
|   web — staff       /staff/*    (token of a staff account; each route also needs a permission)
| Full guide: docs/API.md
*/

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\Client;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Staff;
use App\Http\Controllers\Api\V1\Student\StudentController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:otp');
    Route::post('register/verify', [AuthController::class, 'verifyRegistration']);
    Route::post('otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:otp');
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});

Route::prefix('catalog')->controller(CatalogController::class)->group(function () {
    Route::get('config', 'config');
    Route::get('branches', 'branches');
    Route::get('services', 'services');
    Route::get('doctors', 'doctors');
    Route::get('doctors/{doctor}', 'doctor');
    Route::get('availability', 'availability');
    Route::get('products', 'products');
    Route::get('products/{product}', 'product');
    Route::get('news', 'news');
    Route::get('news/{news}', 'newsItem');
    Route::get('courses', 'courses');
    Route::get('courses/{course}', 'course');
});

Route::match(['get', 'post'], 'webhooks/chapa', [PaymentWebhookController::class, 'chapa'])->name('webhooks.chapa');
Route::get('payments/fake-checkout/{txRef}', [PaymentWebhookController::class, 'fakeCheckout'])->middleware('signed')->name('payments.fake-checkout');

// ---------------------------------------------------------------- any signed-in user
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::get('me', 'me');
        Route::post('password/change', 'changePassword')->middleware('throttle:auth');
        Route::post('logout', 'logout');
    });

    Route::middleware('password.changed')->group(function () {
        Route::prefix('auth')->controller(AuthController::class)->group(function () {
            Route::patch('me', 'updateMe');
            Route::post('me/avatar', 'uploadAvatar');
            Route::post('devices', 'registerDevice');
        });

        Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('unread-count', 'unreadCount');
            Route::post('read-all', 'markAllRead');
            Route::post('{id}/read', 'markRead');
        });

        Route::prefix('conversations')->controller(ConversationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('{conversation}', 'show');
            Route::get('{conversation}/messages', 'messages');
            Route::post('{conversation}/messages', 'send')->middleware('throttle:60,1');
            Route::post('{conversation}/read', 'read');
        });
    });

    // ------------------------------------------------------------ mobile — client
    Route::prefix('client')->middleware(['type:client', 'password.changed'])->group(function () {
        Route::get('appointments', [Client\AppointmentController::class, 'index']);
        Route::post('appointments', [Client\AppointmentController::class, 'store']);
        Route::get('appointments/{appointment}', [Client\AppointmentController::class, 'show']);
        Route::post('appointments/{appointment}/cancel', [Client\AppointmentController::class, 'cancel']);
        Route::post('appointments/{appointment}/change', [Client\AppointmentController::class, 'change']);
        Route::post('appointments/{appointment}/review', [Client\AppointmentController::class, 'review']);

        Route::get('payments', [Client\PaymentController::class, 'index']);
        Route::get('payments/{payment}', [Client\PaymentController::class, 'show']);
        Route::post('payments/{payment}/checkout', [Client\PaymentController::class, 'checkout']);
        Route::post('payments/{payment}/verify', [Client\PaymentController::class, 'verify']);

        Route::get('orders', [Client\OrderController::class, 'index']);
        Route::post('orders', [Client\OrderController::class, 'store']);
        Route::get('orders/{order}', [Client\OrderController::class, 'show']);
        Route::post('products/{product}/notify-me', [Client\OrderController::class, 'notifyMe']);

        Route::get('prescriptions', [Client\PrescriptionController::class, 'index']);
        Route::get('prescriptions/{prescription}', [Client\PrescriptionController::class, 'show']);

        Route::get('complaints', [Client\ComplaintController::class, 'index']);
        Route::post('complaints', [Client\ComplaintController::class, 'store']);
        Route::get('complaints/{complaint}', [Client\ComplaintController::class, 'show']);
        Route::post('complaints/{complaint}/messages', [Client\ComplaintController::class, 'message']);
        Route::post('complaints/{complaint}/feedback', [Client\ComplaintController::class, 'feedback']);

        Route::get('chats', [Client\ChatController::class, 'index']);
        Route::post('chats/{doctor}', [Client\ChatController::class, 'open']);

        // Checkout with Lavish Rewards vouchers and gift cards (then Chapa for the rest)
        Route::get('payments/{payment}/wallet', [Client\PaymentController::class, 'wallet']);
        Route::post('payments/{payment}/quote', [Client\PaymentController::class, 'quote']);
        Route::post('payments/{payment}/apply', [Client\PaymentController::class, 'apply']);
        Route::post('payments/{payment}/release', [Client\PaymentController::class, 'release']);
        Route::post('payments/{payment}/receipt', [Client\PaymentController::class, 'receipt'])->middleware('throttle:20,1');

        // Lavish Rewards
        Route::prefix('rewards')->controller(Client\RewardsController::class)->group(function () {
            Route::get('/', 'show');
            Route::post('join', 'join');
            Route::post('tier-seen', 'tierSeen');
            Route::get('ways-to-earn', 'waysToEarn');
            Route::get('catalog', 'catalog');
            Route::post('catalog/{reward}/redeem', 'redeem');
            Route::get('vouchers', 'vouchers');
            Route::get('history', 'history');
            Route::get('requests', 'requests');
            Route::post('requests', 'requestPoints')->middleware('throttle:20,1');
            Route::post('requests/{pointsRequest}/cancel', 'cancelRequest');
            Route::get('referrals', 'referrals');
            Route::post('referrals', 'useReferral');
            Route::get('estimate', 'estimate');
        });

        // Gift cards
        Route::prefix('gift-cards')->controller(Client\GiftCardController::class)->group(function () {
            Route::get('options', 'options');
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::post('claim', 'claim')->middleware('throttle:giftcode');
            Route::post('check', 'check')->middleware('throttle:giftcode');
            Route::get('{giftCard}', 'show');
        });
    });

    // ------------------------------------------------------------ mobile — student
    Route::prefix('student')->middleware(['type:student', 'password.changed'])->controller(StudentController::class)->group(function () {
        Route::get('enrollments', 'enrollments');
        Route::get('enrollments/{enrollment}', 'enrollment');
        Route::get('enrollments/{enrollment}/certificate', 'certificate');
        Route::get('modules/{module}', 'module');
        Route::post('modules/{module}/submission', 'submit');
        Route::get('sessions', 'sessions');
    });

    // ------------------------------------------------------------ web — staff
    Route::prefix('staff')->middleware(['type:staff', 'password.changed'])->group(function () {
        Route::get('dashboard', Staff\DashboardController::class);
        Route::get('search', Staff\SearchController::class);

        Route::controller(Staff\AppointmentController::class)->group(function () {
            Route::middleware('permission:page.appointments')->group(function () {
                Route::get('appointments', 'index');
                Route::get('appointments/classes', 'classes');
                Route::get('appointments/{appointment}', 'show');
            });
            Route::post('appointments/walk-in', 'walkIn')->middleware('permission:appointments.create');
            Route::middleware('permission:appointments.approve')->group(function () {
                Route::get('appointments/{appointment}/check', 'check');
                Route::post('appointments/{appointment}/approve', 'approve');
                Route::post('appointments/{appointment}/decline', 'decline');
                Route::post('appointments/{appointment}/request-change', 'requestChange');
            });
            Route::post('appointments/{appointment}/complete', 'complete')->middleware('permission:appointments.complete');
        });

        Route::controller(Staff\ClientController::class)->group(function () {
            Route::get('clients', 'index')->middleware('permission:page.clients|appointments.approve|appointments.create');
            Route::get('clients/{client}', 'show')->middleware('permission:page.clients|appointments.approve|appointments.create');
            Route::patch('clients/{client}', 'update')->middleware('permission:clients.edit');
            Route::put('clients/{client}/history', 'updateHistory')->middleware('permission:clients.history');
            Route::post('clients/{client}/invite', 'invite')->middleware('permission:clients.invite');
        });

        Route::controller(Staff\PaymentController::class)->group(function () {
            Route::get('payments', 'index')->middleware('permission:page.payments');
            Route::post('payments/{payment}/record', 'record')->middleware('permission:payments.collect');
            Route::post('payments/{payment}/refund', 'refund')->middleware('permission:payments.refund');
            Route::post('payments/{payment}/verify', 'verify')->middleware('permission:payments.collect');
            Route::middleware('permission:payments.collect')->group(function () {
                Route::get('payments/{payment}/wallet', 'wallet');
                Route::post('payments/{payment}/quote', 'quote');
                Route::post('payments/{payment}/checkout', 'checkout');
                Route::post('payments/{payment}/receipts/{receipt}/accept', 'acceptReceipt');
                Route::post('payments/{payment}/receipts/{receipt}/reject', 'rejectReceipt');
            });
        });

        // Lavish Rewards
        Route::prefix('rewards')->controller(Staff\RewardsController::class)->group(function () {
            Route::middleware('permission:page.rewards')->group(function () {
                Route::get('settings', 'settings');
                Route::get('members', 'members');
                Route::get('clients/{client}', 'client');
                Route::post('clients/{client}/join', 'join')->middleware('permission:rewards.enroll');
                Route::post('enrol', 'enrol')->middleware('permission:rewards.enroll');
                Route::post('clients/{client}/redeem/{reward}', 'redeem')->middleware('permission:payments.collect|rewards.manage');
            });
            Route::middleware('permission:rewards.manage')->group(function () {
                Route::put('settings', 'updateSettings');
                Route::put('tiers', 'updateTiers');
                Route::post('rules', 'saveRule');
                Route::patch('rules/{rule}', 'saveRule');
                Route::post('catalog', 'saveReward');
                Route::patch('catalog/{reward}', 'saveReward');
                Route::post('campaigns', 'saveCampaign');
                Route::patch('campaigns/{campaign}', 'saveCampaign');
                Route::get('audit', 'audit');
            });
            Route::post('clients/{client}/adjust', 'adjust')->middleware('permission:rewards.adjust');
            Route::get('reports', 'reports')->middleware('permission:finance.revenue');
        });

        // Members' requests to use points (reception; e-commerce for shop requests sent to them)
        Route::prefix('rewards/requests')->controller(Staff\PointsRequestController::class)->group(function () {
            Route::get('/', 'index')->middleware('permission:rewards.requests|orders.points');
            Route::get('{pointsRequest}', 'show')->middleware('permission:rewards.requests|orders.points');
            Route::post('{pointsRequest}/forward', 'forward')->middleware('permission:rewards.requests');
            Route::post('{pointsRequest}/complete', 'complete')->middleware('permission:rewards.requests|orders.points');
            Route::post('{pointsRequest}/reject', 'reject')->middleware('permission:rewards.requests|orders.points');
        });

        // Gift cards
        Route::prefix('gift-cards')->controller(Staff\GiftCardController::class)->group(function () {
            Route::get('/', 'index')->middleware('permission:page.giftcards');
            Route::post('/', 'store')->middleware('permission:giftcards.sell|giftcards.promo');
            Route::post('stock/generate', 'generateStock')->middleware('permission:giftcards.stock');
            Route::post('stock/import', 'importStock')->middleware('permission:giftcards.stock');
            Route::post('stock/sell', 'sellStock')->middleware(['permission:giftcards.sell', 'throttle:giftcode']);
            Route::post('lookup', 'lookup')->middleware(['permission:giftcards.sell|giftcards.activate|giftcards.use|payments.collect', 'throttle:giftcode']);
            Route::post('{giftCard}/activate', 'activate')->middleware('permission:giftcards.activate');
            Route::post('{giftCard}/use', 'useCard')->middleware('permission:giftcards.use');
            Route::get('{giftCard}', 'show')->middleware('permission:page.giftcards');
            Route::post('{giftCard}/void', 'void')->middleware('permission:giftcards.void');
        });

        // Treatments clients can book (prices, length, which doctors offer them)
        Route::controller(Staff\ServiceController::class)->group(function () {
            Route::get('services', 'index')->middleware('permission:page.treatments');
            Route::post('services', 'store')->middleware('permission:treatments.create');
            Route::patch('services/{service}', 'update')->middleware('permission:treatments.edit');
            Route::delete('services/{service}', 'destroy')->middleware('permission:treatments.delete');
        });

        Route::controller(Staff\ProductController::class)->group(function () {
            Route::middleware('permission:page.products|page.orders|page.prescriptions')->group(function () {
                Route::get('products', 'index');
                Route::get('products/movements', 'movements');
                Route::get('products/{product}', 'show');
            });
            Route::post('products', 'store')->middleware('permission:products.create');
            Route::patch('products/{product}', 'update')->middleware('permission:products.edit');
            Route::delete('products/{product}', 'destroy')->middleware('permission:products.delete');
            Route::post('products/{product}/image', 'uploadImage')->middleware('permission:products.create|products.edit');
            Route::post('products/{product}/receive', 'receive')->middleware('permission:products.stock');
            Route::post('products/{product}/adjust', 'adjust')->middleware('permission:products.stock');
            Route::post('products/{product}/transfer', 'transfer')->middleware('permission:products.transfer');
        });

        Route::controller(Staff\OrderController::class)->group(function () {
            Route::get('orders', 'index')->middleware('permission:page.orders');
            Route::get('orders/{order}', 'show')->middleware('permission:page.orders');
            Route::middleware('permission:orders.deliver')->group(function () {
                Route::post('orders/{order}/advance', 'advance');
                Route::post('orders/{order}/mark-paid', 'markPaid');
                Route::post('orders/{order}/resend-email', 'resendEmail');
            });
            Route::post('orders/{order}/cancel', 'cancel')->middleware('permission:orders.cancel');
        });

        Route::controller(Staff\PrescriptionController::class)->group(function () {
            Route::get('prescriptions', 'index')->middleware('permission:page.prescriptions');
            Route::post('prescriptions', 'store')->middleware('permission:prescriptions.create');
            Route::post('prescription-items/{item}/suggest', 'suggest')->middleware('permission:prescriptions.create');
            Route::post('prescriptions/{prescription}/fulfil', 'fulfil')->middleware('permission:prescriptions.fulfil');
        });

        Route::controller(Staff\ComplaintController::class)->group(function () {
            Route::get('complaints', 'index')->middleware('permission:page.complaints');
            Route::get('complaints/{complaint}', 'show')->middleware('permission:page.complaints');
            Route::post('complaints/{complaint}/reply', 'reply')->middleware('permission:complaints.reply');
            Route::post('complaints/{complaint}/status', 'status')->middleware('permission:complaints.resolve');
        });

        Route::prefix('academy')->controller(Staff\AcademyController::class)->group(function () {
            Route::middleware('permission:page.academy')->group(function () {
                Route::get('courses', 'courses');
                Route::get('enrollments', 'enrollments');
            });
            Route::post('courses', 'storeCourse')->middleware('permission:academy.create');
            Route::middleware('permission:academy.paths')->group(function () {
                Route::patch('courses/{course}', 'updateCourse');
                Route::put('courses/{course}/teachers', 'setTeachers');
                Route::post('courses/{course}/modules', 'addModule');
                Route::patch('modules/{module}', 'updateModule');
                Route::delete('modules/{module}', 'destroyModule');
            });
            Route::middleware('permission:academy.students')->group(function () {
                Route::post('enrollments', 'enroll');
                Route::post('enrollments/{enrollment}/mark-paid', 'markPaid');
                Route::post('students/{student}/reset-password', 'resetStudentPassword');
            });
            Route::post('enrollments/{enrollment}/certificate', 'issueCertificate')->middleware('permission:academy.certificates');
        });

        Route::prefix('teaching')->middleware('permission:academy.teach')->controller(Staff\TeachingController::class)->group(function () {
            Route::get('courses', 'courses');
            Route::put('modules/{module}', 'updateModule');
            Route::post('modules/{module}/publish', 'publishModule');
            Route::get('sessions', 'sessions');
            Route::post('sessions', 'storeSession');
            Route::delete('sessions/{session}', 'destroySession');
            Route::get('students', 'students');
            Route::put('enrollments/{enrollment}/progress', 'setProgress');
            Route::get('submissions', 'submissions');
            Route::post('submissions/{submission}/grade', 'grade');
        });

        Route::controller(Staff\StaffMemberController::class)->group(function () {
            Route::get('members', 'index')->middleware('permission:page.staff');
            Route::post('members', 'store')->middleware('permission:staff.create');
            Route::patch('members/{member}', 'update')->middleware('permission:staff.edit');
            Route::post('members/{member}/status', 'setStatus')->middleware('permission:staff.edit|staff.suspend');
            Route::post('members/{member}/reset-password', 'resetPassword')->middleware('permission:staff.password');
            Route::post('doctors/{doctor}/access', 'setDoctorAccess')->middleware('permission:doctors.grant|doctors.ban');
            Route::patch('doctors/{doctor}', 'updateDoctor'); // managers, or the doctor themself (checked inside)
        });

        Route::controller(Staff\PermissionController::class)->group(function () {
            Route::get('permissions', 'index')->middleware('permission:page.permissions');
            // withoutScopedBindings: look the permission up in the whole catalogue, not only among the role's current grants
            Route::put('permissions/{role:key}/{permission:key}', 'update')->middleware('permission:permissions.manage')->withoutScopedBindings();
            Route::post('permissions/reset', 'reset')->middleware('permission:permissions.manage');
        });

        Route::controller(Staff\NewsController::class)->group(function () {
            Route::get('news', 'index')->middleware('permission:page.news');
            Route::post('news', 'store')->middleware('permission:news.create');
            Route::post('news/{news}', 'update')->middleware('permission:news.edit'); // POST so images can be sent as multipart
            Route::delete('news/{news}', 'destroy')->middleware('permission:news.delete');
        });

        Route::prefix('team')->controller(Staff\TeamController::class)->group(function () {
            Route::get('people', 'people');
            Route::get('announcements', 'announcements');
            Route::post('announcements', 'announce')->middleware('permission:team.announce');
            Route::post('announcements/{announcement}/read', 'readAnnouncement');
            Route::delete('announcements/{announcement}', 'deleteAnnouncement')->middleware('permission:team.announce');
            Route::get('groups', 'groups');
            Route::post('groups', 'createGroup')->middleware('permission:team.groups');
            Route::patch('groups/{group}', 'updateGroup')->middleware('permission:team.groups');
            Route::delete('groups/{group}', 'deleteGroup')->middleware('permission:team.groups');
            Route::post('direct/{user}', 'direct');
        });
    });
});
