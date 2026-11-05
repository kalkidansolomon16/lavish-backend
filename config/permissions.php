<?php

/*
| Staff roles, the permission catalogue and the starting grants.
| This is the server-side copy of lavish_front/src/permissions.js and is the source of truth:
| `php artisan db:seed --class=RolePermissionSeeder` syncs it into the roles / permissions tables.
| Grants changed later in the web Permissions page live in the database (permission_role).
*/

$catalogue = [
    'Pages' => [
        'page.appointments' => ['Appointments'],
        'page.payments' => ['Payments'],
        'page.clients' => ['Clients'],
        'page.products' => ['Products & inventory'],
        'page.orders' => ['Orders'],
        'page.prescriptions' => ['Prescriptions'],
        'page.complaints' => ['Complaints'],
        'page.academy' => ['Academy'],
        'page.staff' => ['Staff'],
        'page.news' => ['News'],
        'page.permissions' => ['Permissions'],
        'page.team' => ['Lavish Team (announcements, groups & staff chat)'],
        'page.chat' => ['Patient chat', ['doctor']],
        'page.schedule' => ['Class schedule', ['teacher']],
        'page.modules' => ['Modules', ['teacher']],
        'page.students' => ['My students', ['teacher']],
    ],
    'Appointments & clients' => [
        'appointments.approve' => ['Approve, assign & decline bookings · walk-in / phone booking'],
        'appointments.complete' => ['Mark treatments as done', ['doctor']],
        'clients.history' => ['Record & edit client medical history'],
        'clients.invite' => ['Send app links & mark clients registered'],
    ],
    'Money' => [
        'finance.revenue' => ['See revenue, prices, client spend & stock value'],
        'payments.collect' => ['Collect & record treatment payments'],
    ],
    'Products & orders' => [
        'products.edit' => ['Add products, set prices, receive & adjust stock'],
        'products.transfer' => ['Transfer stock between branches'],
        'orders.deliver' => ['Update order status (confirm → prepare → deliver), cancel & mark paid'],
    ],
    'Prescriptions' => [
        'prescriptions.create' => ['Write prescriptions & suggest restocked products', ['doctor']],
        'prescriptions.fulfil' => ['Turn prescriptions into orders'],
    ],
    'Complaints & news' => [
        'complaints.manage' => ['Reply to & resolve complaints'],
        'news.publish' => ['Post, publish & delete news'],
    ],
    'Academy' => [
        'academy.paths' => ['Edit learning paths & teacher access'],
        'academy.students' => ['Enrol students & reset their app passwords'],
        'academy.certificates' => ['Issue certificates'],
        'academy.teach' => ['Teach: lessons, classes, grading & student chat', ['teacher']],
    ],
    'Staff & access' => [
        'staff.add' => ['Add staff & reactivate accounts'],
        'staff.suspend' => ['Suspend staff accounts'],
        'doctors.grant' => ['Give doctors portal access'],
        'doctors.ban' => ['Ban & reinstate doctors'],
        'permissions.manage' => ['Change permissions of lower roles'],
    ],
    'Lavish Team' => [
        'team.announce' => ['Send announcements to all staff or a group'],
        'team.groups' => ['Create staff groups & manage their members'],
    ],
];

$applies = function (string $key, string $role) use ($catalogue): bool {
    foreach ($catalogue as $items) {
        if (isset($items[$key])) {
            return empty($items[$key][1]) || in_array($role, $items[$key][1], true);
        }
    }

    return false;
};
$all = array_merge(...array_map('array_keys', array_values($catalogue)));

return [

    // Lower level = higher in the hierarchy. A role can only manage roles with a higher number.
    'roles' => [
        'ceo' => ['label' => 'CEO', 'level' => 1, 'description' => 'Full access — revenue, products, doctors, staff & news'],
        'gm' => ['label' => 'General Manager', 'level' => 2, 'description' => 'Complaints, inventory & transfers, HR, academy & news'],
        'reception' => ['label' => 'Receptionist', 'level' => 3, 'description' => 'Appointments, walk-in intake & payments'],
        'ecommerce' => ['label' => 'Lavish E-commerce', 'level' => 3, 'description' => 'Products & prices, inventory, deliveries & order payments'],
        'doctor' => ['label' => 'Doctor', 'level' => 3, 'description' => 'Your patients, their history & prescriptions'],
        'teacher' => ['label' => 'Lavish Academy', 'level' => 3, 'description' => 'Your students, schedule, modules, videos & exercises'],
        'team' => ['label' => 'Lavish Team', 'level' => 3, 'team_only' => true, 'description' => 'Staff groups, announcements & team chat — for every member of staff'],
    ],

    'catalogue' => $catalogue,

    // The CEO always holds every permission that applies to them; that row can't be edited.
    'defaults' => [
        'ceo' => array_values(array_filter($all, fn ($k) => $applies($k, 'ceo'))),
        'gm' => ['page.complaints', 'page.products', 'page.staff', 'page.academy', 'page.news', 'page.permissions', 'page.team', 'team.announce', 'team.groups',
            'products.transfer', 'complaints.manage', 'news.publish', 'academy.paths', 'academy.certificates',
            'staff.add', 'doctors.grant', 'permissions.manage'],
        'reception' => ['page.appointments', 'page.clients', 'page.payments', 'page.team',
            'appointments.approve', 'clients.history', 'clients.invite', 'finance.revenue', 'payments.collect'],
        'ecommerce' => ['page.products', 'page.orders', 'page.prescriptions', 'page.team', 'products.edit', 'orders.deliver', 'prescriptions.fulfil'],
        'doctor' => ['page.appointments', 'page.clients', 'page.prescriptions', 'page.chat', 'page.team', 'appointments.complete', 'prescriptions.create'],
        'teacher' => ['page.schedule', 'page.modules', 'page.students', 'academy.teach', 'page.team'],
        // Staff with no work portal (nurses, therapists, drivers…) — they only see Lavish Team.
        'team' => ['page.team'],
    ],

    // Sidebar order of the web portal.
    'pages' => ['dashboard', 'appointments', 'payments', 'clients', 'chat', 'products', 'orders', 'prescriptions', 'complaints', 'academy', 'staff', 'news', 'schedule', 'modules', 'students', 'team', 'permissions'],
];
