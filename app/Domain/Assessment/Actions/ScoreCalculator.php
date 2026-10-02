<?php

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\GradeScale;
use App\Domain\Assessment\Models\GradeScaleBand;

/**
 * Pure score math. Works in integer hundredths so a 7.5/10 is exactly
 * 75.00% on every DB engine — no float drift between SQLite, PostgreSQL
 * and production MySQL.
 */
class ScoreCalculator
{
    public static function toHundredths(string|int|float $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function fromHundredths(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
    }

    /**
     * @return string normalized percent with two decimals, e.g. "75.00"
     */
    public function normalizedPercent(string|int|float $rawScore, string|int|float $maxScore): string
    {
        $max = self::toHundredths($maxScore);

        if ($max <= 0) {
            return '0.00';
        }

        $percentHundredths = (int) round(self::toHundredths($rawScore) * 10000 / $max);

        return self::fromHundredths($percentHundredths);
    }

    /**
     * Highest band whose min_percent is at or below the percent. Null when
     * the school has not configured a scale — never a made-up grade.
     */
    public function finalGrade(?GradeScale $scale, string $normalizedPercent): ?string
    {
        if ($scale === null) {
            return null;
        }

        $percent = self::toHundredths($normalizedPercent);

        /** @var GradeScaleBand|null $band */
        $band = $scale->bands
            ->sortByDesc(fn (GradeScaleBand $band) => self::toHundredths($band->min_percent))
            ->first(fn (GradeScaleBand $band) => self::toHundredths($band->min_percent) <= $percent);

        return $band?->label;
    }
}
