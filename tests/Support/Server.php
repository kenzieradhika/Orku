<?php

declare(strict_types=1);

namespace Orku\Tests\Support;

final class Server
{
    private $process;
    private string $log;
    public readonly string $url;

    public function __construct(string $example, bool $fixture = false)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($socket === false) {
            throw new \RuntimeException($message, $errno);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $root = dirname(__DIR__, 2);
        $this->url = 'http://'.$address;
        $this->log = tempnam(sys_get_temp_dir(), 'orku-test-');
        $command = $fixture
            ? [PHP_BINARY, '-d', 'display_errors=0', '-S', $address, $root.'/tests/fixtures/public/index.php']
            : [PHP_BINARY, $root.'/bin/orku', 'serve', $example, $address];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']], $pipes, $root);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Cannot start test HTTP server.');
        }
        fclose($pipes[0]);
        for ($i = 0; $i < 100; ++$i) {
            if (!proc_get_status($this->process)['running']) {
                $output = file_get_contents($this->log);
                $this->close();
                throw new \RuntimeException('Test server exited: '.$output);
            }
            $connection = @stream_socket_client('tcp://'.$address, $errno, $message, 0.05);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->close();
        throw new \RuntimeException('Test server did not start.');
    }

    public function request(string $path, string $method = 'GET', string $body = '', array $headers = []): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'content' => $body, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
        $result = file_get_contents($this->url.$path, false, $context);
        $lines = http_get_last_response_headers();
        if ($result === false || $lines === null) {
            throw new \RuntimeException('HTTP test request failed.');
        }
        preg_match('~^HTTP/\S+ (\d+)~', $lines[0], $matches);
        $responseHeaders = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower($name)] = trim($value);
        }
        return ['status' => (int) $matches[1], 'headers' => $responseHeaders, 'header_lines' => array_slice($lines, 1), 'body' => $result];
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (isset($this->log) && is_file($this->log)) {
            unlink($this->log);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
