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

];
