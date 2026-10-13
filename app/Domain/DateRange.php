<?php

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class DateRange
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        if ($end < $start) {
            throw new DomainError('INVALID_DATE_RANGE', 'El fin no puede ser anterior al inicio.');
        }

        $utc = new DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end->setTimezone($utc);
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && $instant <= $this->end;
    }
}
