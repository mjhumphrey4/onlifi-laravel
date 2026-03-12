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

        $response = $this->communicate(['/ip/dhcp-server/lease/print', '=.proplist=mac-address,address,host-name,last-seen,status']);
        $clients = $this->parseResponse($response);

        $result = [];
        foreach ($clients as $client) {
            if (isset($client['status']) && $client['status'] === 'bound') {
                $result[] = [
                    'mac_address' => $client['mac-address'] ?? '',
                    'ip_address' => $client['address'] ?? '',
                    'hostname' => $client['host-name'] ?? 'Unknown',
                    'last_seen' => $client['last-seen'] ?? '',
                    'device_type' => $this->detectDeviceType($client['mac-address'] ?? ''),
                ];
            }
        }

        return $result;
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
