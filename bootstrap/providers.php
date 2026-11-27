<?php

use App\Northdeck\NorthdeckServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NorthdeckServiceProvider::class, // NORTHDECK: SSO, branding, support links (app/Northdeck)
];
