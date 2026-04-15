<?php

declare(strict_types=1);

namespace App\Service\SessionTimeCancel;

final class TransformTime
{
    private int $time;

    public function __construct(int $time)
    {
        $this->time = $time;
    }

    public function __toString()
    {
        return (string) $this->time;
    }

    public function toMinutes(): TransformTime
    {
        return $this;
    }

    public function toHours(): float|int
    {
        return $this->time / 60;
    }

    public function toSeconds(): float|int
    {
        return $this->time * 60;
    }
}
