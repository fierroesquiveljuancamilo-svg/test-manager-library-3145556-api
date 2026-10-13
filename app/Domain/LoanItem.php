<?php

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class LoanItem
{
    public string $id;

    public string $loanId;

    public string $bookId;

    public string $bookTitle;

    public string $categoryName;

    public DateTimeImmutable $dueAt;

    public ?DateTimeImmutable $returnedAt;

    public function __construct(
        string $id,
        string $loanId,
        string $bookId,
        string $bookTitle,
        public Isbn $isbn,
        string $categoryName,
        DateTimeImmutable $dueAt,
        ?DateTimeImmutable $returnedAt = null,
    ) {
        $this->id = Guard::uuid($id);
        $this->loanId = Guard::uuid($loanId);
        $this->bookId = Guard::uuid($bookId);
        $this->bookTitle = Guard::text($bookTitle, 250);
        $this->categoryName = Guard::text($categoryName, 120);
        $utc = new DateTimeZone('UTC');
        $this->dueAt = $dueAt->setTimezone($utc);
        $this->returnedAt = $returnedAt?->setTimezone($utc);
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->returnedAt === null && $this->dueAt < $now;
    }

    public function returned(DateTimeImmutable $at): self
    {
        if ($this->returnedAt !== null) {
            throw new ItemAlreadyReturned;
        }

        return new self($this->id, $this->loanId, $this->bookId, $this->bookTitle, $this->isbn, $this->categoryName, $this->dueAt, $at);
    }
}
