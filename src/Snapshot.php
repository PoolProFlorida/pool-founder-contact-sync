<?php
declare(strict_types=1);
namespace PoolProFlorida;

final class Snapshot
{
    private const MAX_CONTACTS = 20000;

    public static function file(): string { return dirname(__DIR__) . '/storage/contacts.json'; }

    public static function load(): array
    {
        if (!is_file(self::file())) return ['updated_at' => null, 'contacts' => []];
        $data = json_decode((string)file_get_contents(self::file()), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['contacts'] ?? null)) {
            throw new \RuntimeException('Invalid saved snapshot');
        }
        return $data;
    }

    public static function refresh(PoolFounderClient $client): array
    {
        $records = $client->customers(); // Fetch ALL pages before touching disk.
        if (count($records) > self::MAX_CONTACTS) throw new \RuntimeException('Contact limit exceeded');
        $existing = self::load();
        if (count($records) === 0 && count($existing['contacts']) > 0) {
            throw new \RuntimeException('API returned zero contacts; previous snapshot retained');
        }

        $contacts = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['id']) || !is_scalar($record['id'])) {
                throw new \RuntimeException('Customer record is missing a valid ID');
            }
            $id = (string)$record['id'];
            if ($id === '') throw new \RuntimeException('Customer ID cannot be empty');
            $resource = hash('sha256', 'customer:' . $id) . '.vcf';
            if (isset($contacts[$resource])) throw new \RuntimeException('Duplicate customer ID encountered');
            $card = VCard::fromCustomer($record);
            $addresses = [];
            foreach (($record['serviceLocations'] ?? []) as $location) {
                if (is_array($location) && isset($location['address']) && is_array($location['address'])) {
                    $addresses[] = self::addressLabel($location['address']);
                }
            }
            foreach (['serviceAddress', 'address'] as $field) {
                if (is_array($record[$field] ?? null)) $addresses[] = self::addressLabel($record[$field]);
            }
            $contacts[$resource] = [
                'resource' => $resource,
                'name' => (string)($record['name'] ?? trim(($record['firstName'] ?? '') . ' ' . ($record['lastName'] ?? ''))),
                'email' => is_scalar($record['email'] ?? null) ? (string)$record['email'] : '',
                'phone' => is_scalar($record['phone'] ?? null) ? (string)$record['phone'] : '',
                'addresses' => array_values(array_unique(array_filter($addresses))),
                'service_days' => is_array($record['serviceDays'] ?? null) ? array_values(array_filter($record['serviceDays'], 'is_string')) : [],
                'vcard' => $card
            ];
        }
        uasort($contacts, static fn(array $a, array $b): int =>
            strcasecmp((string)$a['name'], (string)$b['name']) ?: strcmp((string)$a['resource'], (string)$b['resource'])
        );
        $data = ['updated_at' => gmdate(DATE_ATOM), 'contacts' => $contacts];

        $folder = dirname(self::file());
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create private storage');
        }
        $lock = fopen($folder . '/.sync.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock storage');
        try {
            $tmp = tempnam($folder, '.snapshot-');
            if ($tmp === false) throw new \RuntimeException('Cannot create temporary snapshot');
            try {
                chmod($tmp, 0600);
                $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) throw new \RuntimeException('Could not write complete snapshot');
                if (!rename($tmp, self::file())) throw new \RuntimeException('Could not replace snapshot');
                chmod(self::file(), 0600);
            } finally {
                if (is_file($tmp)) unlink($tmp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $data;
    }

    private static function addressLabel(array $a): string
    {
        $parts = [];
        foreach (['street','city','state','zipCode','country'] as $key) {
            if (isset($a[$key]) && is_scalar($a[$key]) && (string)$a[$key] !== '') {
                $parts[] = (string)$a[$key];
            }
        }
        return implode(', ', $parts);
    }
}
