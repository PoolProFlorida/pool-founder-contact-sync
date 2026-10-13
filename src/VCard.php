<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class VCard
{
    private static function escape(string $s): string
    {
        return str_replace(
            ["\\", ";", ",", "\r\n", "\n", "\r"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
            $s
        );
    }

    /** Fold long content lines at 75 UTF-8 octets, as required by vCard 3.0. */
    private static function fold(string $line): string
    {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) throw new \InvalidArgumentException('Invalid Unicode in contact field');
        $output = [];
        $current = '';
        $bytes = 0;
        foreach ($chars as $char) {
            $length = strlen($char);
            if ($bytes + $length > 75) {
                $output[] = $current;
                $current = ' ';
                $bytes = 1;
            }
            $current .= $char;
            $bytes += $length;
        }
        $output[] = $current;
        return implode("\r\n", $output);
    }

    private static function address(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? ['street' => $value] : null;
        }
        return is_array($value) ? $value : null;
    }

    public static function fromCustomer(array $c): string
    {
        if (!isset($c['id']) || !is_scalar($c['id']) || (string)$c['id'] === '') {
            throw new \InvalidArgumentException('Missing customer ID');
        }
        $id = (string)$c['id'];
        $first = is_scalar($c['firstName'] ?? null) ? (string)$c['firstName'] : '';
        $last = is_scalar($c['lastName'] ?? null) ? (string)$c['lastName'] : '';
        $name = is_scalar($c['name'] ?? null) ? trim((string)$c['name']) : '';
        if ($name === '') $name = trim("$first $last");
        if ($name === '' && is_scalar($c['company'] ?? null)) $name = trim((string)$c['company']);
        if ($name === '') $name = 'Unnamed customer';

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:' . self::escape('poolfounder-customer-' . $id),
            'FN:' . self::escape($name),
            'N:' . self::escape($last) . ';' . self::escape($first) . ';;;'
        ];
        // Customer records have a stable web profile URL. The browser may
        // prompt for sign-in; this is NOT a customer-portal/share link or
        // a promised mobile-app deep link.
        // Encode the ID as ONE path component to prevent URL injection.
        $lines[] = 'URL;TYPE=WORK:https://www.poolfounder.com/dashboard/customers/'
            . rawurlencode($id);

        foreach (['company' => 'ORG', 'email' => 'EMAIL', 'phone' => 'TEL'] as $field => $key) {
            if (is_scalar($c[$field] ?? null) && trim((string)$c[$field]) !== '') {
                $lines[] = $key . ':' . self::escape((string)$c[$field]);
            }
        }

        $addresses = [];
        if (is_array($c['serviceLocations'] ?? null)) {
            foreach ($c['serviceLocations'] as $location) {
                if (is_array($location) && isset($location['address'])) {
                    $a = self::address($location['address']);
                    if ($a !== null) $addresses[] = $a;
                }
            }
        }
        foreach (['serviceAddress', 'address'] as $field) {
            if (isset($c[$field])) {
                $a = self::address($c[$field]);
                if ($a !== null) $addresses[] = $a;
            }
        }

        $uniqueAddresses = [];
        foreach ($addresses as $a) {
            $street = (string)($a['street'] ?? $a['streetAddress'] ?? $a['addressLine1'] ?? '');
            $line2 = (string)($a['street2'] ?? $a['addressLine2'] ?? '');
            if ($line2 !== '') $street .= ($street !== '' ? "\n" : '') . $line2;
            $fields = [
                '', '', $street,
                (string)($a['city'] ?? ''),
                (string)($a['state'] ?? $a['stateCode'] ?? ''),
                (string)($a['zipCode'] ?? $a['postalCode'] ?? $a['zip'] ?? ''),
                (string)($a['country'] ?? '')
            ];
            if (implode('', $fields) === '') continue;
            $dedupeKey = json_encode($fields, JSON_THROW_ON_ERROR);
            $uniqueAddresses[$dedupeKey] = $fields;
        }
        foreach ($uniqueAddresses as $fields) {
            $lines[] = 'ADR;TYPE=WORK:' . implode(';', array_map(self::escape(...), $fields));
        }

        if (is_array($c['serviceDays'] ?? null)) {
            $days = array_values(array_filter($c['serviceDays'], 'is_string'));
            if ($days !== []) $lines[] = 'NOTE:' . self::escape('Service days: ' . implode(', ', $days));
        }
        $lines[] = 'END:VCARD';
        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }
}
