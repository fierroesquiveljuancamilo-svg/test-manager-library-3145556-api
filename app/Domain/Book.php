<?php

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class Book
{
    public readonly string $id;

    private string $title;

    private string $author;

    public readonly Isbn $isbn;

    private string $categoryId;

    private Quantity $totalCopies;

    private int $availableCopies;

    private ?string $coverKey = null;

    private ?DateTimeImmutable $deletedAt = null;

    public function __construct(string $id, string $title, string $author, Isbn $isbn, string $categoryId, Quantity $totalCopies)
    {
        $this->id = Guard::uuid($id);
        $this->title = Guard::text($title, 250);
        $this->author = Guard::text($author, 200);
        $this->isbn = $isbn;
        $this->categoryId = Guard::uuid($categoryId);
        $this->totalCopies = $totalCopies;
        $this->availableCopies = $totalCopies->value;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function totalCopies(): int
    {
        return $this->totalCopies->value;
    }

    public function availableCopies(): int
    {
        return $this->availableCopies;
    }

    public function coverKey(): ?string
    {
        return $this->coverKey;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function withdraw(): void
    {
        if ($this->deletedAt !== null || $this->availableCopies === 0) {
            throw new BookNotAvailable;
        }

        $this->availableCopies--;
    }

    public function restock(): void
    {
        if ($this->availableCopies === $this->totalCopies->value) {
            throw new DomainError('VALIDATION_ERROR', 'Los ejemplares disponibles no pueden superar el total.');
        }

        $this->availableCopies++;
    }

    public function revise(string $title, string $author, string $categoryId, Quantity $totalCopies): void
    {
        $title = Guard::text($title, 250);
        $author = Guard::text($author, 200);
        $categoryId = Guard::uuid($categoryId);
        $available = $totalCopies->value - ($this->totalCopies->value - $this->availableCopies);
        if ($available < 0) {
            throw new DomainError('VALIDATION_ERROR', 'El total no puede ser menor que los ejemplares prestados.');
        }

        $this->title = $title;
        $this->author = $author;
        $this->categoryId = $categoryId;
        $this->totalCopies = $totalCopies;
        $this->availableCopies = $available;
    }

    public function setCoverKey(?string $coverKey): void
    {
        if ($coverKey !== null && (trim($coverKey) === '' || mb_strlen($coverKey) > 512)) {
            throw new DomainError('VALIDATION_ERROR', 'La clave de portada no puede estar vacía ni superar 512 caracteres.');
        }

        $this->coverKey = $coverKey;
    }

    public function softDelete(DateTimeImmutable $at): void
    {
        if ($this->availableCopies !== $this->totalCopies->value) {
            throw new BookHasOpenLoans;
        }

        $this->deletedAt ??= $at->setTimezone(new DateTimeZone('UTC'));
    }
}
