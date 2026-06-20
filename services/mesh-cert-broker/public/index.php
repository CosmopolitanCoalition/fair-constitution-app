<?php

// The HTTP entry — point the Apache vhost docroot here. POST a signed cert request; get a cert or a
// client-safe error. Internal details (tokens, paths, ACME output) are NEVER echoed.

require dirname(__DIR__).'/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        // health probe
        echo json_encode(['service' => 'mesh-cert-broker', 'status' => 'ok']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (! is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        exit;
    }

    $result = broker_factory()->issue($body);
    echo json_encode($result);
} catch (\MeshCertBroker\BrokerError $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Fail closed + opaque — never leak a stack trace, token, or path to the client.
    error_log('[mesh-cert-broker] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error.']);
}
