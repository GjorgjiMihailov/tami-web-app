<?php

namespace App\Support\Posting;

use App\Exceptions\PostingFormulaException;
use App\Support\Bcmath;

/**
 * Чист пресметувач на формули од шемите: броеви, именувани променливи,
 * + − * и загради. Нема eval и нема делење — формулата не може да направи
 * ништо друго освен аритметика над променливите што ги добива.
 */
final class FormulaEvaluator
{
    private const SCALE = 10;

    /** @var list<array{0: string, 1: string}> */
    private array $tokens;

    private int $pos = 0;

    /** @param array<string, string> $variables */
    private function __construct(private readonly string $formula, private readonly array $variables)
    {
        $this->tokens = self::tokenize($formula);
    }

    /** @param array<string, string> $variables */
    public static function evaluate(string $formula, array $variables): string
    {
        $evaluator = new self($formula, $variables);

        if ($evaluator->tokens === []) {
            throw new PostingFormulaException('Формулата е празна.');
        }

        $result = $evaluator->expression();

        if ($evaluator->pos < count($evaluator->tokens)) {
            throw $evaluator->syntax();
        }

        return Bcmath::roundHalfUp($result, 2);
    }

    /** @return list<string> */
    public static function variablesIn(string $formula): array
    {
        $names = [];

        foreach (self::tokenize($formula) as [$type, $value]) {
            if ($type === 'var' && ! in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($formula);

        while ($offset < $length) {
            if (preg_match('/\G\s+/u', $formula, $m, 0, $offset)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G\d+(?:\.\d+)?/u', $formula, $m, 0, $offset)) {
                $tokens[] = ['num', $m[0]];
            } elseif (preg_match('/\G[\p{L}_][\p{L}\p{N}_]*/u', $formula, $m, 0, $offset)) {
                $tokens[] = ['var', $m[0]];
            } elseif (preg_match('/\G[-+*()]/', $formula, $m, 0, $offset)) {
                $tokens[] = ['op', $m[0]];
            } else {
                throw new PostingFormulaException("Формулата „{$formula}“ содржи недозволен знак.");
            }

            $offset += strlen($m[0]);
        }

        return $tokens;
    }

    private function expression(): string
    {
        $value = $this->term();

        while ($this->peekOp(['+', '-'])) {
            $op = $this->next()[1];
            $right = $this->term();
            $value = $op === '+' ? bcadd($value, $right, self::SCALE) : bcsub($value, $right, self::SCALE);
        }

        return $value;
    }

    private function term(): string
    {
        $value = $this->factor();

        while ($this->peekOp(['*'])) {
            $this->next();
            $value = bcmul($value, $this->factor(), self::SCALE);
        }

        return $value;
    }

    private function factor(): string
    {
        $token = $this->next();

        if ($token === null) {
            throw $this->syntax();
        }

        [$type, $value] = $token;

        if ($type === 'num') {
            return $value;
        }

        if ($type === 'var') {
            if (! array_key_exists($value, $this->variables)) {
                throw new PostingFormulaException("Непозната променлива „{$value}“ во формулата „{$this->formula}“.");
            }

            return (string) $this->variables[$value];
        }

        if ($value === '-') {
            return bcmul('-1', $this->factor(), self::SCALE);
        }

        if ($value === '(') {
            $inner = $this->expression();
            $close = $this->next();

            if ($close === null || $close[1] !== ')') {
                throw $this->syntax();
            }

            return $inner;
        }

        throw $this->syntax();
    }

    /** @return array{0: string, 1: string}|null */
    private function next(): ?array
    {
        return $this->tokens[$this->pos++] ?? null;
    }

    /** @param list<string> $ops */
    private function peekOp(array $ops): bool
    {
        $token = $this->tokens[$this->pos] ?? null;

        return $token !== null && $token[0] === 'op' && in_array($token[1], $ops, true);
    }

    private function syntax(): PostingFormulaException
    {
        return new PostingFormulaException("Формулата „{$this->formula}“ не е разбирлива.");
    }
}
