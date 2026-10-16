<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Close the console registry after boot
    |--------------------------------------------------------------------------
    |
    | A Solution declares its console UI from its provider's boot(); once the application has booted, the registry
    | is frozen and a late declaration throws. Tests switch this off (phpunit.xml) because they register fixture
    | Solutions into an application that has already booted.
    |
    */

    'freeze_console_registry' => (bool) env('SOLUTIONS_FREEZE_CONSOLE_REGISTRY', true),

    /*
    |--------------------------------------------------------------------------
    | The console runtime a Solution's prebuilt UI runs on
    |--------------------------------------------------------------------------
    |
    | The versions of the console kit (@fapost/ui), Vue and Inertia this Core ships, against which the activation screen
    | compares the ranges a Solution's `fapost-ui.json` declares. PHP has no node_modules in a deployed image, so they
    | are written here; a test holds them equal to package-lock.json, so bumping one of the packages fails until this
    | file follows.
    |
    */

    'console_runtime' => [
        'kit'     => '0.1.0',
        'vue'     => '3.5.43',
        'inertia' => '3.7.1',
    ],

];
