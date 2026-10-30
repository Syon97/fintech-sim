<?php

namespace App\Monitoring\Support;

final class Money
{
    public static function myr(int $sen): string
    {
        return 'RM ' . number_format($sen / 100, 2);
    }
}