<?php

/**
 * @author Adrian Teodorescu (ateodorescu@gmail.com)
 *
 * This class allows interaction with `ipmitool` executable.
 */

namespace App\Controller;

use Symfony\Component\Config\Definition\Exception\Exception;
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

    public function index(Request $request): JsonResponse
    {
        $info = $this->getDeviceInfo($request);

        if ($info['success']) {
            $sensors = $this->getSensors($request);

            if ($sensors['success']) {
                $info = array_merge($info, $sensors);
            }
        }

//        print_r($info);

        return new JsonResponse($info);
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

    private function runChassisCommand(Request $request, string $type):JsonResponse
    {
        $done = false;
        $cmd = $this->getCommand($request);
        foreach ($this->ipmiTypes as $ipmi_type) {
            $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'chassis', 'power', $type]));

            if ($ret) {
                $done = true;
                break;
            }
        }

        return new JsonResponse([
            'success' => $done
        ]);
    }

    private function runCommand($command): bool|string
    {
        $proc = new Process($command);
        $proc->setTimeout(1200);
        $proc->run();
        $output = $proc->getOutput();
        $exitCode = $proc->stop();

        if ($exitCode) {
//            throw new Exception($proc->getErrorOutput());
            return false;
        }

        return $output;
    }

    private function getCommand(Request $request): array
    {
        $query = $request->query;
        $ipmi = [
            'host' => $query->get('host'),
            'port' => $query->get('port', 623),
            'user' => $query->get('user', 'ADMIN'),
            'password' => $query->get('password', '')
        ];

        $cmd = ['ipmitool'];

        array_push($cmd, '-H', $ipmi['host'], '-p', $ipmi['port'], '-U', $ipmi['user'], '-P', $ipmi['password']);

        return $cmd;
    }

    private function getDeviceInfo(Request $request): array
    {
        $response = [
            'success' => false
        ];

        $device = [];
        $cmd = $this->getCommand($request);
        $found = false;
        $on = false;

        try {
            foreach ($this->ipmiTypes as $ipmi_type) {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'bmc', 'info']));

                if ($ret) {
                    $results = explode(PHP_EOL, $ret);
                    $device = $this->extractValuesFromResults($results);

                    $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'fru']));

                    if ($ret) {
                        $results = explode(PHP_EOL, $ret);
                        $device = array_merge($device, $this->extractValuesFromResults($results));
                    }

                    $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'chassis', 'power', 'status']));

                    if ($ret) {
                        $on = (trim($ret) === "Chassis Power is on");
                    }

                    $found = true;
                    break;
                }
            }

            if ($found) {
                $response['success'] = true;
                $response['device'] = $device;
                $response['power_on'] = $on;
            } else {
                $response['message'] = 'Wrong connection data provided!';
            }
        } catch (Exception $exception) {
            $response['message'] = $exception->getMessage();
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
            'success' => false
        ];

        $sensorData = [
            'power' => []
        ];
        $states = [];
        $cmd = $this->getCommand($request);
        $found = false;

        try {
            foreach ($this->sensorTypes as $type => $unit) {
                $data = $this->getSensorsByType($request, $type, $unit);
                $found = $found || $data['found'];
                $sensorData[$type] = $data['sensors'];
                $states = array_merge($states, $data['states']);
            }

            foreach ($this->ipmiTypes as $ipmi_type) {
                $ret = $this->runCommand(array_merge($cmd, ['-I', $ipmi_type, 'dcmi', 'power', 'reading']));

                if ($ret) {
                    // extract power usage
                    $results = explode(PHP_EOL, $ret);
                    $results = array_values(array_filter($results, function ($line) {
                        return str_contains($line, 'Watts');
                    }));

                    if (!empty($results)) {
                        foreach ($results as $result) {
                            if (!empty($result)) {
                                $values = array_map('trim', explode(':', $result));
                                [$description, $value] = $values;
                                $id = $this->generateId($description);
                                $value = trim(str_replace('Watts', '', $value));

                                $sensorData['power'][$id] = $description;
                                $states[$id] = $value;
                            }
                        }
                    }

                    $found = true;
                    break;
                }
            }

            if ($found) {
                $response['success'] = true;
                $response['sensors'] = $sensorData;
                $response['states'] = $states;
            } else {
                $response['message'] = 'Wrong connection data provided!';
            }
        } catch (Exception $exception) {
            $response['message'] = $exception->getMessage();
        }

        return $response;
    }

    private function getSensorsByType(Request $request, string $type, string $unit): array
    {
        $sensors = [];
        $states = [];

        $cmd = $this->getCommand($request);
        $found = false;

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
                            }
                            else {
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

        return [
            'found' => $found,
            'sensors' => $sensors,
            'states' => $states
        ];
    }


}