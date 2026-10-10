<?php

namespace Tests\Unit;

use App\Domain\Tax\Services\TaxCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/** OA4: the one tax calculation, as a pure function (no database). */
class TaxCalculatorTest extends TestCase
{
    private function calc(string $method, string $treatment, string $entered, string $rate, int $scale = 2): array
    {
        return array_map(fn ($v) => (string) $v, TaxCalculator::calculate($method, $treatment, BigDecimal::of($entered), BigDecimal::of($rate), $scale));
    }

    public function test_exclusive_tax_is_added_on_top_of_the_base(): void
    {
        $this->assertSame(['entered' => '1000000.0000', 'base' => '1000000.0000', 'tax' => '110000.0000', 'rate' => '11.000000'], $this->calc('EXCLUSIVE', 'STANDARD', '1000000', '11'));
    }

    public function test_inclusive_tax_is_carved_out_of_the_amount_and_base_plus_tax_is_what_was_entered(): void
    {
        $r = $this->calc('INCLUSIVE', 'STANDARD', '1110000', '11');

        $this->assertSame(['1000000.0000', '110000.0000'], [$r['base'], $r['tax']]);

        $odd = $this->calc('INCLUSIVE', 'STANDARD', '100000.01', '11'); // 100000.01 x 11 / 111 = 9909.910090..
        $this->assertSame('9909.9100', $odd['tax']);
        $this->assertTrue(BigDecimal::of($odd['base'])->plus($odd['tax'])->isEqualTo('100000.01'));
    }

    public function test_half_up_rounding_at_the_currency_scale(): void
    {
        $this->assertSame('0.0600', $this->calc('EXCLUSIVE', 'STANDARD', '0.55', '11')['tax']);    // 0.0605 -> 0.06
        $this->assertSame('0.0700', $this->calc('EXCLUSIVE', 'STANDARD', '0.65', '11')['tax']);    // 0.0715 -> 0.07
        $this->assertSame('1.0000', $this->calc('EXCLUSIVE', 'STANDARD', '9.09', '11', 0)['tax']); // 0.9999 -> 1 at scale 0
    }

    public function test_zero_rated_exempt_and_zero_rate_give_no_tax_and_keep_the_entered_amount_as_base(): void
    {
        foreach ([['ZERO_RATED', '11'], ['EXEMPT', '11'], ['STANDARD', '0']] as [$treatment, $rate]) {
            $r = $this->calc('INCLUSIVE', $treatment, '500000', $rate);
            $this->assertSame(['500000.0000', '0.0000'], [$r['base'], $r['tax']], $treatment);
        }
    }

    public function test_the_same_inputs_always_give_the_same_result(): void
    {
        $this->assertSame($this->calc('INCLUSIVE', 'STANDARD', '123456.78', '12.5', 2), $this->calc('INCLUSIVE', 'STANDARD', '123456.78', '12.5', 2));
    }
}
