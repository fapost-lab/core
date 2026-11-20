<?php

declare(strict_types=1);

/*
| What the API token form says when a value is refused (App\Domains\Staff\Services\ApiTokenService). A message never
| carries the token.
*/
return [
    'name_required'         => 'Enter a name.',
    'name_too_long'         => 'The name may not be longer than :max characters.',
    'abilities_required'    => 'Choose at least one permission.',
    'ability_unknown'       => 'One of the permissions does not exist.',
    'ability_not_grantable' => 'You cannot give a token the permission ":permission".',
    'lifetime'              => 'Choose an expiry from 1 to :max days.',
    'not_rotatable'         => 'An expired or revoked token cannot be rotated. Issue a new one.',
    'widens'                => 'A rotated token may keep or drop permissions of the token it replaces, never add new ones.',
];
