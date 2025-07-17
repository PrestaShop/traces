<?php

namespace PrestaShop\Traces\DTO;

class Employee
{
    public function __construct(
        public readonly string $login,
        /**
         * @var TimeFrame[]
         */
        public readonly array $timeFrames,
    ) {
    }
}
