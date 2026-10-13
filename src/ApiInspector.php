<?php
declare(strict_types=1);

namespace PoolProFlorida;

/**
 * Redact every API value while preserving the returned JSON field structure.
 * This is intentionally NOT a raw customer-data dump.
 */
final class ApiInspector
{
    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 12) return '[nested content omitted]';
        if ($value instanceof \stdClass) {
            $safe = new \stdClass();
            $count = 0;
            foreach (get_object_vars($value) as $key => $child) {
                if (++$count > 60) {
                    $safe->__omitted_fields__ = '[additional fields omitted]';
                    break;
                }
                // Values are always masked. Reject unusual dynamically generated
                // property names so a PII-derived object key is not displayed.
                $name = preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $key)
                    ? $key : 'redacted_key_' . $count;
                $safe->{$name} = self::redact($child, $depth + 1);
            }
            return $safe;
        }
        if (is_array($value)) {
            $safe = [];
            foreach (array_slice($value, 0, 5) as $child) {
                $safe[] = self::redact($child, $depth + 1);
            }
            if (count($value) > 5) $safe[] = '[additional array items omitted]';
            return $safe;
        }
        if ($value === null) return null;
        if (is_string($value)) return $value === '' ? '[empty string]' : '[redacted string]';
        if (is_bool($value)) return '[redacted boolean]';
        if (is_int($value)) return '[redacted integer]';
        if (is_float($value)) return '[redacted number]';
        return '[redacted value]';
    }
}
