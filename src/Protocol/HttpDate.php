<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

final class HttpDate
{
    private const array MONTHS = [
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12,
    ];

    public static function parse(string $value, ?int $now = null): ?int
    {
        $value = trim($value);

        if (preg_match(
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), ([0-9]{2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) ([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT$/iD',
            $value,
            $matches,
        ) === 1) {
            return self::timestamp(
                weekday: $matches[1],
                day: (int) $matches[2],
                month: $matches[3],
                year: (int) $matches[4],
                hour: (int) $matches[5],
                minute: (int) $matches[6],
                second: (int) $matches[7],
                longWeekday: false,
            );
        }

        if (preg_match(
            '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), ([0-9]{2})-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT$/iD',
            $value,
            $matches,
        ) === 1) {
            $currentYear = (int) gmdate('Y', $now ?? time());
            $year = intdiv($currentYear, 100) * 100 + (int) $matches[4];

            if ($year > $currentYear + 50) {
                $year -= 100;
            }

            return self::timestamp(
                weekday: $matches[1],
                day: (int) $matches[2],
                month: $matches[3],
                year: $year,
                hour: (int) $matches[5],
                minute: (int) $matches[6],
                second: (int) $matches[7],
                longWeekday: true,
            );
        }

        if (preg_match(
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (?: ([0-9])|([0-9]{2})) ([0-9]{2}):([0-9]{2}):([0-9]{2}) ([0-9]{4})$/iD',
            $value,
            $matches,
        ) === 1) {
            $day = $matches[3] !== '' ? (int) $matches[3] : (int) $matches[4];

            return self::timestamp(
                weekday: $matches[1],
                day: $day,
                month: $matches[2],
                year: (int) $matches[8],
                hour: (int) $matches[5],
                minute: (int) $matches[6],
                second: (int) $matches[7],
                longWeekday: false,
            );
        }

        return null;
    }

    public static function format(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s \\G\\M\\T', $timestamp);
    }

    private static function timestamp(
        string $weekday,
        int $day,
        string $month,
        int $year,
        int $hour,
        int $minute,
        int $second,
        bool $longWeekday,
    ): ?int {
        $monthNumber = self::MONTHS[strtolower($month)] ?? null;

        if (
            $monthNumber === null
            || !checkdate($monthNumber, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 60
        ) {
            return null;
        }

        $leapSecond = $second === 60;
        $timestamp = gmmktime($hour, $minute, min($second, 59), $monthNumber, $day, $year);

        if ($timestamp === false) {
            return null;
        }

        if ($leapSecond) {
            ++$timestamp;
        }

        $expectedWeekday = gmdate($longWeekday ? 'l' : 'D', $timestamp - ($leapSecond ? 1 : 0));

        return strcasecmp($weekday, $expectedWeekday) === 0 ? $timestamp : null;
    }

    private function __construct() {}
}
