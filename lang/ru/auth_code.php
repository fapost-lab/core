<?php

declare(strict_types=1);

// The fixed email that carries a contact's one-time code (`auth_request` node, email method).
// It is sent in the contact's language; no flow author writes any of it.
return [
    'subject' => 'Ваш код подтверждения :brand',
    'heading' => 'Код подтверждения :brand',
    'intro'   => 'Введите этот код в чате, чтобы подтвердить, что это вы:',
    'expires' => 'Код действует :minutes минуту.|Код действует :minutes минуты.|Код действует :minutes минут.',
    'ignore'  => 'Если вы не запрашивали код, просто проигнорируйте это письмо: без кода доступ никто не получит.',
];
