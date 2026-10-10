<?php

namespace App\Domain\Tax\Services;

use Brick\Math\BigDecimal;

/**
 * The outcome of taxing the lines of one document: the lines with their amount turned into the net base (and the amount as entered kept
 * beside it), the facts to snapshot per taxed line and the tax total. When no line names a tax code the application is inactive and the
 * document keeps its previous behaviour (a manual header tax).
 */
final class TaxApplication
{
    /**
     * @param  list<array<string,mixed>>  $lines
     * @param  array<int,array{code:\App\Domain\Tax\Models\TaxCode,rate:\App\Domain\Tax\Models\TaxRate,calc:array<string,BigDecimal>}>  $facts  line index => facts of a taxed line
     */
    public function __construct(public readonly array $lines, public readonly array $facts, public readonly BigDecimal $taxTotal) {}

    public function active(): bool
    {
        return $this->facts !== [];
    }
}
