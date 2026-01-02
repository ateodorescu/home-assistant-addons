<?php

/**
 * @author Adrian Teodorescu (ateodorescu@gmail.com)
 *
 * This class allows interaction with `ipmitool` executable.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    const COMMAND_TIMEOUT = 50;
    const DEFAULT_PORT = 623;

    public function index(Request $request): JsonResponse
    {
        $this->password = $request->query->get('password', '');
        $info = $this->getDeviceInfo($request);

        if ($info['success']) {
            $sensors = $this->getSensors($request);

            if ($sensors['success']) {
                $info = array_merge($info, $sensors);
            }
        }

        $info['debug'] = implode("\n", $this->debug);

        if (array_key_exists('message', $info)) {
            $info['message'] = $this->anonymizePassword($info['message']);
        }

        return new JsonResponse($info);
    }

    public function command(Request $request): JsonResponse
    {
        $cmd = str_getcsv($request->get('params', ''), ' ', '"', '');
        array_unshift($cmd, 'ipmitool');
        $ret = $this->runCommand($cmd);
        $done = ($ret !== false);

        return new JsonResponse([
            'success' => $done,
            'output' => $done ? $ret : implode("\n", $this->debug)
        ]);
    }

    public function sensors(Request $request): JsonResponse
    {
        return new JsonResponse($this->getSensors($request));
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

    private function anonymizePassword(string $message): string
    {
        return empty($this->password) ? $message : str_replace($this->password, '####', $message);
    }

    private function runChassisCommand(Request $request, string $type):JsonResponse
    {
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

        return new JsonResponse([
            'success' => $done
        ]);
    }

    private function runCommand($command, $ignoreErrors = false): bool|string
    {
        $errorIntro = "Error occurred when running \"" . implode(" ", array_map($this->anonymizePassword(...), $command)) . "\".\n" ;

        try {
            $proc = new Process($command);
            $proc->setTimeout(self::COMMAND_TIMEOUT);
            $proc->run();
            $output = $proc->getOutput();
            $exitCode = $proc->stop();

            if ($exitCode) {
                // let's log this error
                $message = $this->anonymizePassword($errorIntro .$proc->getErrorOutput());
                $this->debug[] = $message;

                if (!$ignoreErrors) {
                    error_log($message);
                }

                return false;
            }
        }
        catch (\Exception $exception) {
            // let's log this error
            $message = $this->anonymizePassword($errorIntro . $exception->getMessage());
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
        $query = $request->query;
        $host = $query->get('host');

        if (empty($host)) {
            $message = 'No hostname provided!';
            $this->debug[] = $message;
            error_log($message);

            return false;
        }

        $user = $query->get('user', '');
        $pass = $query->get('password', '');
        $kg_key = $query->get('kg_key', '');
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

    private function getDeviceInfo(Request $request): array
    {
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getDeviceInfoByInterface($request, $interface);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getDeviceInfoByInterface($request, $interface);
        }

        return $response;
    }

    private function getDeviceInfoByInterface(Request $request, string $interface): array
    {
        $response = [
            'success' => false
        ];

        $cmd = $this->getCommand($request);
        $on = false;
        $error = 'Wrong connection data provided!';

        if ($cmd === false) {
            $response['message'] = $error;
        }
        else {
            try {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'bmc', 'info']));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    $device = $this->extractValuesFromResults($results);

                    $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'fru']));

                    if ($ret) {
                        $results = explode(PHP_EOL, $ret);
                        $device = array_merge($device, $this->extractValuesFromResults($results));
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

    private function getSensors(Request $request): array
    {
        $response = [
            'success' => false,
            'message' => 'Wrong connection data provided!'
        ];

        $interface = $request->query->get('interface', '');

        if (empty($interface)) {
            foreach ($this->ipmiTypes as $interface) {
                $response = $this->getSensorsByInterface($request, $interface);

                if ($response['success']) {
                    break;
                }
            }
        }
        else {
            $response = $this->getSensorsByInterface($request, $interface);
        }

        return $response;
    }

    private function getSensorsByInterface(Request $request, string $interface): array
    {
        $response = [
            'success' => false
        ];

        $states = [];
        $sensorData = [];

        foreach ($this->unitsOfMeasure as $uom => $type) {
            $sensorData[$type] = [];
        }

        $cmd = $this->getCommand($request);

        if ($cmd !== false) {
            try {
//                $response['success'] = $this->extractFromSensorCommand($cmd, $interface, $sensorData, $states);
                $response['success'] = $this->extractFromSdrCommand($cmd, $interface, $sensorData, $states);
                $this->extractFromDcmiPowerReadingCommand($cmd, $interface, $sensorData, $states);

            } catch (\Exception $exception) {
                $response['message'] = $exception->getMessage();
            }
        }

        $response['sensors'] = $sensorData;
        $response['states'] = $states;

        return $response;
    }

    private function extractFromSensorCommand(array $cmd, string $interface, array &$sensorData, array &$states): bool
    {
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sensor']), true);

        if ($ret) {
            $lines = explode(PHP_EOL, $ret);

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $values = array_map('trim', explode('|', $line));

                        if ($values[3] === 'ok') {
                            $description = $values[0];
                            $id = $this->generateId($description);
                            $value = $values[1];
                            $uom = $values[2];
                            $type = array_key_exists($uom, $this->unitsOfMeasure) ? $this->unitsOfMeasure[$uom] : null;

                            if ($type) {
                                $sensorData[$type][$id] = $description;
                                $states[$id] = $value;
                            }
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


    private function extractFromSdrCommand(array $cmd, string $interface, array &$sensorData, array &$states): bool
    {
        $ret = $this->runCommand(array_merge($cmd, ['-I', $interface, 'sdr', 'list', 'full']), true);

        if ($ret) {
            $lines = explode(PHP_EOL, $ret);

            if (!empty($lines)) {
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $values = array_map('trim', explode('|', $line));

                        if ($values[2] === 'ok') {
                            $description = $values[0];
                            $id = $this->generateId($description);
                            $value = $values[1];

                            foreach($this->unitsOfMeasure as $uom => $type) {
                                if (str_contains($value, $uom)) {
                                    $value = trim(str_replace($uom, '', $value));

                                    $id_pattern = "/^".$id."/";
                                    $id_count = count($this->preg_array_key_exists($id_pattern, $sensorData[$type]));
                                    if ($id_count > 0) {
                                        $description .= ' ' . $id_count+1;
                                        $id = $this->generateId($description);
                                    }

                                    $sensorData[$type][$id] = $description;
                                    $states[$id] = $value;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $ret !== false;
    }

    private function extractFromDcmiPowerReadingCommand(array $cmd, string $interface, array &$sensorData, array &$states): void
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
                            $values = array_map('trim', explode(':', $result));
                            $description = $values[0];
                            $value = $values[1];
                            $value = trim(str_replace('Watts', '', $value));
                            $extract = true;
                        } else if (str_contains($result, 'Seconds')) {
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

    private function getSensorsByType(Request $request, string $type, string $unit): array
    {
        $sensors = [];
        $states = [];

        $cmd = $this->getCommand($request);
        $found = false;

        if ($cmd !== false) {
            foreach ($this->ipmiTypes as $ipmi_type) {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'sdr', 'type', $type]));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);

                    if (!empty($results)) {
                        foreach ($results as $result) {
                            if (!empty($result)) {
                                $values = array_map('trim', explode('|', $result));
                                [$description, $a, $b, $c, $value] = $values;
                                $id = $this->generateId($description);

                                if (str_contains($value, $unit)) {
                                    $value = trim(str_replace($unit, '', $value));
                                } else {
                                    $value = null;
                                }

                                $sensors[$id] = $description;
                                $states[$id] = $value;
                            }
                        }

                        $found = true;
                        break;
                    }
                }
            }
        }

        return [
            'found' => $found,
            'sensors' => $sensors,
            'states' => $states
        ];
    }


}
