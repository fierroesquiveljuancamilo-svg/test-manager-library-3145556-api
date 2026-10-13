<?php

namespace App\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class Loan
{
    public readonly string $id;

    public readonly string $memberId;

    public readonly string $loanedByUserId;

    public readonly DateTimeImmutable $loanedAt;

    /** @var array<string, LoanItem> */
    private array $items = [];

    private bool $recorded = false;

    /**
     * Creates a transient draft. Call record() after adding one to three items;
     * a recorded loan permits only item returns.
     */
    public function __construct(string $id, string $memberId, string $loanedByUserId, DateTimeImmutable $loanedAt)
    {
        $this->id = Guard::uuid($id);
        $this->memberId = Guard::uuid($memberId);
        $this->loanedByUserId = Guard::uuid($loanedByUserId);
        $this->loanedAt = $loanedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function addItem(string $itemId, Book $book, string $categoryName): LoanItem
    {
        if ($this->recorded || count($this->items) === 3) {
            throw new DomainError('VALIDATION_ERROR', 'Un préstamo registrado no se modifica y admite como máximo tres líneas.');
        }

        $item = new LoanItem($itemId, $this->id, $book->id, $book->title(), $book->isbn, $categoryName, $this->loanedAt->add(new DateInterval('P14D')));
        if (isset($this->items[$item->id])) {
            throw new DomainError('VALIDATION_ERROR', 'El identificador de la línea está repetido.');
        }
        foreach ($this->items as $existing) {
            if ($existing->bookId === $book->id) {
                throw new DomainError('DUPLICATE_BOOK_IN_LOAN', 'No se puede repetir un libro en el préstamo.');
            }
        }

        $book->withdraw();
        $this->items[$item->id] = $item;

        return $item;
    }

    public function record(): void
    {
        $this->requireItems();
        $this->recorded = true;
    }

    /** @return list<LoanItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function returnItem(string $itemId, Book $book, DateTimeImmutable $at): LoanItem
    {
        $itemId = Guard::uuid($itemId);
        $item = $this->items[$itemId] ?? throw new DomainError('NOT_FOUND', 'No existe la línea de préstamo.');
        if ($item->returnedAt !== null) {
            throw new ItemAlreadyReturned;
        }
        if ($item->bookId !== $book->id) {
            throw new DomainError('VALIDATION_ERROR', 'El libro no corresponde a la línea del préstamo.');
        }
        if ($at < $this->loanedAt) {
            throw new DomainError('VALIDATION_ERROR', 'La devolución no puede ser anterior al préstamo.');
        }

        $returned = $item->returned($at);
        $book->restock();
        $this->items[$itemId] = $returned;
        $this->recorded = true;

        return $returned;
    }

    public function status(DateTimeImmutable $now): LoanStatus
    {
        $this->requireItems();
        $hasUnreturned = false;
        foreach ($this->items as $item) {
            if ($item->isOverdue($now)) {
                return LoanStatus::Overdue;
            }
            $hasUnreturned = $hasUnreturned || $item->returnedAt === null;
        }

        return $hasUnreturned ? LoanStatus::Open : LoanStatus::Closed;
    }

    private function requireItems(): void
    {
        if ($this->items === []) {
            throw new DomainError('VALIDATION_ERROR', 'Un préstamo debe contener al menos una línea.');
        }
    }
}
