<?php

return [
    'brand' => 'OptiNexus',
    'language' => 'Language',

    'mail' => [
        'subject' => 'You are invited to :application (:tenant) on OptiNexus',
        'greeting' => 'Hello :name,',
        'intro' => ':inviter invited you to use :application for :tenant.',
        'intro_unknown' => 'You were invited to use :application for :tenant.',
        'action' => 'Open the link below to choose your own password and confirm that this e-mail address is yours:',
        'validity' => 'The link works once and until :expires.',
        'ignore' => 'If you did not expect this invitation, ignore this message. Nothing happens until you open the link and set a password.',
        'footer' => 'Automatic message from OptiNexus. Please do not reply.',
    ],

    'form' => [
        'title' => 'Accept your invitation',
        'lead' => 'You are invited to use :application for :tenant.',
        'email' => 'E-mail address',
        'name' => 'Your name',
        'password' => 'Choose a password',
        'password_hint' => 'At least :min characters. Only you will know it.',
        'password_confirmation' => 'Repeat the password',
        'current_password' => 'Your current OptiNexus password',
        'existing_notice' => 'This e-mail address already has an OptiNexus account. Confirm it with your current password to add this access to it. Your password is not changed.',
        'submit_new' => 'Set password and continue',
        'submit_existing' => 'Confirm and add access',
        'verify_note' => 'Opening this invitation from your mailbox confirms that the e-mail address is yours.',
    ],

    'done' => [
        'title' => 'Your access is ready',
        'lead' => 'Your e-mail address is confirmed and :application is ready for you at :tenant.',
        'next' => 'Continue to :application and sign in with :email.',
        'continue' => 'Continue to :application',
        'no_link' => 'Open :application from your usual address and choose to sign in with OptiNexus.',
    ],

    'states' => [
        'invalid' => [
            'title' => 'This invitation link is not valid',
            'text' => 'The link is incomplete, was replaced by a newer invitation, or never existed.',
            'action' => 'Open the most recent invitation e-mail, or ask the administrator who invited you to send it again.',
        ],
        'used' => [
            'title' => 'This invitation was already used',
            'text' => 'The access for this invitation has been set up.',
            'action' => 'Sign in to your application with your e-mail address and the password you chose.',
        ],
        'revoked' => [
            'title' => 'This invitation was withdrawn',
            'text' => 'The administrator cancelled this invitation.',
            'action' => 'Ask the administrator of your organization to invite you again.',
        ],
        'expired' => [
            'title' => 'This invitation has expired',
            'text' => 'Invitation links only work for a limited time.',
            'action' => 'Ask the administrator who invited you to send the invitation again. A new link will be e-mailed to you.',
        ],
        'unavailable' => [
            'title' => 'This invitation can no longer be used',
            'text' => 'The organization, the application or the permission of the person who invited you changed since the invitation was sent.',
            'action' => 'Ask the administrator of your organization to review the access and invite you again.',
        ],
        'roles_changed' => [
            'title' => 'The invited access changed',
            'text' => 'One of the roles in this invitation can no longer be given. Nothing was granted.',
            'action' => 'Ask the administrator to invite you again with the current roles.',
        ],
        'not_linkable' => [
            'title' => 'This invitation cannot be used for this e-mail address',
            'text' => 'The account behind this e-mail address cannot receive this access.',
            'action' => 'Contact the administrator who invited you.',
        ],
        'locked' => [
            'title' => 'Too many attempts',
            'text' => 'This invitation is locked for a while because the password was entered incorrectly several times.',
            'action' => 'Try again in :minutes minutes, or ask the administrator to send a new invitation.',
        ],
        'conflict' => [
            'title' => 'This e-mail address was just registered',
            'text' => 'An account for this e-mail address was created a moment ago.',
            'action' => 'Open the invitation link again and confirm it with that account\'s password.',
        ],
        'no_seat' => [
            'title' => 'There is no free seat for this access',
            'text' => 'Your organization has no free seat for this application right now, so nothing was granted.',
            'action' => 'Ask the administrator of your organization to free a seat or raise the user limit, then open this invitation again.',
        ],
        'seat_retry' => [
            'title' => 'We could not reserve your seat just now',
            'text' => 'The seat could not be checked this moment. Nothing was granted and your invitation is still valid.',
            'action' => 'Wait a moment and open the invitation link again.',
        ],
        'wrong_password' => [
            'title' => 'The password is not correct',
            'text' => 'The current password could not be confirmed.',
            'action' => 'Try again.',
        ],
    ],

    'errors' => [
        'name_required' => 'Enter your name.',
        'password_required' => 'Choose a password.',
        'password_min' => 'The password needs at least :min characters.',
        'password_confirmed' => 'The two passwords are not the same.',
        'current_password_required' => 'Enter your current password.',
        'wrong_password' => 'That password is not correct. Attempts left: :left.',
        'rate_limited' => 'Too many requests. Try again in a moment.',
    ],
];
