<?php

// Домините на четирите хостови. Стандардните вредности се оние што ги користат
// тестовите и локалната работа, па серијата поминува без .env.
return [
    'domains' => [
        'portal' => env('APP_DOMAIN_PORTAL', 'portal.test'),
        'prodazba' => env('APP_DOMAIN_PRODAZBA', 'prodazba.test'),
        'finansii' => env('APP_DOMAIN_FINANSII', 'finansii.test'),
        'plata' => env('APP_DOMAIN_PLATA', 'plata.test'),
    ],
];
