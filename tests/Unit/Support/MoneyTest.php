<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_integer_cents_pass_through(): void
    {
        $money = Money::fromApiValue(702513, 'cents');

        $this->assertSame(702513, $money->minorUnits);
        $this->assertSame('USD', $money->currency);
    }

    public function test_decimal_values_are_treated_as_major_units(): void
    {
        $money = Money::fromApiValue(167.89, 'cents');

        $this->assertSame(16789, $money->minorUnits);
    }

    public function test_numeric_strings_are_supported(): void
    {
        $this->assertSame(702513, Money::fromApiValue('702513', 'cents')->minorUnits);
        $this->assertSame(16789, Money::fromApiValue('167.89', 'cents')->minorUnits);
    }

    public function test_null_and_empty_values_become_zero(): void
    {
        $this->assertSame(0, Money::fromApiValue(null)->minorUnits);
        $this->assertSame(0, Money::fromApiValue('')->minorUnits);
    }

    public function test_negative_values_are_preserved(): void
    {
        $this->assertSame(-1250, Money::fromApiValue(-1250, 'cents')->minorUnits);
    }

    public function test_currency_is_retained(): void
    {
        $money = Money::fromApiValue(1000, 'cents', 'EUR');

        $this->assertSame('EUR', $money->currency);
        $this->assertSame(10.0, $money->toMajor());
    }

    public function test_major_unit_mode_multiplies_by_one_hundred(): void
    {
        $this->assertSame(1999, Money::fromApiValue(19.99, 'major')->minorUnits);
        $this->assertSame(1999, Money::fromApiValue('19.99', 'major')->minorUnits);
    }
}
