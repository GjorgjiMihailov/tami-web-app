<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Вистинските банкарски изводи не влегуваат во репото ни како фикстури ни во
 * документација. Македонска домашна сметка е 15 цифри: 3 на банката, 10 на
 * сметката и 2 контролни (ISO 7064 mod 97-10). Секој таков број што ја
 * поминува контролата е вистинска сметка — измислените фикстури намерно не ја
 * поминуваат. Така нема список со вистински броеви (ни хешови од нив: на
 * толку мал простор хешот се враќа за секунди).
 */
class NoRealBankDataInRepoTest extends TestCase
{
    public function test_no_test_or_document_contains_a_valid_looking_account_number(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ([base_path('tests'), base_path('docs')] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! in_array($file->getExtension(), ['php', 'md', 'txt', 'json', 'xml', 'sta', 'mt940'], true)) {
                    continue;
                }

                $scanned++;
                $text = (string) file_get_contents($file->getPathname());

                preg_match_all('/(?<!\d)\d{15}(?!\d)/', $text, $plain);
                preg_match_all('/(?<!\d)(\d{3})-(\d{10})-(\d{2})(?!\d)/', $text, $dashed, PREG_SET_ORDER);

                $candidates = array_merge($plain[0], array_map(fn (array $m) => $m[1].$m[2].$m[3], $dashed));

                foreach ($candidates as $candidate) {
                    if (self::looksLikeARealAccount($candidate)) {
                        $offenders[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $scanned, 'Скенирањето не нашло датотеки.');
        $this->assertSame([], array_values(array_unique($offenders)), 'Број што личи на вистинска сметка: '.implode(', ', array_unique($offenders)));
    }

    public function test_the_check_accepts_a_constructed_valid_account_and_rejects_the_fixture_account(): void
    {
        $body = '3000000012345';
        $check = str_pad((string) (98 - ((int) bcmod($body.'00', '97'))), 2, '0', STR_PAD_LEFT);

        $this->assertTrue(self::looksLikeARealAccount($body.$check));
        $this->assertFalse(self::looksLikeARealAccount('100000000000001'));
    }

    private static function looksLikeARealAccount(string $digits): bool
    {
        if (preg_match('/^\d{15}$/', $digits) !== 1) {
            return false;
        }

        $check = 98 - (int) bcmod(substr($digits, 0, 13).'00', '97');

        return str_pad((string) $check, 2, '0', STR_PAD_LEFT) === substr($digits, 13, 2);
    }
}
