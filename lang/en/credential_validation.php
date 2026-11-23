<?php

declare(strict_types=1);

/*
| What the credential form says when a field is refused (App\Domains\Credentials\CredentialWriter). A message never
| carries the value that was entered.
*/
return [
    'name_required'             => 'Enter a name.',
    'name_too_long'             => 'The name may not be longer than :max characters.',
    'name_taken'                => 'A credential with this name already exists.',
    'type_unknown'              => 'Choose a credential type.',
    'field_required'            => 'This field is required.',
    'field_invalid'             => 'This value is not valid.',
    'field_too_long'            => 'This value may not be longer than :max characters.',
    'select_invalid'            => 'Choose one of the listed values.',
    'url_invalid'               => 'Enter an absolute https URL.',
    'url_host_not_allowed'      => 'The host of this URL must be one of the allowed hosts.',
    'secret_required'           => 'This secret is required.',
    'secret_too_short'          => 'A secret must be at least :min characters long.',
    'hosts_required'            => 'Add at least one allowed host.',
    'hosts_too_many'            => 'No more than :max allowed hosts.',
    'host_invalid'              => 'Enter a host name such as api.example.com or *.example.com, without a scheme, port or path.',
    'authorize_params_invalid'  => 'Enter parameters as a query string, such as access_type=offline&prompt=consent.',
    'authorize_params_reserved' => 'These parameters are set by the connection itself and cannot be given here: :keys.',
];
