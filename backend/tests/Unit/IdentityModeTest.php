<?php

namespace Tests\Unit;

use App\Support\IdentityMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IdentityModeTest extends TestCase
{
    public function test_accepts_the_two_supported_modes(): void
    {
        $this->assertSame('standalone', IdentityMode::assertValid('standalone'));
        $this->assertSame('optinexus', IdentityMode::assertValid('optinexus'));
    }

    public function test_rejects_an_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentityMode::assertValid('saas');
    }
}
