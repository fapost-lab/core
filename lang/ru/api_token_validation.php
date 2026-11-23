<?php

declare(strict_types=1);

/*
| What the API token form says when a value is refused (App\Domains\Staff\Services\ApiTokenService). A message never
| carries the token.
*/
return [
    'name_required'         => 'Введите название.',
    'name_too_long'         => 'Название не может быть длиннее :max символов.',
    'abilities_required'    => 'Выберите хотя бы одно право.',
    'ability_unknown'       => 'Одного из прав не существует.',
    'ability_not_grantable' => 'Вы не можете выдать токену право «:permission».',
    'lifetime'              => 'Выберите срок от 1 до :max дней.',
    'not_rotatable'         => 'Истёкший или отозванный токен нельзя ротировать. Выпустите новый.',
    'widens'                => 'Ротированный токен может сохранить или потерять права заменяемого токена, но не получить новые.',
];
