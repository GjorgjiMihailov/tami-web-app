<?php

namespace App\Support;

/**
 * Со кого идентитет се потпишува и праќа кон УЈП за една фирма: е-УЈП ID и
 * сериски број на токенот. Не е самата фирма — е човекот што потпишува.
 */
final class EfakturaSigner
{
    public const SOURCE_USER = 'user';

    /** Постар запис: токен запишан на самата фирма (пред токенот да се врзе за корисник). */
    public const SOURCE_COMPANY = 'company';

    public function __construct(
        public readonly string $eujpId,
        public readonly string $serialNumber,
        public readonly string $source,
    ) {}
}
