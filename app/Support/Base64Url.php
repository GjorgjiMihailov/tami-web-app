<?php

namespace App\Support;

class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
