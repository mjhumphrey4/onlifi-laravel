<?php
/**
 * MikroTik RouterOS API Client
 * Handles communication with MikroTik routers via API
 */
class MikrotikAPI {
    private $socket = null;
    private $host;
    private $port;
    private $username;
    private $password;
    private $connected = false;
    private $timeout = 5;

    public function __construct($host, $username, $password, $port = 8728) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Connect to MikroTik router
     */
    public function connect() {
        if ($this->connected) {
            return true;
        }

        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            error_log("MikroTik connection failed: $errstr ($errno)");
            return false;
        }

        stream_set_timeout($this->socket, $this->timeout);
        
        if (!$this->login()) {
            $this->disconnect();
            return false;
        }

        $this->connected = true;
        return true;
    }

    /**
     * Disconnect from router
     */
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Login to router
     */
    private function login() {
        $response = $this->communicate(['/login', '=name=' . $this->username, '=password=' . $this->password]);
        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Send command and receive response
     */
    private function communicate($command) {
        if (!$this->socket) {
            return false;
        }

        foreach ($command as $cmd) {
            $this->write($cmd);
        }
        $this->write('');

        return $this->read();
    }

    /**
     * Write data to socket
     */
    private function write($str) {
        $len = strlen($str);
        if ($len < 0x80) {
            $len = chr($len);
        } elseif ($len < 0x4000) {
            $len = chr(($len >> 8) | 0x80) . chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            $len = chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x10000000) {
            $len = chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } else {
            $len = chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }

        fwrite($this->socket, $len . $str);
    }

    /**
     * Read response from socket
     */
    private function read() {
        $response = [];
        while (true) {
            $line = $this->readLine();
            if ($line === false || $line === '') {
                break;
            }
            $response[] = $line;
            if ($line === '!done') {
                break;
            }
        }
        return $response;
    }

    /**
     * Read single line from socket
     */
    private function readLine() {
        $len = $this->readLen();
        if ($len === false || $len === 0) {
            return '';
        }
        $line = '';
        while (strlen($line) < $len) {
            $line .= fread($this->socket, $len - strlen($line));
        }
        return $line;
    }

    /**
     * Read length prefix
     */
    private function readLen() {
        $byte = ord(fread($this->socket, 1));
        if ($byte & 0x80) {
            if (($byte & 0xC0) === 0x80) {
                return (($byte & ~0xC0) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xE0) === 0xC0) {
                return (($byte & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF0) === 0xE0) {
                return (($byte & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF8) === 0xF0) {
                return (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }
        }
        return $byte;
    }

    /**
     * Parse response into associative array
     */
    private function parseResponse($response) {
        $parsed = [];
        $current = [];
        
        foreach ($response as $line) {
            if ($line === '!done' || $line === '!trap') {
                if (!empty($current)) {
                    $parsed[] = $current;
                    $current = [];
                }
            } elseif (strpos($line, '=') === 0) {
                $parts = explode('=', substr($line, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }
        
        if (!empty($current)) {
            $parsed[] = $current;
        }
        
        return $parsed;
    }

    /**
     * Get active DHCP leases (connected clients)
     */
    public function getActiveClients() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate([
            '/ip/dhcp-server/lease/print',
            '=.proplist=.id,mac-address,address,host-name,last-seen,status,server,dynamic,comment',
        ]);
        $clients = $this->parseResponse($response);

        $result = [];
        foreach ($clients as $client) {
            if (isset($client['status']) && $client['status'] === 'bound') {
                $result[] = [
                    'id' => $client['.id'] ?? '',
                    'mac_address' => $client['mac-address'] ?? '',
                    'ip_address' => $client['address'] ?? '',
                    'hostname' => $client['host-name'] ?? 'Unknown',
                    'last_seen' => $client['last-seen'] ?? '',
                    'status' => $client['status'] ?? '',
                    'server' => $client['server'] ?? '',
                    'dynamic' => filter_var($client['dynamic'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'comment' => $client['comment'] ?? '',
                    'device_type' => $this->detectDeviceType($client['mac-address'] ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * Get configured IP pools.
     */
    public function getIpPools() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate([
            '/ip/pool/print',
            '=.proplist=.id,name,ranges,next-pool,comment',
        ]);
        $pools = $this->parseResponse($response);

        return array_map(function ($pool) {
            return [
                'id' => $pool['.id'] ?? '',
                'name' => $pool['name'] ?? '',
                'ranges' => $pool['ranges'] ?? '',
                'next_pool' => $pool['next-pool'] ?? '',
                'comment' => $pool['comment'] ?? '',
            ];
        }, $pools);
    }

    /**
     * Get HotSpot active users
     */
    public function getHotspotUsers() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate(['/ip/hotspot/active/print']);
        $users = $this->parseResponse($response);

        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'username' => $user['user'] ?? '',
                'mac_address' => $user['mac-address'] ?? '',
                'ip_address' => $user['address'] ?? '',
                'uptime' => $user['uptime'] ?? '0s',
                'bytes_in' => $user['bytes-in'] ?? 0,
                'bytes_out' => $user['bytes-out'] ?? 0,
                'login_time' => $user['login-by'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Get router system resources
     */
    public function getSystemResources() {
        if (!$this->connect()) {
            return null;
        }

        $response = $this->communicate(['/system/resource/print']);
        $resources = $this->parseResponse($response);

        if (empty($resources)) {
            return null;
        }

        $resource = $resources[0];
        return [
            'uptime' => $resource['uptime'] ?? '',
            'version' => $resource['version'] ?? '',
            'cpu_load' => $resource['cpu-load'] ?? 0,
            'free_memory' => $resource['free-memory'] ?? 0,
            'total_memory' => $resource['total-memory'] ?? 0,
            'cpu_count' => $resource['cpu-count'] ?? 1,
            'cpu_frequency' => $resource['cpu-frequency'] ?? 0,
            'board_name' => $resource['board-name'] ?? '',
        ];
    }

    /**
     * Get interface statistics
     */
    public function getInterfaceStats() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate(['/interface/print', '=stats']);
        return $this->parseResponse($response);
    }

    /**
     * Add HotSpot user (voucher)
     */
    public function addHotspotUser($username, $password, $profile = 'default', $comment = '') {
        if (!$this->connect()) {
            return false;
        }

        $command = [
            '/ip/hotspot/user/add',
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile,
        ];

        if ($comment) {
            $command[] = '=comment=' . $comment;
        }

        $response = $this->communicate($command);
        return isset($response[0]) && strpos($response[0], '!done') !== false;
    }

    /**
     * Remove HotSpot user
     */
    public function removeHotspotUser($username) {
        if (!$this->connect()) {
            return false;
        }

        $response = $this->communicate([
            '/ip/hotspot/user/remove',
            '=.id=' . $username
        ]);

        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Disconnect active HotSpot sessions for a username.
     */
    public function removeActiveHotspotUser($username) {
        if (!$this->connect()) {
            return false;
        }

        $response = $this->communicate([
            '/ip/hotspot/active/print',
            '?user=' . $username,
            '=.proplist=.id,user',
        ]);

        $users = $this->parseResponse($response);
        $removed = false;

        foreach ($users as $user) {
            if (empty($user['.id'])) {
                continue;
            }

            $removeResponse = $this->communicate([
                '/ip/hotspot/active/remove',
                '=.id=' . $user['.id'],
            ]);

            if (isset($removeResponse[0]) && $removeResponse[0] === '!done') {
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Get HotSpot IP bindings.
     */
    public function getHotspotIpBindings() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate(['/ip/hotspot/ip-binding/print']);
        $bindings = $this->parseResponse($response);

        return array_map(function ($binding) {
            return [
                'id' => $binding['.id'] ?? '',
                'mac_address' => $binding['mac-address'] ?? '',
                'address' => $binding['address'] ?? '',
                'to_address' => $binding['to-address'] ?? '',
                'server' => $binding['server'] ?? 'all',
                'type' => $binding['type'] ?? 'regular',
                'comment' => $binding['comment'] ?? '',
                'disabled' => ($binding['disabled'] ?? 'false') === 'true',
            ];
        }, $bindings);
    }

    /**
     * Add a HotSpot IP binding.
     */
    public function addHotspotIpBinding(array $binding) {
        if (!$this->connect()) {
            return false;
        }

        $command = [
            '/ip/hotspot/ip-binding/add',
            '=mac-address=' . ($binding['mac_address'] ?? ''),
            '=type=' . ($binding['type'] ?? 'bypassed'),
        ];

        foreach ([
            'address' => 'address',
            'to_address' => 'to-address',
            'server' => 'server',
            'comment' => 'comment',
        ] as $inputKey => $routerKey) {
            if (!empty($binding[$inputKey])) {
                $command[] = '=' . $routerKey . '=' . $binding[$inputKey];
            }
        }

        $response = $this->communicate($command);
        return isset($response[0]) && $response[0] === '!done';
    }

    public function getPppoeSecrets() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate(['/ppp/secret/print']);
        $secrets = $this->parseResponse($response);

        return array_map(function ($secret) {
            return [
                'id' => $secret['.id'] ?? '',
                'name' => $secret['name'] ?? '',
                'username' => $secret['name'] ?? '',
                'password' => $secret['password'] ?? '',
                'profile' => $secret['profile'] ?? '',
                'service' => $secret['service'] ?? '',
                'remote_address' => $secret['remote-address'] ?? '',
                'comment' => $secret['comment'] ?? '',
                'disabled' => ($secret['disabled'] ?? 'false') === 'true',
                'last_logged_out' => $secret['last-logged-out'] ?? '',
            ];
        }, $secrets);
    }

    public function addPppoeSecret(array $client) {
        if (!$this->connect()) {
            return false;
        }

        $command = [
            '/ppp/secret/add',
            '=name=' . ($client['username'] ?? $client['name'] ?? ''),
            '=password=' . ($client['password'] ?? ''),
            '=service=' . ($client['service'] ?? 'pppoe'),
            '=disabled=' . (!empty($client['disabled']) ? 'yes' : 'no'),
        ];

        foreach ([
            'profile' => 'profile',
            'remote_address' => 'remote-address',
            'comment' => 'comment',
        ] as $inputKey => $routerKey) {
            if (!empty($client[$inputKey])) {
                $command[] = '=' . $routerKey . '=' . $client[$inputKey];
            }
        }

        $response = $this->communicate($command);
        return isset($response[0]) && $response[0] === '!done';
    }

    public function setPppoeSecretDisabled(string $id, bool $disabled) {
        if (!$this->connect()) {
            return false;
        }

        $response = $this->communicate([
            '/ppp/secret/set',
            '=.id=' . $id,
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);

        return isset($response[0]) && $response[0] === '!done';
    }

    public function removePppoeSecret(string $id) {
        if (!$this->connect()) {
            return false;
        }

        $response = $this->communicate([
            '/ppp/secret/remove',
            '=.id=' . $id,
        ]);

        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Get RouterOS system users.
     */
    public function getSystemUsers() {
        if (!$this->connect()) {
            return [];
        }

        $response = $this->communicate(['/user/print']);
        $users = $this->parseResponse($response);

        return array_map(function ($user) {
            return [
                'id' => $user['.id'] ?? '',
                'name' => $user['name'] ?? '',
                'group' => $user['group'] ?? '',
                'last_logged_in' => $user['last-logged-in'] ?? '',
                'comment' => $user['comment'] ?? '',
                'disabled' => ($user['disabled'] ?? 'false') === 'true',
            ];
        }, $users);
    }

    /**
     * Add a RouterOS system user.
     */
    public function addSystemUser(array $user) {
        if (!$this->connect()) {
            return false;
        }

        $command = [
            '/user/add',
            '=name=' . ($user['name'] ?? ''),
            '=password=' . ($user['password'] ?? ''),
            '=group=' . ($user['group'] ?? 'read'),
        ];

        if (!empty($user['comment'])) {
            $command[] = '=comment=' . $user['comment'];
        }

        $response = $this->communicate($command);
        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Enable or disable a RouterOS system user.
     */
    public function setSystemUserDisabled(string $id, bool $disabled) {
        if (!$this->connect()) {
            return false;
        }

        $response = $this->communicate([
            '/user/set',
            '=.id=' . $id,
            '=disabled=' . ($disabled ? 'yes' : 'no'),
        ]);

        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Detect device type from MAC address (OUI lookup)
     */
    private function detectDeviceType($mac) {
        $mac = strtoupper(str_replace([':', '-'], '', $mac));
        $oui = substr($mac, 0, 6);

        $vendors = [
            '001122' => 'Cisco',
            '00D0BA' => 'Apple',
            '3C0754' => 'Apple',
            '001EC2' => 'Apple',
            'F0B479' => 'Apple',
            '70CD60' => 'Apple',
            '001451' => 'Samsung',
            '00EE76' => 'Samsung',
            'E8B2AC' => 'Samsung',
            '001D25' => 'Huawei',
            '0025BC' => 'Huawei',
            '00E04C' => 'Realtek',
            '001C7F' => 'TP-Link',
            '50C7BF' => 'TP-Link',
            'D46E0E' => 'TP-Link',
        ];

        return $vendors[$oui] ?? 'Unknown';
    }

    /**
     * Get router identity
     */
    public function getIdentity() {
        if (!$this->connect()) {
            return null;
        }

        $response = $this->communicate(['/system/identity/print']);
        $identity = $this->parseResponse($response);

        return $identity[0]['name'] ?? null;
    }

    /**
     * Test connection to router
     */
    public function testConnection() {
        return $this->connect();
    }

    public function __destruct() {
        $this->disconnect();
    }
}
