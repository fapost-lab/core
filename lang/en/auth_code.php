<?php

declare(strict_types=1);

// The fixed email that carries a contact's one-time code (`auth_request` node, email method).
// It is sent in the contact's language; no flow author writes any of it.
return [
    'subject' => 'Your :brand verification code',
    'heading' => ':brand verification code',
    'intro'   => 'Type this code in the chat to confirm it is you:',
    'expires' => 'The code works for :minutes minute.|The code works for :minutes minutes.',
    'ignore'  => 'If you did not ask for a code, ignore this email: nobody gets access without it.',
];
