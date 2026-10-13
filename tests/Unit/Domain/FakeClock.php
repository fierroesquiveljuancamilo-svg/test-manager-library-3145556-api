<?php

namespace Tests\Unit\Domain;

use DateInterval;
use DateTimeImmutable;

final class FakeClock
{
    public function __construct(private DateTimeImmutable $instant) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(string $interval): void
    {
        $this->instant = $this->instant->add(new DateInterval($interval));
    }
}
