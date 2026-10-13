<?php

namespace Tests\Unit\Domain;

use App\Domain\Book;
use App\Domain\BookHasOpenLoans;
use App\Domain\BookNotAvailable;
use App\Domain\DomainError;
use App\Domain\Isbn;
use App\Domain\Quantity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BookTest extends TestCase
{
    private function book(int $total = 2): Book
    {
        return new Book('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '  Original title  ', 'Author', new Isbn('9780307474728'), '11111111-1111-4111-8111-111111111111', new Quantity($total));
    }

    public function test_new_book_trims_title_and_starts_with_all_copies_available(): void
    {
        $book = $this->book();
        $this->assertSame('Original title', $book->title());
        $this->assertSame(2, $book->totalCopies());
        $this->assertSame(2, $book->availableCopies());
        $this->assertNull($book->deletedAt());
        $this->assertNull($book->coverKey());
    }

    public function test_withdraw_never_allows_negative_availability(): void
    {
        $book = $this->book(1);
        $book->withdraw();
        $this->assertSame(0, $book->availableCopies());
        try {
            $book->withdraw();
            $this->fail('An unavailable book must be rejected.');
        } catch (BookNotAvailable $error) {
            $this->assertSame('BOOK_NOT_AVAILABLE', $error->errorCode);
        }
        $this->assertSame(0, $book->availableCopies());
    }

    public function test_restock_never_exceeds_total_copies(): void
    {
        $book = $this->book();
        $book->withdraw();
        $book->restock();
        $this->assertSame(2, $book->availableCopies());
        try {
            $book->restock();
            $this->fail('Restocking a full book must be rejected.');
        } catch (DomainError $error) {
            $this->assertSame('VALIDATION_ERROR', $error->errorCode);
        }
        $this->assertSame(2, $book->availableCopies());
    }

    public function test_changing_total_adjusts_available_copies_by_the_same_difference(): void
    {
        $book = $this->book(3);
        $book->withdraw();
        $book->revise('New title', 'New author', $book->categoryId(), new Quantity(5));
        $this->assertSame(5, $book->totalCopies());
        $this->assertSame(4, $book->availableCopies());
        $book->revise('New title', 'New author', $book->categoryId(), new Quantity(1));
        $this->assertSame(1, $book->totalCopies());
        $this->assertSame(0, $book->availableCopies());
    }

    public function test_invalid_total_change_leaves_all_fields_unchanged(): void
    {
        $book = $this->book(3);
        $book->withdraw();
        $book->withdraw();
        try {
            $book->revise('Changed', 'Changed', '22222222-2222-4222-8222-222222222222', new Quantity(1));
            $this->fail('The total cannot be less than outstanding copies.');
        } catch (DomainError) {
            $this->assertSame('Original title', $book->title());
            $this->assertSame('Author', $book->author());
            $this->assertSame('11111111-1111-4111-8111-111111111111', $book->categoryId());
            $this->assertSame(3, $book->totalCopies());
            $this->assertSame(1, $book->availableCopies());
        }
    }

    public function test_blank_title_is_rejected(): void
    {
        $this->expectException(DomainError::class);
        new Book('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '  ', 'Author', new Isbn('9780307474728'), '11111111-1111-4111-8111-111111111111', new Quantity(1));
    }

    public function test_soft_delete_preserves_identity_and_uses_utc(): void
    {
        $book = $this->book();
        $at = new DateTimeImmutable('2026-01-01T10:00:00-05:00');
        $book->softDelete($at);
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $book->id);
        $this->assertSame('2026-01-01T15:00:00+00:00', $book->deletedAt()->format('c'));
        $book->softDelete(new DateTimeImmutable('2026-01-02Z'));
        $this->assertEquals($at, $book->deletedAt());
        $this->expectException(BookNotAvailable::class);
        $book->withdraw();
    }

    public function test_soft_delete_is_rejected_with_outstanding_copies(): void
    {
        $book = $this->book();
        $book->withdraw();
        try {
            $book->softDelete(new DateTimeImmutable('2026-01-01Z'));
            $this->fail('A book with open loans cannot be deleted.');
        } catch (BookHasOpenLoans $error) {
            $this->assertSame('BOOK_HAS_OPEN_LOANS', $error->errorCode);
        }
        $this->assertNull($book->deletedAt());
        $book->restock();
        $book->softDelete(new DateTimeImmutable('2026-01-01Z'));
        $this->assertNotNull($book->deletedAt());
    }

    public function test_cover_key_can_be_set_and_cleared_but_not_empty(): void
    {
        $book = $this->book();
        $book->setCoverKey('opaque-key');
        $this->assertSame('opaque-key', $book->coverKey());
        $book->setCoverKey(null);
        $this->assertNull($book->coverKey());
        $this->expectException(DomainError::class);
        $book->setCoverKey('');
    }
}
