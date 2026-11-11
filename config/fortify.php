<?php

return [

    // Auth guard Fortify uses.
    'guard' => 'web',

    // Kept because Fortify reads it; password reset is turned off.
    'passwords' => 'users',

    // Login field: employee number instead of email.
    'username' => 'employee_no',

    'email' => 'email',

    // Off so "DUMMY-0001" is matched exactly, whatever the database collation.
    'lowercase_usernames' => false,

    // Where users land after logging in.
    'home' => '/dashboard',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    // Login throttling is defined in FortifyServiceProvider.
    'limiters' => [
        'login' => 'login',
    ],

    'views' => true,

    // Empty on purpose: no registration, no password reset, no email
    // verification. The admin creates accounts.
    'features' => [],

];