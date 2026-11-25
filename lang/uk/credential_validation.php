<?php

declare(strict_types=1);

/*
| Що каже форма облікових даних, коли поле відхилено (App\Domains\Credentials\CredentialWriter). Повідомлення
| ніколи не містить введене значення.
*/
return [
    'name_required'               => 'Вкажіть назву.',
    'name_too_long'               => 'Назва не має бути довшою за :max символів.',
    'name_taken'                  => 'Облікові дані з такою назвою вже є.',
    'type_unknown'                => 'Оберіть тип облікових даних.',
    'field_required'              => 'Поле обов’язкове.',
    'field_invalid'               => 'Недопустиме значення.',
    'field_too_long'              => 'Значення не має бути довшим за :max символів.',
    'select_invalid'              => 'Оберіть одне зі значень списку.',
    'url_invalid'                 => 'Вкажіть абсолютну https-адресу.',
    'url_host_not_allowed'        => 'Хост цієї адреси має бути серед дозволених хостів.',
    'secret_required'             => 'Секрет обов’язковий.',
    'secret_too_short'            => 'Секрет має бути не коротшим за :min символів.',
    'secret_reenter_for_new_host' => 'Секрет тепер може потрапити на хост, куди раніше не потрапляв: введіть його знову.',
    'hosts_required'              => 'Додайте щонайменше один дозволений хост.',
    'hosts_too_many'              => 'Не більше :max дозволених хостів.',
    'host_invalid'                => 'Вкажіть ім’я хоста виду api.example.com або *.example.com, без схеми, порту та шляху.',
    'authorize_params_invalid'    => 'Вкажіть параметри рядком запиту, наприклад access_type=offline&prompt=consent.',
    'authorize_params_reserved'   => 'Ці параметри задає саме підключення, їх не можна вказати тут: :keys.',
];
