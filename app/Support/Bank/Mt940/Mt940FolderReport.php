<?php

namespace App\Support\Bank\Mt940;

/**
 * Анализа на повеќе MT940 фајлови одеднаш: дупки во низата, прекини на
 * ланецот на состојби и изводи што не се совпаѓаат. Чиста логика, без база.
 */
final class Mt940FolderReport
{
    /**
     * @param  array<string, string>  $files  име на фајл => содржина
     * @return array{
     *     files: int, statements: int, lines: int,
     *     refused: list<array{file: string, reason: string}>,
     *     duplicates: list<string>,
     *     accounts: array<string, array{first: int, last: int, count: int, gaps: list<string>, chain_breaks: list<string>, mismatches: list<string>}>
     * }
     */
    public static function analyse(array $files): array
    {
        $refused = [];
        $duplicates = [];
        $groups = [];

        foreach ($files as $name => $text) {
            try {
                $statement = Mt940Parser::parse($text);
            } catch (Mt940Exception $e) {
                $refused[] = ['file' => (string) $name, 'reason' => $e->getMessage()];

                continue;
            }

            $key = $statement->account.'/'.substr($statement->date, 0, 4);

            if (isset($groups[$key][$statement->number])) {
                $duplicates[] = "{$key} извод {$statement->number}: {$groups[$key][$statement->number]['file']} и {$name}";

                continue;
            }

            $groups[$key][$statement->number] = ['file' => (string) $name, 'statement' => $statement];
        }

        ksort($groups);

        $accounts = [];
        $statements = 0;
        $lines = 0;

        foreach ($groups as $key => $byNumber) {
            ksort($byNumber);
            $gaps = [];
            $breaks = [];
            $mismatches = [];
            $previous = null;

            foreach ($byNumber as $number => $entry) {
                $statement = $entry['statement'];
                $statements++;
                $lines += count($statement->lines);

                if (bccomp($statement->difference(), '0', 2) !== 0) {
                    $mismatches[] = "извод {$number}: разлика {$statement->difference()}";
                }

                if ($previous !== null) {
                    if ($number > $previous->number + 1) {
                        $gaps[] = $number === $previous->number + 2
                            ? 'фали '.($previous->number + 1)
                            : 'фалат '.($previous->number + 1).'–'.($number - 1);
                    } elseif (bccomp($statement->opening, $previous->closing, 2) !== 0) {
                        $breaks[] = "извод {$number}: почетна {$statement->opening}, крајна на {$previous->number} е {$previous->closing}";
                    }
                }

                $previous = $statement;
            }

            $numbers = array_keys($byNumber);
            $accounts[$key] = [
                'first' => min($numbers),
                'last' => max($numbers),
                'count' => count($numbers),
                'gaps' => $gaps,
                'chain_breaks' => $breaks,
                'mismatches' => $mismatches,
            ];
        }

        return [
            'files' => count($files),
            'statements' => $statements,
            'lines' => $lines,
            'refused' => $refused,
            'duplicates' => $duplicates,
            'accounts' => $accounts,
        ];
    }
}
