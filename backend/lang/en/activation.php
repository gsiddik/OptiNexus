<?php

return [
    'activate' => [
        'title' => 'Choose your password',
        'lead' => 'This is your first sign-in. Replace the temporary password with a password only you know.',
        'email' => 'Email',
        'temporary_password' => 'Temporary password',
        'temporary_hint' => 'The one your administrator gave you. It works once and is replaced when you save.',
        'password' => 'New password',
        'password_hint' => 'At least :min characters. Do not reuse the temporary one.',
        'password_confirmation' => 'Repeat the new password',
        'submit' => 'Save password and continue',
        'note' => 'Until this step is done your account cannot open any application.',
    ],

    'errors' => [
        'temporary_required' => 'Enter the temporary password.',
        'wrong_password' => 'The temporary password is not correct. :left attempts left before this account is locked for a while.',
        'password_required' => 'Choose a new password.',
        'password_min' => 'The password needs at least :min characters.',
        'password_confirmed' => 'The two passwords are not the same.',
        'same_password' => 'Choose a password different from the temporary one.',
        'locked' => 'Too many attempts. Try again in :minutes minutes, or ask your administrator.',
        'expired' => 'This temporary password has expired. Ask your administrator to issue a new one.',
        'session' => 'Your sign-in request has expired. Open the application again and sign in.',
    ],

    'states' => [
        'locked' => [
            'title' => 'Too many attempts',
            'text' => 'This account is locked for a while because the temporary password was entered incorrectly several times.',
            'action' => 'Try again in :minutes minutes, or ask your administrator.',
        ],
        'expired' => [
            'title' => 'The temporary password has expired',
            'text' => 'Temporary passwords only work for a limited time.',
            'action' => 'Ask your administrator to issue a new temporary password.',
        ],
        'used' => [
            'title' => 'This password was already replaced',
            'text' => 'The temporary password was used and no longer works.',
            'action' => 'Sign in to your application with your email address and the password you chose.',
        ],
        'invalid' => [
            'title' => 'This step cannot continue',
            'text' => 'Your sign-in request is no longer valid. A newer temporary password may have been issued.',
            'action' => 'Open the application again and sign in with the latest temporary password.',
        ],
    ],

    'pending' => [
        'title' => 'Waiting for email verification',
        'text' => 'Your password is set. Your administrator still has to record that your email address was verified before you can open any application.',
        'action' => 'Contact your administrator. After they confirm it, sign in again from your application with the password you chose.',
    ],
];
