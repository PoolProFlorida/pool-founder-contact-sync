<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class VCard {
    private static function escape(string $s): string {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $s);
    }
    public static function fromCustomer(array $c): string {
        if (empty($c['id'])) throw new \InvalidArgumentException('Missing customer id');
        $id = (string)$c['id'];
        $first = (string)($c['firstName'] ?? '');
        $last = (string)($c['lastName'] ?? '');
        $name = (string)($c['name'] ?? trim("$first $last"));
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'UID:' . self::escape('poolfounder-customer-' . $id),
            'FN:' . self::escape($name), 'N:' . self::escape($last) . ';' . self::escape($first) . ';;;'];
        foreach (['company'=>'ORG', 'email'=>'EMAIL', 'phone'=>'TEL'] as $field=>$key) {
            if (!empty($c[$field])) $lines[] = $key . ':' . self::escape((string)$c[$field]);
        }
        $addresses = [];
        if (isset($c['serviceLocations']) && is_array($c['serviceLocations'])) {
            foreach ($c['serviceLocations'] as $location) if (is_array($location) && isset($location['address']) && is_array($location['address'])) $addresses[] = $location['address'];
        }
        foreach (['serviceAddress', 'address'] as $field) if (isset($c[$field]) && is_array($c[$field])) $addresses[] = $c[$field];
        foreach ($addresses as $a) {
            $fields = ['', '', (string)($a['street'] ?? ''), (string)($a['city'] ?? ''), (string)($a['state'] ?? ''), (string)($a['zipCode'] ?? $a['postalCode'] ?? ''), (string)($a['country'] ?? '')];
            $lines[] = 'ADR;TYPE=WORK:' . implode(';', array_map(self::escape(...), $fields));
        }
        if (!empty($c['serviceDays']) && is_array($c['serviceDays'])) $lines[] = 'NOTE:' . self::escape('Service days: ' . implode(', ', $c['serviceDays']));
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines) . "\r\n";
    }
}
