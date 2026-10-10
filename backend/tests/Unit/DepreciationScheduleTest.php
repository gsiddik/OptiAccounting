<?php

namespace Tests\Unit;

use App\Domain\FixedAsset\Services\Depreciation\DepreciationMethods;
use App\Domain\FixedAsset\Services\Depreciation\ScheduleBuilder;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/** OA4: the deterministic depreciation schedules (no database; pure functions of the asset's terms). */
class DepreciationScheduleTest extends TestCase
{
    public function test_straight_line_adds_up_to_the_basis_exactly_and_the_last_month_absorbs_the_rounding(): void
    {
        $rows = ScheduleBuilder::build('STRAIGHT_LINE', '1000000.0000', '0.0000', 3, '2026-03-10', 'CAPITALIZATION_MONTH', 2, null);

        $this->assertSame(['333333.3300', '333333.3300', '333333.3400'], array_column($rows, 'amount'));
        $this->assertSame('1000000.0000', $rows[2]['accumulated_after']);
        $this->assertSame('0.0000', $rows[2]['book_value_after']);
        $this->assertSame(['2026-03-01', '2026-04-01', '2026-05-01'], array_column($rows, 'period_start'));
        $this->assertSame(['2026-03-31', '2026-04-30', '2026-05-31'], array_column($rows, 'period_end'));
    }

    public function test_the_same_terms_always_give_the_same_schedule(): void
    {
        $args = ['STRAIGHT_LINE', '9999999.9900', '1234.5600', 37, '2026-01-31', 'NEXT_MONTH', 2, null];

        $this->assertSame(ScheduleBuilder::build(...$args), ScheduleBuilder::build(...$args));
    }

    public function test_the_start_policy_decides_the_first_month_and_leap_years_are_respected(): void
    {
        $next = ScheduleBuilder::build('STRAIGHT_LINE', '24.0000', '0.0000', 24, '2027-01-31', 'NEXT_MONTH', 0, null);
        $this->assertSame('2027-02-01', $next[0]['period_start']);
        $this->assertSame('2027-02-28', $next[0]['period_end']);
        $this->assertSame('2029-01-31', $next[23]['period_end']);

        $same = ScheduleBuilder::build('STRAIGHT_LINE', '24.0000', '0.0000', 24, '2027-01-31', 'CAPITALIZATION_MONTH', 0, null);
        $this->assertSame('2027-01-01', $same[0]['period_start']);
        $this->assertSame('2028-02-29', $same[13]['period_end'], 'February 2028 has 29 days');
    }

    public function test_the_residual_value_is_never_depreciated(): void
    {
        $rows = ScheduleBuilder::build('STRAIGHT_LINE', '12000000.0000', '2000000.0000', 10, '2026-03-01', 'CAPITALIZATION_MONTH', 2, null);

        $this->assertCount(10, $rows);
        $this->assertSame('10000000.0000', $rows[9]['accumulated_after']);
        $this->assertSame('2000000.0000', $rows[9]['book_value_after']);
    }

    public function test_declining_balance_never_exceeds_the_basis_and_ends_exactly_at_the_residual(): void
    {
        $rows = ScheduleBuilder::build('DECLINING_BALANCE', '12000.0000', '1000.0000', 24, '2026-03-31', 'CAPITALIZATION_MONTH', 2, ['factor' => '2.00']);

        $this->assertCount(24, $rows);
        $this->assertSame('1000.0000', $rows[23]['book_value_after']);
        $this->assertSame('11000.0000', $rows[23]['accumulated_after']);
        $previous = null;
        foreach ($rows as $row) {
            $this->assertTrue(BigDecimal::of($row['amount'])->isPositive());
            $this->assertTrue(BigDecimal::of($row['book_value_after'])->isGreaterThanOrEqualTo('1000'));
            if ($previous !== null && $row['sequence_no'] < 24) {
                $this->assertTrue(BigDecimal::of($row['amount'])->isLessThanOrEqualTo($previous), 'the monthly amount declines');
            }
            $previous = $row['amount'];
        }
        $this->assertSame('1000.0000', $rows[0]['amount'], '12000 x 2 / 24');
    }

    public function test_a_method_that_never_depreciates_has_no_schedule(): void
    {
        $this->assertSame([], ScheduleBuilder::build('NONE', '5000000.0000', '0.0000', null, '2026-03-01', 'CAPITALIZATION_MONTH', 2, null));
    }

    public function test_the_declining_balance_factor_is_validated_and_unknown_methods_are_refused(): void
    {
        $method = DepreciationMethods::for('DECLINING_BALANCE');
        $this->assertSame(['factor' => '2.00'], $method->params(null));
        $this->assertSame(['factor' => '1.50'], $method->params(['factor' => '1.5']));
        foreach (['0.5', '4.5', '2.123', 'abc'] as $bad) {
            try {
                $method->params(['factor' => $bad]);
                $this->fail("factor {$bad} was accepted");
            } catch (DomainException $e) {
                $this->assertSame('DEPRECIATION_PARAMS_INVALID', $e->errorCode);
            }
        }

        $this->expectException(DomainException::class);
        DepreciationMethods::for('SUM_OF_YEARS');
    }
}
