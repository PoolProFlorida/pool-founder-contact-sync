<?php
declare(strict_types=1);
namespace PoolProFlorida;

/**
 * Search and paginate the already saved local snapshot.
 * Does not call the remote API or modify any contact.
 */
final class ContactSearch
{
    public static function stats(array $contacts): array
    {
        $stats = [
            'total' => 0,
            'with_phone' => 0,
            'with_address' => 0,
            'with_email' => 0
        ];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) continue;
            $stats['total']++;
            if (trim((string)($contact['phone'] ?? '')) !== '') $stats['with_phone']++;
            if (trim((string)($contact['email'] ?? '')) !== '') $stats['with_email']++;
            if (self::hasAddress($contact)) $stats['with_address']++;
        }
        return $stats;
    }

    public static function filter(array $contacts, string $query = '', string $only = 'all'): array
    {
        $query = trim($query);
        $result = [];
        foreach ($contacts as $resource => $contact) {
            if (!is_array($contact)) continue;
            $hasPhone = trim((string)($contact['phone'] ?? '')) !== '';
            $hasEmail = trim((string)($contact['email'] ?? '')) !== '';
            $hasAddress = self::hasAddress($contact);
            if ($only === 'missing-phone' && $hasPhone) continue;
            if ($only === 'missing-email' && $hasEmail) continue;
            if ($only === 'missing-address' && $hasAddress) continue;
            if ($only === 'with-address' && !$hasAddress) continue;

            if ($query !== '') {
                $values = [
                    (string)($contact['name'] ?? ''),
                    (string)($contact['phone'] ?? ''),
                    (string)($contact['email'] ?? ''),
                    implode(' ', self::addresses($contact)),
                    implode(' ', array_filter((array)($contact['service_days'] ?? []), 'is_string'))
                ];
                $found = false;
                foreach ($values as $value) {
                    if (function_exists('mb_stripos')) {
                        if (mb_stripos($value, $query, 0, 'UTF-8') !== false) { $found = true; break; }
                    } elseif (stripos($value, $query) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) continue;
            }
            $result[$resource] = $contact;
        }
        return $result;
    }

    public static function page(array $contacts, int $page, int $size = 50): array
    {
        $size = max(1, min(100, $size));
        $maxPage = max(1, (int)ceil(count($contacts) / $size));
        $page = max(1, min($maxPage, $page));
        return [
            'page' => $page,
            'pages' => $maxPage,
            'total' => count($contacts),
            'contacts' => array_slice($contacts, ($page - 1) * $size, $size, true)
        ];
    }

    private static function hasAddress(array $contact): bool
    {
        foreach (self::addresses($contact) as $address) {
            if (trim($address) !== '') return true;
        }
        return false;
    }

    private static function addresses(array $contact): array
    {
        $addresses = $contact['addresses'] ?? [];
        return is_array($addresses) ? array_values(array_filter($addresses, 'is_string')) : [];
    }
}
