<?php

declare(strict_types=1);

// One-request loopback fixture. Only fake test credentials are sent here.
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "Unable to bind HTTP test server\n");
    exit(1);
}
echo stream_socket_get_name($server, false) . "\n";
flush();
$requests = max(1, min(3, (int)($argv[1] ?? 1)));
for ($request = 0; $request < $requests; $request++) {
    $client = stream_socket_accept($server, 10);
    if ($client === false) {
        exit(1);
    }
    stream_set_timeout($client, 5);
    $requestLine = trim((string)fgets($client));
    $headers = [];
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $length = (int)($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($client, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $requestData = json_decode($body, true);
    $content = json_encode(['request' => $requestLine, 'headers' => $headers, 'body' => $requestData], JSON_THROW_ON_ERROR);
    $response = json_encode(['choices' => [['message' => ['content' => $content]]]], JSON_THROW_ON_ERROR);
    if (($requestData['model'] ?? '') === 'test/reasoning-budget' && ($requestData['reasoning']['effort'] ?? '') !== 'none') {
        $response = '{"choices":[{"finish_reason":"length","message":{"content":null,"reasoning":"fake-secret-do-not-print"}}]}';
    }
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
    fclose($client);
}
fclose($server);
