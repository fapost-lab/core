<?php

declare(strict_types=1);

return [
    'login' => [
        'title'       => 'Sign in',
        'heading'     => 'Sign in',
        'description' => 'Use the account of your workspace.',
        'email'       => 'Email address',
        'password'    => 'Password',
        'remember'    => 'Remember me',
        'submit'      => 'Sign in',
        'failed'      => 'These credentials do not match our records.',
        'throttled'   => [
            'title' => 'Too many login attempts',
            'body'  => 'Please try again in :seconds seconds.',
        ],
    ],
    'activation' => [
        'title'                 => 'Set password',
        'heading'               => 'Set your password',
        'description'           => 'Choose a password to activate your account.',
        'email'                 => 'Email address',
        'password'              => 'Password',
        'password_confirmation' => 'Confirm password',
        'submit'                => 'Activate',
        'invalid'               => 'The activation link is invalid or has expired.',
        'expired'               => [
            'title'       => 'Link expired',
            'heading'     => 'This activation link has expired',
            'description' => 'Ask an administrator to resend the invitation.',
            'back'        => 'Back',
        ],
    ],
];
