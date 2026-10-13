<?php

namespace Tests\Unit\Domain;

use App\Domain\DateRange;
use App\Domain\DomainError;
use App\Domain\Isbn;
use App\Domain\Quantity;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function test_isbn_accepts_exactly_thirteen_ascii_digits_without_a_checksum_requirement(): void
    {
        $this->assertSame('0000000000000', (new Isbn('0000000000000'))->value);
        $this->assertSame('9780307474728', (new Isbn('9780307474728'))->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIsbns(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['978030747472'];
        yield 'long' => ['97803074747280'];
        yield 'letter' => ['978030747472X'];
        yield 'hyphens' => ['978-0307474728'];
        yield 'leading space' => [' 9780307474728'];
        yield 'trailing space' => ['9780307474728 '];
        yield 'trailing newline' => ["9780307474728\n"];
        yield 'unicode digits' => ['９７８０３０７４７４７２８'];
    }

    #[DataProvider('invalidIsbns')]
    public function test_isbn_rejects_invalid_values(string $value): void
    {
        $this->expectException(DomainError::class);
        new Isbn($value);
    }

    public function test_quantity_accepts_positive_integers(): void
    {
        $this->assertSame(1, (new Quantity(1))->value);
        $this->assertSame(100, (new Quantity(100))->value);
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantity_rejects_zero_and_negative_numbers(int $value): void
    {
        $this->expectException(DomainError::class);
        new Quantity($value);
    }

    /** @return iterable<array{int}> */
    public static function invalidQuantities(): iterable
    {
        yield [0];
        yield [-1];
        yield [-100];
    }

    public function test_date_range_is_inclusive_and_normalizes_to_utc(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-01-01T00:00:00-05:00'), new DateTimeImmutable('2026-01-02T00:00:00-05:00'));
        $this->assertSame('UTC', $range->start->getTimezone()->getName());
        $this->assertSame('UTC', $range->end->getTimezone()->getName());
        $this->assertTrue($range->contains($range->start));
        $this->assertTrue($range->contains($range->end));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-01-01T04:59:59Z')));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-01-02T05:00:01Z')));
    }

    public function test_date_range_allows_equal_instants_in_different_timezones(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-01-01T00:00:00-05:00'), new DateTimeImmutable('2026-01-01T05:00:00Z'));
        $this->assertEquals($range->start, $range->end);
    }

    public function test_inverted_date_range_has_the_documented_domain_error(): void
    {
        try {
            new DateRange(new DateTimeImmutable('2026-01-02Z'), new DateTimeImmutable('2026-01-01Z'));
            $this->fail('An inverted range must be rejected.');
        } catch (DomainError $error) {
            $this->assertSame('INVALID_DATE_RANGE', $error->errorCode);
        }
    }
}
