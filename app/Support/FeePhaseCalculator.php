<?php

namespace App\Support;

class FeePhaseCalculator
{
    public const STAGES = ['initial', 'first', 'second', 'third', 'final'];

    public static function normalizePhaseFees(mixed $payload): array
    {
        $normalized = [];

        foreach (self::STAGES as $stage) {
            $items = [];

            if (is_array($payload) && isset($payload[$stage]) && is_array($payload[$stage])) {
                foreach ($payload[$stage] as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }

            $normalized[$stage] = $items;
        }

        return $normalized;
    }

    public static function computeExpectedByStage(mixed $payload): array
    {
        $phaseFees = self::normalizePhaseFees($payload);
        $expected = [];

        foreach (self::STAGES as $stage) {
            $sum = 0.0;

            foreach ($phaseFees[$stage] as $item) {
                $sum += self::resolveItemFee($item);
            }

            $expected[$stage] = round(max($sum, 0.0), 2);
        }

        return $expected;
    }

    public static function computeExpectedForStage(mixed $payload, string $stage): float
    {
        $expected = self::computeExpectedByStage($payload);

        return (float) ($expected[$stage] ?? 0.0);
    }

    private static function resolveItemFee(array $item): float
    {
        $directCandidates = [
            $item['selectedFee'] ?? null,
            $item['selected_fee'] ?? null,
            $item['estimatedFee'] ?? null,
            $item['estimated_fee'] ?? null,
            $item['fee'] ?? null,
        ];

        foreach ($directCandidates as $candidate) {
            $parsed = self::parseAmount($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $rangeCandidates = [
            $item['estimationFeesRange'] ?? null,
            $item['estimation_fees_range'] ?? null,
            $item['estimationFees'] ?? null,
            $item['estimation_fees'] ?? null,
        ];

        foreach ($rangeCandidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $rangeAmount = self::parseRangeAmount($candidate);
            if ($rangeAmount !== null) {
                return $rangeAmount;
            }
        }

        return 0.0;
    }

    private static function parseAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return max((float) $value, 0.0);
        }

        if (!is_string($value)) {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.,-]/', '', $value);
        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        $normalized = str_replace(',', '', $cleaned);
        if (!is_numeric($normalized)) {
            return null;
        }

        return max((float) $normalized, 0.0);
    }

    private static function parseRangeAmount(string $range): ?float
    {
        preg_match_all('/\d+(?:[\.,]\d+)?/', $range, $matches);
        $numbers = $matches[0] ?? [];

        if (count($numbers) === 0) {
            return null;
        }

        $first = (float) str_replace(',', '', $numbers[0]);

        if (count($numbers) === 1) {
            return max($first, 0.0);
        }

        $second = (float) str_replace(',', '', $numbers[1]);

        return round(max(($first + $second) / 2, 0.0), 2);
    }
}
