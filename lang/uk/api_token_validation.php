<?php

declare(strict_types=1);

/*
| What the API token form says when a value is refused (App\Domains\Staff\Services\ApiTokenService). A message never
| carries the token.
*/
return [
    'name_required'         => 'Введіть назву.',
    'name_too_long'         => 'Назва не може бути довшою за :max символів.',
    'abilities_required'    => 'Виберіть хоча б одне право.',
    'ability_unknown'       => 'Одного з прав не існує.',
    'ability_not_grantable' => 'Ви не можете видати токену право «:permission».',
    'lifetime'              => 'Виберіть строк від 1 до :max днів.',
    'not_rotatable'         => 'Прострочений або відкликаний токен не можна ротувати. Випустіть новий.',
    'widens'                => 'Ротований токен може зберегти або втратити права замінюваного токена, але не отримати нові.',
];
