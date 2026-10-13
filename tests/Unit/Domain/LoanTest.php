<?php

namespace Tests\Unit\Domain;

use App\Domain\Book;
use App\Domain\BookNotAvailable;
use App\Domain\DomainError;
use App\Domain\Isbn;
use App\Domain\ItemAlreadyReturned;
use App\Domain\Loan;
use App\Domain\LoanStatus;
use App\Domain\Quantity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LoanTest extends TestCase
{
    private function id(int $number): string
    {
        return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number);
    }

    private function book(int $number = 1, int $copies = 2): Book
    {
        return new Book($this->id($number), 'Original title', 'Author', new Isbn('9780307474728'), '11111111-1111-4111-8111-111111111111', new Quantity($copies));
    }

    private function loan(?DateTimeImmutable $at = null): Loan
    {
        return new Loan($this->id(10), $this->id(20), $this->id(30), $at ?? new DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function test_adding_item_withdraws_one_copy_and_freezes_book_values_for_fourteen_days(): void
    {
        $loan = $this->loan(new DateTimeImmutable('2026-03-01T12:00:00-05:00'));
        $book = $this->book();
        $item = $loan->addItem($this->id(100), $book, 'Novela');
        $this->assertSame(1, $book->availableCopies());
        $this->assertSame($loan->id, $item->loanId);
        $this->assertSame($book->id, $item->bookId);
        $this->assertSame('2026-03-15T17:00:00+00:00', $item->dueAt->format('c'));
        $this->assertSame(14 * 86400, $item->dueAt->getTimestamp() - $loan->loanedAt->getTimestamp());
        $this->assertSame('UTC', $loan->loanedAt->getTimezone()->getName());
        $book->revise('Changed title', 'Changed author', '22222222-2222-4222-8222-222222222222', new Quantity(2));
        $this->assertSame('Original title', $item->bookTitle);
        $this->assertSame('Novela', $item->categoryName);
        $this->assertSame('9780307474728', $item->isbn->value);
    }

    public function test_duplicate_book_is_rejected_before_inventory_changes(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        $loan->addItem($this->id(100), $book, 'Novela');
        try {
            $loan->addItem($this->id(101), $book, 'Novela');
            $this->fail('Repeated books must be rejected.');
        } catch (DomainError $error) {
            $this->assertSame('DUPLICATE_BOOK_IN_LOAN', $error->errorCode);
        }
        $this->assertSame(1, $book->availableCopies());
        $this->assertCount(1, $loan->items());
    }

    public function test_fourth_item_is_rejected_without_withdrawing(): void
    {
        $loan = $this->loan();
        for ($i = 1; $i <= 3; $i++) {
            $loan->addItem($this->id(100 + $i), $this->book($i), 'Novela');
        }
        $fourth = $this->book(4);
        try {
            $loan->addItem($this->id(104), $fourth, 'Novela');
            $this->fail('A loan admits at most three lines.');
        } catch (DomainError) {
            $this->assertCount(3, $loan->items());
            $this->assertSame(2, $fourth->availableCopies());
        }
    }

    public function test_empty_draft_cannot_be_recorded_or_have_a_calculated_state(): void
    {
        $loan = $this->loan();
        try {
            $loan->record();
            $this->fail('A recorded loan must have at least one line.');
        } catch (DomainError $error) {
            $this->assertSame('VALIDATION_ERROR', $error->errorCode);
        }
        $this->expectException(DomainError::class);
        $loan->status(new DateTimeImmutable('2026-01-01Z'));
    }

    public function test_recorded_loan_cannot_gain_items(): void
    {
        $loan = $this->loan();
        $loan->addItem($this->id(100), $this->book(), 'Novela');
        $loan->record();
        $book = $this->book(2);
        try {
            $loan->addItem($this->id(101), $book, 'Novela');
            $this->fail('Recorded loans cannot be edited.');
        } catch (DomainError) {
            $this->assertCount(1, $loan->items());
            $this->assertSame(2, $book->availableCopies());
        }
    }

    public function test_unavailable_book_is_not_added(): void
    {
        $loan = $this->loan();
        $book = $this->book(1, 1);
        $book->withdraw();
        try {
            $loan->addItem($this->id(100), $book, 'Novela');
            $this->fail('An unavailable book cannot be added.');
        } catch (BookNotAvailable) {
            $this->assertSame([], $loan->items());
            $this->assertSame(0, $book->availableCopies());
        }
    }

    public function test_invalid_snapshot_is_rejected_before_withdrawing(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        try {
            $loan->addItem($this->id(100), $book, ' ');
            $this->fail('Category name is required.');
        } catch (DomainError) {
            $this->assertSame([], $loan->items());
            $this->assertSame(2, $book->availableCopies());
        }
    }

    public function test_duplicate_item_id_is_rejected_before_withdrawing(): void
    {
        $loan = $this->loan();
        $loan->addItem($this->id(100), $this->book(), 'Novela');
        $second = $this->book(2);
        try {
            $loan->addItem($this->id(100), $second, 'Novela');
            $this->fail('Line ids must be unique.');
        } catch (DomainError) {
            $this->assertCount(1, $loan->items());
            $this->assertSame(2, $second->availableCopies());
        }
    }

    public function test_fake_clock_drives_open_overdue_and_closed_states(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $loan = $this->loan($clock->now());
        $book = $this->book();
        $loan->addItem($this->id(100), $book, 'Novela');
        $loan->record();
        $this->assertSame(LoanStatus::Open, $loan->status($clock->now()));
        $clock->advance('P14D');
        $this->assertSame(LoanStatus::Open, $loan->status($clock->now()));
        $clock->advance('PT1S');
        $this->assertSame(LoanStatus::Overdue, $loan->status($clock->now()));
        $returned = $loan->returnItem($this->id(100), $book, $clock->now());
        $this->assertSame(LoanStatus::Closed, $loan->status($clock->now()));
        $this->assertEquals($clock->now(), $returned->returnedAt);
        $this->assertSame(2, $book->availableCopies());
        $clock->advance('P30D');
        $this->assertSame(LoanStatus::Closed, $loan->status($clock->now()));
    }

    public function test_partial_return_does_not_close_loan_and_returned_lines_are_not_overdue(): void
    {
        $loan = $this->loan();
        $first = $this->book();
        $second = $this->book(2);
        $loan->addItem($this->id(100), $first, 'Novela');
        $loan->addItem($this->id(101), $second, 'Novela');
        $loan->record();
        $returned = $loan->returnItem($this->id(100), $first, new DateTimeImmutable('2026-01-02Z'));
        $this->assertSame(LoanStatus::Open, $loan->status(new DateTimeImmutable('2026-01-02Z')));
        $this->assertFalse($returned->isOverdue(new DateTimeImmutable('2026-02-01Z')));
        $this->assertSame(LoanStatus::Overdue, $loan->status(new DateTimeImmutable('2026-02-01Z')));
        $loan->returnItem($this->id(101), $second, new DateTimeImmutable('2026-02-01Z'));
        $this->assertSame(LoanStatus::Closed, $loan->status(new DateTimeImmutable('2026-02-01Z')));
    }

    public function test_double_return_does_not_restock_twice_or_change_timestamp(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        $loan->addItem($this->id(100), $book, 'Novela');
        $firstAt = new DateTimeImmutable('2026-01-02Z');
        $loan->returnItem($this->id(100), $book, $firstAt);
        try {
            $loan->returnItem($this->id(100), $book, new DateTimeImmutable('2026-01-03Z'));
            $this->fail('A line cannot be returned twice.');
        } catch (ItemAlreadyReturned $error) {
            $this->assertSame('ITEM_ALREADY_RETURNED', $error->errorCode);
        }
        $this->assertSame(2, $book->availableCopies());
        $this->assertEquals($firstAt, $loan->items()[0]->returnedAt);
    }

    public function test_wrong_book_and_early_return_leave_line_and_inventory_unchanged(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        $other = $this->book(2);
        $loan->addItem($this->id(100), $book, 'Novela');
        foreach ([[$other, '2026-01-02Z'], [$book, '2025-12-31Z']] as [$returnedBook, $at]) {
            try {
                $loan->returnItem($this->id(100), $returnedBook, new DateTimeImmutable($at));
                $this->fail('An invalid return must be rejected.');
            } catch (DomainError) {
                $this->assertNull($loan->items()[0]->returnedAt);
                $this->assertSame(1, $book->availableCopies());
                $this->assertSame(2, $other->availableCopies());
            }
        }
    }

    public function test_restock_failure_does_not_mark_line_returned(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        $loan->addItem($this->id(100), $book, 'Novela');
        $book->restock();
        try {
            $loan->returnItem($this->id(100), $book, new DateTimeImmutable('2026-01-02Z'));
            $this->fail('An inconsistent inventory must be rejected.');
        } catch (DomainError) {
            $this->assertNull($loan->items()[0]->returnedAt);
            $this->assertSame(2, $book->availableCopies());
        }
    }

    public function test_unknown_item_has_not_found_error(): void
    {
        $loan = $this->loan();
        try {
            $loan->returnItem($this->id(999), $this->book(), new DateTimeImmutable('2026-01-02Z'));
            $this->fail('Unknown lines must be rejected.');
        } catch (DomainError $error) {
            $this->assertSame('NOT_FOUND', $error->errorCode);
        }
    }

    public function test_return_timestamp_is_utc(): void
    {
        $loan = $this->loan();
        $book = $this->book();
        $loan->addItem($this->id(100), $book, 'Novela');
        $returned = $loan->returnItem($this->id(100), $book, new DateTimeImmutable('2026-01-02T10:00:00-05:00'));
        $this->assertSame('2026-01-02T15:00:00+00:00', $returned->returnedAt->format('c'));
    }
}
