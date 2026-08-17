<?php

namespace App\Service;

/**
 * Persists named BMC connection profiles in a JSON file.
 *
 * Prefers Home Assistant add-on persistent storage (/data) when available.
 */
class ServerConfigStore
{
    private const FILENAME = 'ipmi-servers.json';

    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = ($filePath !== null && $filePath !== '')
            ? $filePath
            : $this->defaultPath();
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(): array
    {
        return $this->read()['servers'];
    }

    /**
     * @return array<string, string>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $server) {
            if (($server['id'] ?? '') === $id) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    public function save(array $server): array
    {
        $normalized = $this->normalize($server);
        $data = $this->read();
        $updated = false;

        foreach ($data['servers'] as $index => $existing) {
            if (($existing['id'] ?? '') === $normalized['id']) {
                $data['servers'][$index] = $normalized;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $data['servers'][] = $normalized;
        }

        $this->write($data);

        return $normalized;
    }

    public function delete(string $id): bool
    {
        $data = $this->read();
        $before = count($data['servers']);
        $data['servers'] = array_values(array_filter(
            $data['servers'],
            static fn (array $server): bool => ($server['id'] ?? '') !== $id
        ));

        if (count($data['servers']) === $before) {
            return false;
        }

        $this->write($data);

        return true;
    }

    private function defaultPath(): string
    {
        // Home Assistant add-ons persist /data across rebuilds.
        if (is_dir('/data')) {
            return '/data/'.self::FILENAME;
        }

        $varDir = dirname(__DIR__, 2).'/var';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0777, true);
        }

        return $varDir.'/'.self::FILENAME;
    }

    /**
     * @return array{servers: list<array<string, string>>}
     */
    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return ['servers' => []];
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return ['servers' => []];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['servers' => []];
        }

        $servers = [];
        if (is_array($decoded['servers'] ?? null)) {
            foreach ($decoded['servers'] as $server) {
                if (!is_array($server)) {
                    continue;
                }
                $servers[] = $this->normalize($server, false);
            }
        }

        return ['servers' => $servers];
    }

    /**
     * @param array{servers: list<array<string, string>>} $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $dir));
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $fh = fopen($this->filePath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $this->filePath));
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock "%s".', $this->filePath));
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        @chmod($this->filePath, 0600);
    }

    /**
     * @param array<mixed> $server
     *
     * @return array<string, string>
     */
    private function normalize(array $server, bool $generateId = true): array
    {
        $id = trim((string) ($server['id'] ?? ''));
        if ($id === '' && $generateId) {
            $id = bin2hex(random_bytes(8));
        }

        $name = trim((string) ($server['name'] ?? ''));
        $host = trim((string) ($server['host'] ?? ''));
        if ($name === '') {
            $name = $host !== '' ? $host : 'Unnamed server';
        }

        return [
            'id' => $id,
            'name' => $name,
            'host' => $host,
            'port' => trim((string) ($server['port'] ?? '623')) ?: '623',
            'user' => trim((string) ($server['user'] ?? '')),
            'password' => (string) ($server['password'] ?? ''),
            'interface' => trim((string) ($server['interface'] ?? '')),
            'kg_key' => (string) ($server['kg_key'] ?? ''),
            'privilege_level' => trim((string) ($server['privilege_level'] ?? '')),
            'extra' => trim((string) ($server['extra'] ?? '')),
        ];
    }
}
