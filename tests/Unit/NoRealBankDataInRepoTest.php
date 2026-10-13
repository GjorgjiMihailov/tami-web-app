<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Вистинските банкарски изводи не влегуваат во репото ни како фикстури ни во
 * документација. Тестот бара 12–17-цифрени броеви и ги споредува по SHA-1 со
 * сметките од вистинските фајлови (самите броеви не се чуваат овде).
 */
class NoRealBankDataInRepoTest extends TestCase
{
    private const REAL_ACCOUNT_HASHES = [
    ];

    public function test_no_test_or_document_contains_a_real_account_number(): void
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
                preg_match_all('/\b\d{12,17}\b/', (string) file_get_contents($file->getPathname()), $matches);

                foreach ($matches[0] as $token) {
                    if (in_array(sha1($token), self::REAL_ACCOUNT_HASHES, true)) {
                        $offenders[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $scanned, 'Скенирањето не нашло датотеки.');
        $this->assertSame([], array_values(array_unique($offenders)), 'Вистински број на сметка во репото: '.implode(', ', array_unique($offenders)));
    }
}
