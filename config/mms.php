<?php

/*
|--------------------------------------------------------------------------
| MMS general settings (not access rules; those are in mms_access.php)
|--------------------------------------------------------------------------
| display_timezone  The app stores times in UTC (config/app.php timezone stays
|                   "UTC"). Pages show times converted to this zone.
| audit.subject_types  Model class => label shown in the Audit Log filter.
|                   Later steps add PurchaseOrder, Delivery, etc. here.
| audit.fields      Property key => label for the "what changed" column.
| audit.events      Event name => label.
|
| After editing: php artisan config:clear
*/

return [

    'display_timezone' => env('MMS_DISPLAY_TIMEZONE', 'Asia/Manila'),

    'audit' => [
        'subject_types' => [
            \App\Models\User::class => 'User',
        ],

        'fields' => [
            'employee_no' => 'Employee no.',
            'name' => 'Name',
            'position' => 'Position',
            'email' => 'Email',
            'role' => 'Role',
            'permissions' => 'Direct permissions',
            'status' => 'Status',
        ],

        'events' => [
            'created' => 'Created',
            'updated' => 'Edited',
            'activated' => 'Activated',
            'deactivated' => 'Deactivated',
            'password_reset' => 'Password reset',
            'access_changed' => 'Access changed',
            'status_changed' => 'Status changed',
            'login' => 'Login',
            'login_failed' => 'Failed login',
            'login_blocked' => 'Login blocked',
            'logout' => 'Logout',
            'session_ended' => 'Session ended',
        ],
    ],

];
