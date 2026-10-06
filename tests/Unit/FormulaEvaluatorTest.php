<?php

namespace Tests\Unit;

use App\Exceptions\PostingFormulaException;
use App\Support\Posting\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    public function test_arithmetic_with_precedence_and_parentheses(): void
    {
        $this->assertSame('7.00', FormulaEvaluator::evaluate('1 + 2 * 3', []));
        $this->assertSame('9.00', FormulaEvaluator::evaluate('(1 + 2) * 3', []));
        $this->assertSame('-4.50', FormulaEvaluator::evaluate('-(1 + 3.5)', []));
        $this->assertSame('0.30', FormulaEvaluator::evaluate('0.1 + 0.2', []));
    }

    public function test_variables_including_cyrillic_names(): void
    {
        $vars = ['ВКУПНО' => '118.00', 'ДДВ' => '18.00', 'НАБАВНА_ВРЕДНОСТ' => '40.00', 'A' => '2'];

        $this->assertSame('100.00', FormulaEvaluator::evaluate('ВКУПНО - ДДВ', $vars));
        $this->assertSame('-18.00', FormulaEvaluator::evaluate('-ДДВ', $vars));
        $this->assertSame('80.00', FormulaEvaluator::evaluate('НАБАВНА_ВРЕДНОСТ * A', $vars));
        $this->assertSame('36.00', FormulaEvaluator::evaluate('ДДВ*A', $vars));
    }

    public function test_the_result_is_rounded_half_up_to_two_decimals(): void
    {
        $this->assertSame('0.34', FormulaEvaluator::evaluate('0.335', []));
        $this->assertSame('0.33', FormulaEvaluator::evaluate('0.334', []));
    }

    public function test_an_unknown_variable_is_refused(): void
    {
        $this->expectException(PostingFormulaException::class);
        $this->expectExceptionMessage('Непозната променлива „ЦАРИНА“');

        FormulaEvaluator::evaluate('ВКУПНО - ЦАРИНА', ['ВКУПНО' => '1.00']);
    }

    public function test_bad_syntax_is_refused(): void
    {
        foreach (['1 +', '(1 + 2', '1 / 2', '1 2', '* 3', ''] as $formula) {
            try {
                FormulaEvaluator::evaluate($formula, []);
                $this->fail("Очекуван исклучок за „{$formula}“.");
            } catch (PostingFormulaException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_variables_used_by_a_formula_can_be_listed(): void
    {
        $this->assertSame(['ВКУПНО', 'ДДВ'], FormulaEvaluator::variablesIn('ВКУПНО - ДДВ * 2 + ВКУПНО'));
        $this->assertSame([], FormulaEvaluator::variablesIn('1 + 2'));
    }
}
