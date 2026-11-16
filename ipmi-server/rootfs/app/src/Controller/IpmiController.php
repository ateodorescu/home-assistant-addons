<?php

/**
 * @author Adrian Teodorescu (ateodorescu@gmail.com)
 *
 * This class allows interaction with `ipmitool` executable.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

class IpmiController
{
    private array $ipmiTypes = ["lanplus", "lan", "imb", "open"];
    private array $sensorTypes = [
        'temperature' => 'degrees C',
        'voltage' => 'Volts',
        'fan' => 'RPM'
    ];
    private array $unitsOfMeasure = [
        'degrees C' => 'temperature',
        'Volts' => 'voltage',
        'RPM' => 'fan',
        'Amps' => 'current',
        'Watts' => 'power'
    ];
    private array $debug = [];
    private string $password = '';
    private string $kgKey = '';
    // Hard ceiling per ipmitool invoke. Keep below nginx/php-fpm (120s).
    const COMMAND_TIMEOUT = 45;
    const DEFAULT_PORT = 623;
    public const PASSWORD_HEADER = 'X-Ipmi-Password';
    public const KG_KEY_HEADER = 'X-Ipmi-Kg-Key';
    public const API_VERSION = 1;
    public const ADDON_VERSION = '2.7.6';
    public const CAPABILITY_RESILIENT_POLL = 'resilient_poll';
    public const CAPABILITY_SENSOR_TYPES_FILTER = 'sensor_types_filter';
    public const CAPABILITY_STATUSES = 'statuses';
    public const CAPABILITY_SDR_FALLBACK_BY_TYPE = 'sdr_fallback_by_type';

    /** @var list<string> */
    private const API_CAPABILITIES = [
        self::CAPABILITY_RESILIENT_POLL,
        self::CAPABILITY_SENSOR_TYPES_FILTER,
        self::CAPABILITY_STATUSES,
        self::CAPABILITY_SDR_FALLBACK_BY_TYPE,
    ];

    /**
     * Safe ipmitool `sdr type` queries for integration buckets.
     * Never include Memory — known to segfault some Intel BMCs (M50CYP).
     *
     * @var array<string, array{ipmi_type: string, unit: string}>
     */
    private const SDR_TYPE_FALLBACK_MAP = [
        'temperature' => ['ipmi_type' => 'Temperature', 'unit' => 'degrees C'],
        'fan' => ['ipmi_type' => 'Fan', 'unit' => 'RPM'],
        'voltage' => ['ipmi_type' => 'Voltage', 'unit' => 'Volts'],
        'current' => ['ipmi_type' => 'Current', 'unit' => 'Amps'],
        'power' => ['ipmi_type' => 'Power Supply', 'unit' => 'Watts'],
    ];

    public function meta(): JsonResponse
    {
        return new JsonResponse($this->apiMetadata());
    }

    public function index(Request $request): Response
    {
        // Browsers opening http://host:9595/ expect the Web UI; keep JSON for API clients.
        if ($this->shouldRedirectRootToUi($request)) {
            return new RedirectResponse('/ui');
        }

        if (empty($request->query->get('host'))) {
            return $this->finalizeJsonResponse([
                'success' => false,
                'message' => 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.',
            ]);
        }

        $this->hydrateSecrets($request);
        $requestedTypes = $this->parseRequestedSensorTypes($request);
        $info = $this->getDeviceInfo($request, $requestedTypes);

        if ($info['success']) {
            if ($this->isPowerOnlyPoll($requestedTypes)) {
                $info = array_merge($info, $this->emptySensorPayload());
            } else {
                $sensors = $this->getSensors($request, $requestedTypes);
                if (!empty($sensors['success'])) {
                    $info['sensors'] = $sensors['sensors'] ?? $this->emptySensorBuckets();
                    $info['states'] = $sensors['states'] ?? [];
                    $info['statuses'] = $sensors['statuses'] ?? [];
                } else {
                    // Pre-2.6.0 clients treat success=true as a complete poll and
                    // will not fall back to RMCP. Empty buckets would drop sensors.
                    $info['success'] = false;
                    if (!empty($sensors['message'])) {
                        $info['message'] = $sensors['message'];
                    }
                }
            }
        }

        return $this->finalizeJsonResponse($info);
    }

    public function command(Request $request): JsonResponse
    {
        $this->hydrateSecrets($request);
        $cmd = str_getcsv($request->query->get('params', ''), ' ', '"', '');
        $this->captureSecretsFromCommandArgs($cmd);
        $cmd = $this->mergeSecretArgs($cmd);
        array_unshift($cmd, 'ipmitool');
        $ret = $this->runCommand($cmd);
        $done = ($ret !== false);

        return $this->finalizeJsonResponse([
            'success' => $done,
            'output' => $done ? $ret : implode("\n", $this->debug)
        ]);
    }

    public function sensors(Request $request): JsonResponse
    {
        $this->hydrateSecrets($request);
        $requestedTypes = $this->parseRequestedSensorTypes($request);
        if ($this->isPowerOnlyPoll($requestedTypes)) {
            return $this->finalizeJsonResponse(array_merge(
                ['success' => true],
                $this->emptySensorPayload()
            ));
        }

        $response = $this->getSensors($request, $requestedTypes);
        if (!empty($response['success'])) {
            $response['sensors'] = $response['sensors'] ?? $this->emptySensorBuckets();
            $response['states'] = $response['states'] ?? [];
            $response['statuses'] = $response['statuses'] ?? [];
        }

        return $this->finalizeJsonResponse($response);
    }

    public function power_on(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'on');
    }

    public function power_off(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'off');
    }

    public function power_cycle(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'cycle');
    }

    public function power_reset(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'reset');
    }

    public function soft_shutdown(Request $request): JsonResponse
    {
        return $this->runChassisCommand($request, 'soft');
    }

    private function generateId($name): string
    {
        $id = preg_replace("/[^A-Za-z0-9 _]/", '', $name);

        return strtolower(str_replace(' ', '_', $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiMetadata(): array
    {
        return [
            'api_version' => self::API_VERSION,
            'addon_version' => self::ADDON_VERSION,
            'capabilities' => self::API_CAPABILITIES,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function finalizeJsonResponse(array $payload): JsonResponse
    {
        $payload = array_merge($this->apiMetadata(), $payload);
        $payload['debug'] = implode("\n", $this->debug);

        if (array_key_exists('message', $payload)) {
            $payload['message'] = $this->anonymizeSecrets((string) $payload['message']);
        }

        return new JsonResponse($payload);
    }

    /**
     * Standalone / direct :9595 visits hit GET / with Accept: text/html and no host.
     * Redirect those to /ui; leave JSON API clients (curl, HA integration) alone.
     */
    private function shouldRedirectRootToUi(Request $request): bool
    {
        if (!empty($request->query->get('host'))) {
            return false;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'text/html');
    }

    /**
     * Parse sensor_types query param.
     *
     * null  = omitted (legacy full discovery)
     * []    = explicit empty (power / device only)
     * list  = only these integration sensor groups
     *
     * @return list<string>|null
     */
    private function parseRequestedSensorTypes(Request $request): ?array
    {
        if (!$request->query->has('sensor_types')) {
            return null;
        }

        $raw = trim((string) $request->query->get('sensor_types', ''));
        if ($raw === '') {
            return [];
        }

        $requested = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && \in_array($part, $this->allSensorTypeKeys(), true)) {
                $requested[] = $part;
            }
        }

        return array_values(array_unique($requested));
    }

    /**
     * @return list<string>
     */
    private function allSensorTypeKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_values($this->unitsOfMeasure),
            ['time']
        )));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function emptySensorBuckets(): array
    {
        $buckets = [];
        foreach ($this->allSensorTypeKeys() as $type) {
            $buckets[$type] = [];
        }

        return $buckets;
    }

    /**
     * @return array{sensors: array<string, array<string, string>>, states: array<string, mixed>, statuses: array<string, string>}
     */
    private function emptySensorPayload(): array
    {
        return [
            'sensors' => $this->emptySensorBuckets(),
            'states' => [],
            'statuses' => [],
        ];
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldIncludeSensorType(?array $requestedTypes, string $type): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        return \in_array($type, $requestedTypes, true);
    }

    /**
     * Explicit empty sensor_types (power / device only). Omitted param is legacy full discovery.
     *
     * @param list<string>|null $requestedTypes
     */
    private function isPowerOnlyPoll(?array $requestedTypes): bool
    {
        return $requestedTypes !== null && count($requestedTypes) === 0;
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldSkipFru(?array $requestedTypes): bool
    {
        return $this->isPowerOnlyPoll($requestedTypes);
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldRunSdr(?array $requestedTypes): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        if (count($requestedTypes) === 0) {
            return false;
        }

        foreach ($requestedTypes as $type) {
            if ($type !== 'power' && $type !== 'time') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $requestedTypes
     */
    private function shouldRunDcmi(?array $requestedTypes): bool
    {
        if ($requestedTypes === null) {
            return true;
        }

        return \in_array('power', $requestedTypes, true)
            || \in_array('time', $requestedTypes, true);
    }

    /**
     * True when ipmitool returned a parseable reading (including 0 RPM under cr/nc/nr).
     * Skips empty rows and explicit "no reading" placeholders.
     */
    private function hasUsableSensorReading(string $reading): bool
    {
        $reading = trim($reading);
        if ($reading === '') {
            return false;
        }

        return stripos($reading, 'no reading') === false;
    }

    private function anonymizeSecrets(string $message): string
    {
        foreach ([$this->password, $this->kgKey] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '####', $message);
            }
        }

        return $message;
    }

    private function hydrateSecrets(Request $request): void
    {
        $this->password = $this->getSecret($request, 'password', self::PASSWORD_HEADER);
        $this->kgKey = $this->getSecret($request, 'kg_key', self::KG_KEY_HEADER);
    }

    private function getSecret(Request $request, string $field, string $header): string
    {
        $fromHeader = $request->headers->get($header);
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }

        $fromBody = $request->request->get($field);
        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        return trim((string) $request->query->get($field, ''));
    }

    /**
     * @param list<string> $cmd
     *
     * @return list<string>
     */
    private function mergeSecretArgs(array $cmd): array
    {
        $hasPassword = false;
        $hasKgKey = false;

        foreach ($cmd as $part) {
            if ($part === '-P') {
                $hasPassword = true;
            } elseif ($part === '-y') {
                $hasKgKey = true;
            }
        }

        // Insert secrets with connection options, BEFORE the IPMI subcommand
        // (e.g. "bmc info"). Options after the subcommand are ignored by ipmitool.
        $insertAt = $this->findIpmiSubcommandIndex($cmd);

        if (!$hasKgKey && $this->kgKey !== '') {
            array_splice($cmd, $insertAt, 0, ['-y', $this->kgKey]);
        }
        if (!$hasPassword && $this->password !== '') {
            array_splice($cmd, $insertAt, 0, ['-P', $this->password]);
        }

        return $cmd;
    }

    /**
     * Index of the first IPMI subcommand token (non-option), or end of array.
     *
     * @param list<string> $cmd
     */
    private function findIpmiSubcommandIndex(array $cmd): int
    {
        $optionsWithValue = [
            '-A', '-b', '-B', '-C', '-d', '-D', '-e', '-f', '-H', '-I', '-k', '-K',
            '-l', '-L', '-m', '-N', '-o', '-O', '-p', '-P', '-R', '-S', '-t', '-T',
            '-U', '-y', '-z',
        ];

        $count = \count($cmd);
        for ($i = 0; $i < $count; $i++) {
            $part = $cmd[$i];
            if ($part === '' || $part[0] !== '-') {
                return $i;
            }
            if (\in_array($part, $optionsWithValue, true)) {
                $i++; // skip option value
            }
        }

        return $count;
    }

    /**
     * @param list<string> $cmd
     */
    private function captureSecretsFromCommandArgs(array $cmd): void
    {
        foreach ($cmd as $index => $part) {
            $next = $cmd[$index + 1] ?? null;
            if (!is_string($next) || $next === '') {
                continue;
            }

            if ($part === '-P' && $this->password === '') {
                $this->password = $next;
            } elseif ($part === '-y' && $this->kgKey === '') {
                $this->kgKey = $next;
            }
        }
    }

    private function runChassisCommand(Request $request, string $type):JsonResponse
    {
        $this->hydrateSecrets($request);
        $done = false;
        $cmd = $this->getCommand($request);
        $interface = $request->query->get('interface', '');

        if ($cmd !== false) {
            if (empty($interface)) {
                foreach ($this->ipmiTypes as $interface) {
                    $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', $type]));

                    if ($ret) {
                        $done = true;
                        break;
                    }
                }
            }
            else {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', $type]));
                $done = $ret !== false;
            }
        }

        return $this->finalizeJsonResponse([
            'success' => $done
        ]);
    }

    private function runCommand($command, $ignoreErrors = false): bool|string
    {
        $result = $this->executeIpmiCommand($command, $ignoreErrors);
        if ($result !== false) {
            return $result;
        }

        // Super Micro / OpenSSL 3: try common RMCP+ cipher suites when default fails.
        // Suite 17 is modern but often unsupported; 3/8/12 are common on older BMCs.
        if (\in_array('lanplus', $command, true) && !\in_array('-C', $command, true)) {
            foreach ([3, 8, 12, 17, 15, 16, 6, 7, 11, 1, 2] as $cipher) {
                $retry = $command;
                $lanplusIdx = array_search('lanplus', $retry, true);
                if ($lanplusIdx === false) {
                    break;
                }
                array_splice($retry, $lanplusIdx + 1, 0, ['-C', (string) $cipher]);
                $result = $this->executeIpmiCommand($retry, true);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $command
     */
    private function executeIpmiCommand(array $command, bool $ignoreErrors = false): bool|string
    {
        $errorIntro = "Error occurred when running \"" . implode(" ", array_map($this->anonymizeSecrets(...), $command)) . "\".\n" ;

        try {
            $proc = new Process($command);
            $proc->setTimeout(self::COMMAND_TIMEOUT);
            // Keep any crash dumps out of the nginx document root (public/).
            $proc->setWorkingDirectory(sys_get_temp_dir());
            $opensslConf = '/etc/ssl/ipmitool-openssl.cnf';
            $env = [];
            if (is_readable($opensslConf)) {
                $env['OPENSSL_CONF'] = $opensslConf;
            }
            $proc->run(null, $env);
            $output = $proc->getOutput();
            $exitCode = $proc->getExitCode();

            if ($exitCode) {
                $message = $this->anonymizeSecrets($errorIntro.$proc->getErrorOutput());
                $this->debug[] = $message;

                if (!$ignoreErrors) {
                    error_log($message);
                }

                return false;
            }
        }
        catch (\Exception $exception) {
            // let's log this error
            $message = $this->anonymizeSecrets($errorIntro . $exception->getMessage());
            $this->debug[] = $message;

            if (!$ignoreErrors) {
                error_log($message);
            }

            return false;
        }

        return $output;
    }

    private function getCommand(Request $request): array|bool
    {
        $this->hydrateSecrets($request);
        $query = $request->query;
        $host = $query->get('host');

        if (empty($host)) {
            $message = 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.';
            $this->debug[] = $message;
            error_log($message);

            return false;
        }

        $user = $query->get('user', '');
        $pass = $this->password;
        $kg_key = $this->kgKey;
        $privilege_level = $query->get('privilege_level', '');
        $extra = $query->get('extra', '');

        $cmd = ['ipmitool', '-H', $host, '-p', $query->get('port', self::DEFAULT_PORT)];

        if (!empty($user)) {
            $cmd[] = '-U';
            $cmd[] = $user;
        }

        if (!empty($pass)) {
            $cmd[] = '-P';
            $cmd[] = $pass;
        }

        // Add Kg key for encrypted IPMI sessions
        if (!empty($kg_key)) {
            $cmd[] = '-y';
            $cmd[] = $kg_key;
        }

        // Add privilege level
        if (!empty($privilege_level)) {
            $cmd[] = '-L';
            $cmd[] = $privilege_level;
        }

        // Parse extra params if provided
        if (!empty($extra)) {
            // If extra contains multiple arguments, parse them properly
            $extraArgs = str_getcsv($extra, ' ', '"', '');
            foreach ($extraArgs as $arg) {
                if (!empty($arg)) {
                    $cmd[] = $arg;
                }
            }
        }

        return $cmd;
    }

    private function getDeviceInfo(Request $request, ?array $requestedTypes = null): array
    {
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getDeviceInfoByInterface($request, $interface, $requestedTypes);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getDeviceInfoByInterface($request, $interface, $requestedTypes);
        }

        return $response;
    }

    private function getDeviceInfoByInterface(Request $request, string $interface, ?array $requestedTypes = null): array
    {
        $response = [
            'success' => false
        ];

        $cmd = $this->getCommand($request);
        $on = false;
        $error = 'Wrong connection data provided!';

        if ($cmd === false) {
            $response['message'] = empty($request->query->get('host'))
                ? 'No hostname provided! Open /ui for the web UI, or pass ?host=... for the JSON API.'
                : $error;
        }
        else {
            try {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'bmc', 'info']));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    $device = $this->extractValuesFromResults($results);

                    if (!$this->shouldSkipFru($requestedTypes)) {
                        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'fru']));

                        if ($ret) {
                            $results = explode(PHP_EOL, $ret);
                            $device = array_merge($device, $this->extractValuesFromResults($results));
                        }
                    }

                    $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'chassis', 'power', 'status']));

                    if ($ret) {
                        $on = (trim($ret) === "Chassis Power is on");
                    }

                    $response['success'] = true;
                    $response['device'] = $device;
                    $response['power_on'] = $on;
                }
                else {
                    $response['message'] = $error;
                }

            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        }

        return $response;
    }

    private function extractValuesFromResults($results): array
    {
        $data = [];
        $results = array_values(array_filter($results, function ($line) {
            return str_contains($line, ':');
        }));

        if (!empty($results)) {
            foreach ($results as $result) {
                if (!empty($result)) {
                    $values = array_map('trim', explode(':', $result));
                    [$description, $value] = $values;

                    if (!empty($value)) {
                        $data[$this->generateId($description)] = $value;
                    }
                }
            }
        }

        return $data;
    }

    private function getSensors(Request $request, ?array $requestedTypes = null): array
    {
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getSensorsByInterface($request, $interface, $requestedTypes);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getSensorsByInterface($request, $interface, $requestedTypes);
        }

        return $response;
    }

    private function getSensorsByInterface(Request $request, string $interface, ?array $requestedTypes = null): array
    {
        $response = [
            'success' => false
        ];

        $states = [];
        $statuses = [];
        $sensorData = [];

        foreach ($this->unitsOfMeasure as $uom => $type) {
            $sensorData[$type] = [];
        }

        $cmd = $this->getCommand($request);

        if ($cmd !== false) {
            try {
                $sdrOk = true;
                if ($this->shouldRunSdr($requestedTypes)) {
                    $sdrOk = $this->extractFromSdrCommand($cmd, $interface, $sensorData, $states, $statuses, $requestedTypes);
                }
                // DCMI is optional; skip it when SDR was required and failed so
                // auto-detect can try the next interface without another timeout.
                if ($sdrOk && $this->shouldRunDcmi($requestedTypes)) {
                    $this->extractFromDcmiPowerReadingCommand($cmd, $interface, $sensorData, $states, $requestedTypes);
                }
                $response['success'] = $sdrOk;
            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        }

        $response['sensors'] = $sensorData;
        $response['states'] = $states;
        $response['statuses'] = $statuses;

        return $response;
    }

    private function extractFromSensorCommand(array $cmd, string $interface, array &$sensorData, array &$states, array &$statuses): bool
    {
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sensor']), true);

        if ($ret) {
            $lines = explode(PHP_EOL, $ret);

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $values = array_map('trim', explode('|', $line));

                        if (count($values) < 4 || !$this->hasUsableSensorReading($values[1])) {
                            continue;
                        }

                        $description = $values[0];
                        $id = $this->generateId($description);
                        $value = $values[1];
                        $uom = $values[2];
                        $type = array_key_exists($uom, $this->unitsOfMeasure) ? $this->unitsOfMeasure[$uom] : null;

                        if ($type) {
                            $sensorData[$type][$id] = $description;
                            $states[$id] = $value;
                            $statuses[$id] = $values[3];
                        }
                    }
                }
            }
        }

        return $ret !== false;
    }

    private function preg_array_key_exists($pattern, $array): array
    {
        $keys = array_keys($array);
        return preg_grep($pattern,$keys);
    }


    private function extractFromSdrCommand(
        array $cmd,
        string $interface,
        array &$sensorData,
        array &$states,
        array &$statuses,
        ?array $requestedTypes = null
    ): bool
    {
        // Non-empty sensor_types: skip full list and query matching sdr types only
        // (faster/safer on BMCs that segfault on `sdr list full`).
        $skipFullList = $requestedTypes !== null && count($requestedTypes) > 0;

        if (!$skipFullList) {
            $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sdr', 'list', 'full']), true);

            if ($ret !== false && trim($ret) !== '') {
                $this->parseSdrListFullOutput($ret, $sensorData, $states, $statuses, $requestedTypes);

                return true;
            }

            $this->debug[] = 'sdr list full failed or empty; falling back to per-type SDR queries'
                . ' (capability: ' . self::CAPABILITY_SDR_FALLBACK_BY_TYPE . ')';
        } else {
            $this->debug[] = 'sensor_types filter set; using per-type SDR queries'
                . ' (capability: ' . self::CAPABILITY_SDR_FALLBACK_BY_TYPE . ')';
        }

        return $this->extractFromSdrTypeFallback(
            $cmd,
            $interface,
            $sensorData,
            $states,
            $statuses,
            $requestedTypes
        );
    }

    /**
     * Parse `sdr list full` pipe-delimited lines into sensors/states/statuses.
     */
    private function parseSdrListFullOutput(
        string $output,
        array &$sensorData,
        array &$states,
        array &$statuses,
        ?array $requestedTypes = null
    ): void {
        $lines = explode(PHP_EOL, $output);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $values = array_map('trim', explode('|', $line));

            if (count($values) < 3 || !$this->hasUsableSensorReading($values[1])) {
                continue;
            }

            $description = $values[0];
            $id = $this->generateId($description);
            $value = $values[1];
            $status = $values[2];

            foreach ($this->unitsOfMeasure as $uom => $type) {
                if (!$this->shouldIncludeSensorType($requestedTypes, $type)) {
                    continue;
                }

                if (str_contains($value, $uom)) {
                    $parsedValue = trim(str_replace($uom, '', $value));
                    $this->storeSensorReading(
                        $sensorData,
                        $states,
                        $statuses,
                        $type,
                        $description,
                        $id,
                        $parsedValue,
                        $status
                    );
                    break;
                }
            }
        }
    }

    /**
     * Fall back to per-type `sdr type` queries for safe integration buckets.
     * Never queries Memory (segfault on some Intel BMCs; home-assistant-ipmi#96).
     *
     * @param list<string>|null $requestedTypes
     */
    private function extractFromSdrTypeFallback(
        array $cmd,
        string $interface,
        array &$sensorData,
        array &$states,
        array &$statuses,
        ?array $requestedTypes = null
    ): bool {
        $anyUsable = false;

        foreach (self::SDR_TYPE_FALLBACK_MAP as $bucket => $meta) {
            if (!$this->shouldIncludeSensorType($requestedTypes, $bucket)) {
                continue;
            }

            if ($this->getSensorsByType(
                $cmd,
                $interface,
                $meta['ipmi_type'],
                $meta['unit'],
                $bucket,
                $sensorData,
                $states,
                $statuses
            )) {
                $anyUsable = true;
            }
        }

        return $anyUsable;
    }

    /**
     * Store one sensor reading, disambiguating duplicate ids within a bucket.
     */
    private function storeSensorReading(
        array &$sensorData,
        array &$states,
        array &$statuses,
        string $type,
        string $description,
        string $id,
        string $value,
        ?string $status
    ): void {
        if (!isset($sensorData[$type])) {
            $sensorData[$type] = [];
        }

        $id_pattern = '/^' . preg_quote($id, '/') . '/';
        $id_count = count($this->preg_array_key_exists($id_pattern, $sensorData[$type]));
        if ($id_count > 0) {
            $description .= ' ' . ($id_count + 1);
            $id = $this->generateId($description);
        }

        $sensorData[$type][$id] = $description;
        $states[$id] = $value;
        if ($status !== null) {
            $statuses[$id] = $status;
        }
    }

    private function extractFromDcmiPowerReadingCommand(
        array $cmd,
        string $interface,
        array &$sensorData,
        array &$states,
        ?array $requestedTypes = null
    ): void
    {
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'dcmi', 'power', 'reading']), true);

        if ($ret) {
            // extract power usage from servers that support this command
            $results = explode(PHP_EOL, $ret);

            if (!empty($results)) {
                foreach ($results as $result) {
                    $extract = false;
                    $sensorType = 'power';

                    if (!empty($result)) {
                        if (str_contains($result, 'Watts')) {
                            if (!$this->shouldIncludeSensorType($requestedTypes, 'power')) {
                                continue;
                            }

                            $values = array_map('trim', explode(':', $result));
                            $description = $values[0];
                            $value = $values[1];
                            $value = trim(str_replace('Watts', '', $value));
                            $extract = true;
                        } else if (str_contains($result, 'Seconds')) {
                            if (!$this->shouldIncludeSensorType($requestedTypes, 'time')) {
                                continue;
                            }

                            $description = 'Sampling period';
                            $pattern = "/" . $description . ":\K.+?(?=Seconds)/";
                            $success = preg_match($pattern, $result, $match);
                            $sensorType = 'time';

                            if ($success) {
                                $extract = true;
                                $value = trim($match[0]);
                            }
                        }

                        if ($extract) {
                            $id = $this->generateId($description);
                            $sensorData[$sensorType][$id] = $description;
                            $states[$id] = $value;
                        }
                    }
                }
            }
        }
    }

    /**
     * Query one ipmitool SDR type on a fixed interface and merge into buckets.
     *
     * Typical `sdr type` line (5 columns):
     *   name | hex | status | entity | value units
     *
     * @return bool true when at least one usable reading was stored
     */
    private function getSensorsByType(
        array $cmd,
        string $interface,
        string $ipmiSdrType,
        string $unit,
        string $bucketType,
        array &$sensorData,
        array &$states,
        array &$statuses
    ): bool {
        $ret = $this->runCommand(
            array_merge($cmd, ['-I', $interface, 'sdr', 'type', $ipmiSdrType]),
            true
        );

        if ($ret === false || trim($ret) === '') {
            return false;
        }

        $anyUsable = false;
        $lines = explode(PHP_EOL, $ret);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $values = array_map('trim', explode('|', $line));
            $description = $values[0] ?? '';
            if ($description === '') {
                continue;
            }

            // Prefer 5-column `sdr type` layout; fall back to last column as reading.
            $status = $values[2] ?? null;
            $readingRaw = $values[4] ?? ($values[count($values) - 1] ?? '');

            if (!$this->hasUsableSensorReading((string) $readingRaw)) {
                continue;
            }

            if ($unit !== '' && !str_contains((string) $readingRaw, $unit)) {
                continue;
            }

            $value = $unit !== ''
                ? trim(str_replace($unit, '', (string) $readingRaw))
                : trim((string) $readingRaw);

            if (!$this->hasUsableSensorReading($value)) {
                continue;
            }

            $id = $this->generateId($description);
            $this->storeSensorReading(
                $sensorData,
                $states,
                $statuses,
                $bucketType,
                $description,
                $id,
                $value,
                is_string($status) ? $status : null
            );
            $anyUsable = true;
        }

        return $anyUsable;
    }
}
