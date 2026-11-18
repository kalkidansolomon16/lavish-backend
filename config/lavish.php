<?php

/*
| Lavish business settings. Values that differ per environment come from .env.
*/
return [

    'currency' => 'ETB',

    // Shop delivery fee in whole ETB, added to every order.
    'delivery_fee' => (int) env('LAVISH_DELIVERY_FEE', 150),

    // Phone number students call to enrol (shown in the app; there is no student sign-up).
    // Every Lavish Academy course lasts this many weeks (one module per week).
    'course_weeks' => 5,

    'academy_phone' => env('LAVISH_ACADEMY_PHONE', '+251911001122'),

    // Link sent to clients by SMS so they can download the app.
    'app_download_url' => env('LAVISH_APP_URL', 'https://lavish.et/app'),

    'booking' => [
        // Start times offered to clients and reception (clinic hours, lunch break excluded).
        'slots' => ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'],
        // How far ahead clients may book.
        'horizon_days' => 60,
        // Minimum notice for a booking made in the app.
        'min_lead_minutes' => 60,
        // Default length of an academy class (blocks the teacher doctor's calendar).
        'class_minutes' => 120,
    ],

    // Online consultations: a video link is generated when reception confirms the session.
    'online' => [
        'video_base_url' => env('LAVISH_VIDEO_BASE_URL', 'https://meet.jit.si'),
        'room_prefix' => 'Lavish',
    ],

    // Sanctum token lifetimes.
    'tokens' => [
        'web_hours' => (int) env('LAVISH_WEB_TOKEN_HOURS', 12),
        'mobile_days' => (int) env('LAVISH_MOBILE_TOKEN_DAYS', 90),
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_seconds' => 60,
        // In local/testing with the log SMS driver this fixed code is accepted, so the mobile developer can test.
        'dev_code' => env('LAVISH_DEV_OTP', '123456'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | afromessage
        'afromessage' => [
            'base_url' => env('AFROMESSAGE_BASE_URL', 'https://api.afromessage.com/api'),
            'token' => env('AFROMESSAGE_TOKEN'),
            'identifier_id' => env('AFROMESSAGE_IDENTIFIER_ID'),
            'sender' => env('AFROMESSAGE_SENDER', 'Lavish'),
        ],
    ],

    'push' => [
        // log = write to the log only (development); fcm = real phone notifications through Firebase.
        'driver' => env('PUSH_DRIVER', 'log'),
        // Firebase service-account key (Project settings → Service accounts → Generate new private key).
        'fcm_credentials' => env('FIREBASE_CREDENTIALS', 'storage/app/firebase.json'),
    ],

    // While online payment is off, clients pay by any of these and upload the receipt in the app;
    // reception accepts it in Web → Payments. Fill in the real account details in .env.
    'payments' => [
        'online_enabled' => (bool) env('ONLINE_PAYMENTS_ENABLED', false), // true = Chapa checkout in the app
        'instructions' => array_values(array_filter([
            env('PAY_TELEBIRR') ? ['method' => 'telebirr', 'label' => 'Telebirr', 'details' => env('PAY_TELEBIRR')] : null,
            env('PAY_CBE') ? ['method' => 'cbe_birr', 'label' => 'CBE account', 'details' => env('PAY_CBE')] : null,
            env('PAY_BANK') ? ['method' => 'bank_transfer', 'label' => 'Bank transfer', 'details' => env('PAY_BANK')] : null,
            ['method' => 'cash', 'label' => 'Cash at reception', 'details' => 'Pay at any Lavish branch front desk.'],
        ])),
    ],

    'chapa' => [
        // fake: no money moves — checkout_url points at a local page that marks the payment paid (local/testing only).
        // live: real Chapa API (use test keys from the Chapa dashboard while testing).
        'mode' => env('CHAPA_MODE', 'fake'),
        'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'),
        'secret_key' => env('CHAPA_SECRET_KEY'),
        'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
        // Where the client's browser is sent after paying (the app can intercept this deep link).
        'return_url' => env('CHAPA_RETURN_URL', 'https://lavish.et/payment-complete'),
    ],

];
