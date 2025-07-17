<?php

namespace PrestaShop\Traces\DTO;

use DateTimeImmutable;

class TimeFrame
{
    public function __construct(
        public readonly DateTimeImmutable $startTime,
        public readonly ?DateTimeImmutable $endTime = null,
    ) {
    }
}
