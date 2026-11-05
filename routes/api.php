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
            Route::middleware('permission:appointments.approve')->group(function () {
                Route::post('appointments/walk-in', 'walkIn');
                Route::get('appointments/{appointment}/check', 'check');
                Route::post('appointments/{appointment}/approve', 'approve');
                Route::post('appointments/{appointment}/decline', 'decline');
            });
            Route::post('appointments/{appointment}/complete', 'complete')->middleware('permission:appointments.complete');
        });

        Route::controller(Staff\ClientController::class)->group(function () {
            Route::get('clients', 'index')->middleware('permission:page.clients|appointments.approve');
            Route::get('clients/{client}', 'show')->middleware('permission:page.clients|appointments.approve');
            Route::patch('clients/{client}', 'update')->middleware('permission:clients.history');
            Route::put('clients/{client}/history', 'updateHistory')->middleware('permission:clients.history');
            Route::post('clients/{client}/invite', 'invite')->middleware('permission:clients.invite');
        });

        Route::controller(Staff\PaymentController::class)->group(function () {
            Route::get('payments', 'index')->middleware('permission:page.payments');
            Route::post('payments/{payment}/record', 'record')->middleware('permission:payments.collect');
            Route::post('payments/{payment}/refund', 'refund')->middleware('permission:payments.collect');
            Route::post('payments/{payment}/verify', 'verify')->middleware('permission:payments.collect');
        });

        Route::controller(Staff\ProductController::class)->group(function () {
            Route::middleware('permission:page.products|page.orders|page.prescriptions')->group(function () {
                Route::get('products', 'index');
                Route::get('products/movements', 'movements');
                Route::get('products/{product}', 'show');
            });
            Route::middleware('permission:products.edit')->group(function () {
                Route::post('products', 'store');
                Route::patch('products/{product}', 'update');
                Route::delete('products/{product}', 'destroy');
                Route::post('products/{product}/image', 'uploadImage');
                Route::post('products/{product}/receive', 'receive');
                Route::post('products/{product}/adjust', 'adjust');
            });
            Route::post('products/{product}/transfer', 'transfer')->middleware('permission:products.transfer');
        });

        Route::controller(Staff\OrderController::class)->group(function () {
            Route::get('orders', 'index')->middleware('permission:page.orders');
            Route::get('orders/{order}', 'show')->middleware('permission:page.orders');
            Route::middleware('permission:orders.deliver')->group(function () {
                Route::post('orders/{order}/advance', 'advance');
                Route::post('orders/{order}/cancel', 'cancel');
                Route::post('orders/{order}/mark-paid', 'markPaid');
                Route::post('orders/{order}/resend-email', 'resendEmail');
            });
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
            Route::post('complaints/{complaint}/reply', 'reply')->middleware('permission:complaints.manage');
            Route::post('complaints/{complaint}/status', 'status')->middleware('permission:complaints.manage');
        });

        Route::prefix('academy')->controller(Staff\AcademyController::class)->group(function () {
            Route::middleware('permission:page.academy')->group(function () {
                Route::get('courses', 'courses');
                Route::get('enrollments', 'enrollments');
            });
            Route::middleware('permission:academy.paths')->group(function () {
                Route::post('courses', 'storeCourse');
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
            Route::post('members', 'store')->middleware('permission:staff.add');
            Route::patch('members/{member}', 'update')->middleware('permission:staff.add');
            Route::post('members/{member}/status', 'setStatus')->middleware('permission:staff.add|staff.suspend');
            Route::post('members/{member}/reset-password', 'resetPassword')->middleware('permission:staff.add');
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
            Route::middleware('permission:news.publish')->group(function () {
                Route::post('news', 'store');
                Route::post('news/{news}', 'update'); // POST so images can be sent as multipart
                Route::delete('news/{news}', 'destroy');
            });
        });

        Route::prefix('team')->middleware('permission:page.team')->controller(Staff\TeamController::class)->group(function () {
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
