<?php

/*
| Staff roles, the permission catalogue and the starting grants — the source of truth for the web portal.
| `php artisan db:seed --class=RolePermissionSeeder` syncs it into the roles / permissions tables.
| Grants changed later in the web Permissions page live in the database (permission_role).
|
| Permissions are split by action so a manager can allow, say, "view staff" without "add staff":
|   page.*      — see the page (view / list)
|   *.create    — add new records
|   *.edit      — change existing records
|   *.delete    — remove records
|   plus a few workflow actions (approve, collect, deliver, grade…).
| Lavish Team (groups & announcements) is not a permission: every staff member sees it, and only the
| groups they were added to.
*/

$catalogue = [
    'Appointments' => [
        'page.appointments' => ['View appointments & the calendar'],
        'appointments.create' => ['Book walk-in & phone appointments'],
        'appointments.approve' => ['Approve, assign doctors & decline bookings'],
        'appointments.complete' => ['Mark treatments as done', ['doctor', 'nurse']],
    ],
    'Clients' => [
        'page.clients' => ['View clients & their records'],
        'clients.edit' => ['Edit client details'],
        'clients.history' => ['Record & edit client medical history'],
        'clients.invite' => ['Send app links & mark clients registered'],
    ],
    'Payments' => [
        'page.payments' => ['View payments'],
        'payments.collect' => ['Record payments, check receipts, use rewards & gift cards on bills'],
        'payments.refund' => ['Give refunds'],
        'finance.revenue' => ['See revenue, prices, client spend & stock value'],
    ],
    'Treatments' => [
        'page.treatments' => ['View treatments & prices'],
        'treatments.create' => ['Add treatments'],
        'treatments.edit' => ['Edit treatments, prices, length & which doctors offer them'],
        'treatments.delete' => ['Remove treatments'],
    ],
    'Products & stock' => [
        'page.products' => ['View products & stock'],
        'products.create' => ['Add products'],
        'products.edit' => ['Edit products, prices & photos'],
        'products.delete' => ['Remove products'],
        'products.stock' => ['Receive & adjust stock'],
        'products.transfer' => ['Transfer stock between branches'],
    ],
    'Orders' => [
        'page.orders' => ['View orders'],
        'orders.deliver' => ['Update order status (confirm → prepare → deliver) & mark paid'],
        'orders.cancel' => ['Cancel orders'],
        'orders.points' => ['Take clients’ points off shop orders (requests sent by reception)'],
    ],
    'Prescriptions' => [
        'page.prescriptions' => ['View prescriptions'],
        'prescriptions.create' => ['Write prescriptions & suggest restocked products', ['doctor']],
        'prescriptions.fulfil' => ['Turn prescriptions into orders'],
    ],
    'Patient chat' => [
        'page.chat' => ['Chat with patients', ['doctor']],
    ],
    'Complaints' => [
        'page.complaints' => ['View complaints'],
        'complaints.reply' => ['Reply to complaints'],
        'complaints.resolve' => ['Change complaint status & resolve'],
    ],
    'News' => [
        'page.news' => ['View news'],
        'news.create' => ['Post news'],
        'news.edit' => ['Edit & publish / unpublish news'],
        'news.delete' => ['Delete news'],
    ],
    'Academy' => [
        'page.academy' => ['View courses & students'],
        'academy.create' => ['Create courses'],
        'academy.paths' => ['Edit courses, learning paths & teacher access'],
        'academy.students' => ['Enrol students, mark fees paid & reset their app passwords'],
        'academy.certificates' => ['Issue certificates'],
    ],
    'Teaching' => [
        'page.schedule' => ['Class schedule', ['teacher']],
        'page.modules' => ['Modules (videos & exercises)', ['teacher']],
        'page.students' => ['My students', ['teacher']],
        'academy.teach' => ['Teach: lessons, classes, grading & student chat', ['teacher']],
    ],
    'Staff' => [
        'page.staff' => ['View staff'],
        'staff.create' => ['Add staff'],
        'staff.edit' => ['Edit staff details & reactivate accounts'],
        'staff.suspend' => ['Suspend staff accounts'],
        'staff.password' => ['Reset staff passwords'],
        'doctors.grant' => ['Give doctors portal access'],
        'doctors.ban' => ['Ban & reinstate doctors'],
    ],
    'Permissions' => [
        'page.permissions' => ['View permissions'],
        'permissions.manage' => ['Change permissions of lower roles'],
    ],
    'Lavish Rewards' => [
        'page.rewards' => ['View Lavish Rewards (members, points & history)'],
        'rewards.enroll' => ['Enrol clients in Lavish Rewards'],
        'rewards.requests' => ['Handle clients’ requests to use points (take off bills, send shop requests to e-commerce)'],
        'rewards.manage' => ['Edit the program: tiers, earning rules, catalog & campaigns'],
        'rewards.adjust' => ['Add or remove client points by hand'],
    ],
    'Gift cards' => [
        'page.giftcards' => ['View gift cards'],
        'giftcards.sell' => ['Sell gift cards & look cards up by code'],
        'giftcards.activate' => ['Activate e-gift cards bought in the app'],
        'giftcards.use' => ['Use gift cards for treatments at the desk'],
        'giftcards.promo' => ['Issue free promotional cards'],
        'giftcards.void' => ['Void & reissue lost cards'],
        'giftcards.stock' => ['Create & import printed card batches'],
    ],
    'Lavish Team' => [
        'team.announce' => ['Send announcements to all staff or a group'],
        'team.groups' => ['Create groups & manage their members'],
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
        'nurse' => ['label' => 'Nurse', 'level' => 3, 'description' => 'Clinical support: appointments, client records & prescriptions'],
        'teacher' => ['label' => 'Lavish Academy', 'level' => 3, 'description' => 'Your students, schedule, modules, videos & exercises'],
    ],

    // Old (combined) permission → the new split permissions. Used once by the migration that splits
    // them, so every role keeps exactly what it could do before.
    'renamed' => [
        'staff.add' => ['staff.create', 'staff.edit', 'staff.password'],
        'news.publish' => ['news.create', 'news.edit', 'news.delete'],
        'complaints.manage' => ['complaints.reply', 'complaints.resolve'],
        'giftcards.manage' => ['giftcards.promo', 'giftcards.void', 'giftcards.stock'],
        'products.edit' => ['products.create', 'products.edit', 'products.delete', 'products.stock'],
        'appointments.approve' => ['appointments.create', 'appointments.approve'],
        'clients.history' => ['clients.edit', 'clients.history'],
        'payments.collect' => ['payments.collect', 'payments.refund'],
        'orders.deliver' => ['orders.deliver', 'orders.cancel'],
        'rewards.manage' => ['rewards.manage', 'rewards.adjust'],
        'academy.paths' => ['academy.create', 'academy.paths'],
    ],

    'catalogue' => $catalogue,

    // The CEO always holds every permission that applies to them; that row can't be edited.
    'defaults' => [
        'ceo' => array_values(array_filter($all, fn ($k) => $applies($k, 'ceo'))),
        'gm' => ['page.treatments', 'treatments.create', 'treatments.edit', 'treatments.delete',
            'page.complaints', 'complaints.reply', 'complaints.resolve',
            'page.products', 'products.transfer',
            'page.staff', 'staff.create', 'staff.edit', 'staff.password', 'doctors.grant',
            'page.academy', 'academy.create', 'academy.paths', 'academy.certificates',
            'page.news', 'news.create', 'news.edit', 'news.delete',
            'page.permissions', 'permissions.manage', 'team.announce', 'team.groups',
            'page.rewards', 'rewards.manage', 'rewards.adjust', 'rewards.enroll', 'rewards.requests',
            'page.giftcards', 'giftcards.sell', 'giftcards.activate', 'giftcards.use', 'giftcards.promo', 'giftcards.void', 'giftcards.stock'],
        'reception' => ['page.appointments', 'appointments.create', 'appointments.approve',
            'page.clients', 'clients.edit', 'clients.history', 'clients.invite',
            'page.payments', 'payments.collect', 'payments.refund', 'finance.revenue',
            'page.rewards', 'rewards.enroll', 'rewards.requests', 'page.giftcards', 'giftcards.sell', 'giftcards.activate', 'giftcards.use'],
        'ecommerce' => ['page.products', 'products.create', 'products.edit', 'products.delete', 'products.stock',
            'page.orders', 'orders.deliver', 'orders.cancel', 'orders.points', 'page.prescriptions', 'prescriptions.fulfil'],
        'doctor' => ['page.appointments', 'appointments.complete', 'page.clients', 'page.prescriptions', 'prescriptions.create', 'page.chat'],
        'nurse' => ['page.appointments', 'page.clients', 'page.prescriptions'],
        'teacher' => ['page.schedule', 'page.modules', 'page.students', 'academy.teach'],
    ],

    // Sidebar order of the web portal. "team" is shown to every staff member.
    'pages' => ['dashboard', 'appointments', 'payments', 'rewards', 'giftcards', 'clients', 'chat', 'treatments', 'products', 'orders', 'prescriptions', 'complaints', 'academy', 'staff', 'news', 'schedule', 'modules', 'students', 'team', 'permissions'],
];
