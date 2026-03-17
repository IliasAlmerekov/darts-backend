<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace App\Util;

/**
 * Resolves scalar SQL result values across drivers that normalize aliases differently.
 */
final class SqlResultValueAccessor
{
    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return bool
     */
    public static function has(array $row, array $keys): bool
    {
        foreach (self::expandCandidates($keys) as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<int|string>        $keys
     *
     * @return mixed
     */
    public static function find(array $row, array $keys): mixed
    {
        foreach (self::expandCandidates($keys) as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return $row[$candidate];
            }
        }

        return null;
    }

    /**
     * @param list<int|string> $keys
     *
     * @return list<int|string>
     */
    private static function expandCandidates(array $keys): array
    {
        $candidates = [];

        foreach ($keys as $key) {
            if (is_int($key)) {
                $candidates[] = $key;

                continue;
            }

            $snakeCaseKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $candidates[] = $key;
            $candidates[] = strtolower($key);
            $candidates[] = $snakeCaseKey;
            $candidates[] = str_replace('_', '', $snakeCaseKey);
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }
}
