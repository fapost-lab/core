<?php

declare(strict_types=1);

/*
| Что говорит форма учётных данных, когда поле отклонено (App\Domains\Credentials\CredentialWriter). Сообщение
| никогда не содержит введённое значение.
*/
return [
    'name_required'               => 'Укажите название.',
    'name_too_long'               => 'Название не должно быть длиннее :max символов.',
    'name_taken'                  => 'Учётные данные с таким названием уже есть.',
    'type_unknown'                => 'Выберите тип учётных данных.',
    'field_required'              => 'Поле обязательно.',
    'field_invalid'               => 'Недопустимое значение.',
    'field_too_long'              => 'Значение не должно быть длиннее :max символов.',
    'select_invalid'              => 'Выберите одно из значений списка.',
    'url_invalid'                 => 'Укажите абсолютный https-адрес.',
    'url_host_not_allowed'        => 'Хост этого адреса должен быть среди разрешённых хостов.',
    'secret_required'             => 'Секрет обязателен.',
    'secret_too_short'            => 'Секрет должен быть не короче :min символов.',
    'secret_reenter_for_new_host' => 'Секрет теперь может уйти на хост, куда раньше не уходил: введите его заново.',
    'hosts_required'              => 'Добавьте хотя бы один разрешённый хост.',
    'hosts_too_many'              => 'Не более :max разрешённых хостов.',
    'host_invalid'                => 'Укажите имя хоста вида api.example.com или *.example.com, без схемы, порта и пути.',
    'authorize_params_invalid'    => 'Укажите параметры строкой запроса, например access_type=offline&prompt=consent.',
    'authorize_params_reserved'   => 'Эти параметры задаёт само подключение, их нельзя указать здесь: :keys.',
];
