<?php

namespace App\Domain;

use DateTimeImmutable;

final readonly class Member
{
    public string $id;

    public string $fullName;

    public string $documentNumber;

    public function __construct(string $id, string $fullName, string $documentNumber)
    {
        $this->id = Guard::uuid($id);
        $this->fullName = Guard::text($fullName, 200);
        $this->documentNumber = Guard::text($documentNumber, 30);
    }

    /**
     * The caller supplies the complete loan history for the member.
     *
     * @param  iterable<Loan>  $loans
     */
    public function assertCanBorrow(Quantity $requested, iterable $loans, DateTimeImmutable $now): void
    {
        $currentCount = 0;
        foreach ($loans as $loan) {
            if ($loan->memberId !== $this->id) {
                continue;
            }
            foreach ($loan->items() as $item) {
                if ($item->isOverdue($now)) {
                    throw new MemberHasOverdue;
                }
                if ($item->returnedAt === null) {
                    $currentCount++;
                }
            }
        }

        if ($requested->value > 3 - $currentCount) {
            throw new MemberLimitExceeded($currentCount, $requested->value);
        }
    }
}
