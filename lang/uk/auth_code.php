<?php

declare(strict_types=1);

// The fixed email that carries a contact's one-time code (`auth_request` node, email method).
// It is sent in the contact's language; no flow author writes any of it.
return [
    'subject' => 'Ваш код підтвердження :brand',
    'heading' => 'Код підтвердження :brand',
    'intro'   => 'Введіть цей код у чаті, щоб підтвердити, що це ви:',
    'expires' => 'Код діє :minutes хвилину.|Код діє :minutes хвилини.|Код діє :minutes хвилин.',
    'ignore'  => 'Якщо ви не запитували код, просто проігноруйте цей лист: без коду доступ ніхто не отримає.',
];
