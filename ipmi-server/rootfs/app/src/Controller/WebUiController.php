<?php

namespace App\Controller;

use App\Service\ServerConfigStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebUiController
{
    private const ACTIONS = [
        'sensors' => 'Fetch sensors',
        'power_on' => 'Power on',
        'power_off' => 'Power off',
        'power_cycle' => 'Power cycle',
        'power_reset' => 'Power reset',
        'soft_shutdown' => 'Soft shutdown',
        'command' => 'Run custom command',
        'save_server' => 'Save server',
        'delete_server' => 'Delete server',
    ];

    private const CONFIG_ACTIONS = [
        'save_server',
        'delete_server',
    ];

    public function __construct(
        private readonly IpmiController $ipmiController,
        private readonly ServerConfigStore $serverConfigStore,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            return $this->handle($request);
        } catch (\Throwable $e) {
            if ($this->wantsJson($request)) {
                return new JsonResponse([
                    'success' => false,
                    'action' => (string) $request->request->get('ui_action', 'sensors'),
                    'action_label' => 'Request',
                    'message' => $e->getMessage(),
                    'output' => sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()),
                ], 500);
            }

            throw $e;
        }
    }

    private function handle(Request $request): Response
    {
        $form = [
            'server_id' => '',
            'name' => '',
            'host' => '',
            'port' => (string) IpmiController::DEFAULT_PORT,
            'user' => '',
            'password' => '',
            'interface' => '',
            'kg_key' => '',
            'privilege_level' => '',
            'command_args' => '',
        ];
        $result = null;
        $action = 'sensors';

        try {
            $servers = $this->serverConfigStore->all();
        } catch (\Throwable $e) {
            $servers = [];
            if ($request->isMethod('GET') && !$this->wantsJson($request)) {
                $result = [
                    'action' => 'save_server',
                    'action_label' => 'Saved servers',
                    'success' => false,
                    'message' => 'Could not load saved servers: '.$e->getMessage(),
                ];
            }
        }

        if ($request->isMethod('POST')) {
            foreach (array_keys($form) as $key) {
                $form[$key] = trim((string) $request->request->get($key, $form[$key]));
            }

            // Field is "ui_action" (not "action") so it does not shadow HTMLFormElement.action in JS.
            $action = (string) $request->request->get('ui_action', $request->request->get('action', 'sensors'));
            if (!isset(self::ACTIONS[$action])) {
                $action = 'sensors';
            }

            if (in_array($action, self::CONFIG_ACTIONS, true)) {
                $result = $this->runConfigAction($action, $form);
                try {
                    $servers = $this->serverConfigStore->all();
                } catch (\Throwable) {
                    // Keep previous list if reload fails after a write error.
                }
            } else {
                $query = array_filter([
                    'host' => $form['host'],
                    'port' => $form['port'] !== '' ? $form['port'] : (string) IpmiController::DEFAULT_PORT,
                    'user' => $form['user'],
                    'interface' => $form['interface'],
                    'privilege_level' => $form['privilege_level'],
                ], static fn (string $value): bool => $value !== '');

                $result = $this->runAction($action, $query, $form['password'], $form['kg_key'], $form['command_args']);
            }

            $result['action'] = $action;
            $result['action_label'] = self::ACTIONS[$action];
            $result['servers'] = $servers;

            if ($this->wantsJson($request)) {
                return new JsonResponse($result);
            }

            // Do not echo secrets back into the HTML form on full-page POST.
            $form['password'] = '';
            $form['kg_key'] = '';
        }

        return new Response($this->render($form, $result, $action, $servers));
    }

    private function wantsJson(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function runConfigAction(string $action, array $form): array
    {
        try {
            if ($action === 'save_server') {
                if ($form['host'] === '') {
                    return [
                        'success' => false,
                        'message' => 'Host / IP is required to save a server.',
                    ];
                }

                // Keep existing secrets when the form fields are left blank on update.
                if ($form['server_id'] !== '') {
                    $existing = $this->serverConfigStore->find($form['server_id']);
                    if ($existing !== null) {
                        if ($form['password'] === '') {
                            $form['password'] = $existing['password'];
                        }
                        if ($form['kg_key'] === '') {
                            $form['kg_key'] = $existing['kg_key'];
                        }
                    }
                }

                $saved = $this->serverConfigStore->save([
                    'id' => $form['server_id'],
                    'name' => $form['name'],
                    'host' => $form['host'],
                    'port' => $form['port'],
                    'user' => $form['user'],
                    'password' => $form['password'],
                    'interface' => $form['interface'],
                    'kg_key' => $form['kg_key'],
                    'privilege_level' => $form['privilege_level'],
                ]);

                return [
                    'success' => true,
                    'message' => sprintf('Saved server "%s".', $saved['name']),
                    'server' => $saved,
                ];
            }

            if ($form['server_id'] === '') {
                return [
                    'success' => false,
                    'message' => 'Select a saved server to delete.',
                ];
            }

            $existing = $this->serverConfigStore->find($form['server_id']);
            if ($existing === null) {
                return [
                    'success' => false,
                    'message' => 'Saved server not found.',
                ];
            }

            $this->serverConfigStore->delete($form['server_id']);

            return [
                'success' => true,
                'message' => sprintf('Deleted server "%s".', $existing['name']),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Could not update saved servers: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function runAction(string $action, array $query, string $password, string $kgKey, string $commandArgs): array
    {
        if ($action === 'sensors') {
            $jsonResponse = $this->ipmiController->sensors($this->createIpmiRequest('/sensors', $query, $password, $kgKey));

            return json_decode($jsonResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        if ($action === 'command') {
            if ($commandArgs === '') {
                return [
                    'success' => false,
                    'output' => 'Enter custom command arguments, for example: bmc info',
                ];
            }

            // Do not put password/kg into params: Request query encoding can corrupt
            // secrets (e.g. +, &, =). Headers match the sensors/power code path.
            $params = $this->buildCommandParams($query, '', '', $commandArgs);
            $commandRequest = Request::create('/command', 'GET', [
                'params' => $params,
            ]);
            if ($password !== '') {
                $commandRequest->headers->set(IpmiController::PASSWORD_HEADER, $password);
            }
            if ($kgKey !== '') {
                $commandRequest->headers->set(IpmiController::KG_KEY_HEADER, $kgKey);
            }
            $jsonResponse = $this->ipmiController->command($commandRequest);

            return json_decode($jsonResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        $path = '/'.$action;
        $ipmiRequest = $this->createIpmiRequest($path, $query, $password, $kgKey);
        $jsonResponse = match ($action) {
            'power_on' => $this->ipmiController->power_on($ipmiRequest),
            'power_off' => $this->ipmiController->power_off($ipmiRequest),
            'power_cycle' => $this->ipmiController->power_cycle($ipmiRequest),
            'power_reset' => $this->ipmiController->power_reset($ipmiRequest),
            'soft_shutdown' => $this->ipmiController->soft_shutdown($ipmiRequest),
            default => throw new \InvalidArgumentException(sprintf('Unsupported action "%s".', $action)),
        };

        return json_decode($jsonResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $query
     */
    private function createIpmiRequest(string $path, array $query, string $password, string $kgKey): Request
    {
        $request = Request::create($path, 'GET', $query);

        if ($password !== '') {
            $request->headers->set(IpmiController::PASSWORD_HEADER, $password);
        }
        if ($kgKey !== '') {
            $request->headers->set(IpmiController::KG_KEY_HEADER, $kgKey);
        }

        return $request;
    }

    /**
     * @param array<string, string> $query
     */
    private function buildCommandParams(array $query, string $password, string $kgKey, string $commandArgs): string
    {
        $parts = ['-H', $query['host'], '-p', $query['port'] ?? (string) IpmiController::DEFAULT_PORT];

        if (($query['user'] ?? '') !== '') {
            $parts[] = '-U';
            $parts[] = $query['user'];
        }
        if ($password !== '') {
            $parts[] = '-P';
            $parts[] = $password;
        }
        // Custom commands cannot auto-detect like /sensors; default to lanplus (IPMI 2.0).
        $interface = $query['interface'] ?? '';
        if ($interface === '') {
            $interface = 'lanplus';
        }
        $parts[] = '-I';
        $parts[] = $interface;
        if ($kgKey !== '') {
            $parts[] = '-y';
            $parts[] = $kgKey;
        }
        if (($query['privilege_level'] ?? '') !== '') {
            $parts[] = '-L';
            $parts[] = $query['privilege_level'];
        }

        foreach (str_getcsv($commandArgs, ' ', '"', '') as $arg) {
            if ($arg !== '') {
                $parts[] = $arg;
            }
        }

        return implode(' ', array_map(
            static fn (string $part): string => preg_match('/\s/', $part) ? '"'.$part.'"' : $part,
            $parts
        ));
    }

    /**
     * @param array<string, string> $form
     * @param array<string, mixed>|null $result
     * @param list<array<string, string>> $servers
     */
    private function render(array $form, ?array $result, string $action, array $servers): string
    {
        $interfaces = ['', 'lanplus', 'lan', 'imb', 'open'];
        $privileges = ['', 'CALLBACK', 'USER', 'OPERATOR', 'ADMINISTRATOR'];
        $actions = self::ACTIONS;

        ob_start();
        include dirname(__DIR__, 2).'/templates/web_ui.php';

        return (string) ob_get_clean();
    }
}
