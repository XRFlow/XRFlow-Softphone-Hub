<?php
/**
 * Public Hub REST front controller.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Apache alias (packaging/apache/xrflow-softphone-hub.conf):
 *   Alias /xrflow-hub /var/www/html/admin/modules/xrflowsoftphone/public
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$bootstrap = '/etc/freepbx.conf';
if (!is_readable($bootstrap)) {
	http_response_code(503);
	echo json_encode(['error' => 'freepbx_unavailable']);
	exit;
}

include $bootstrap;
try {
	$hub = FreePBX::Xrflowsoftphone();
} catch (Throwable $e) {
	http_response_code(503);
	echo json_encode(['error' => 'module_unavailable']);
	exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^/xrflow-hub#', '', (string) $path);
$path = '/' . ltrim((string) $path, '/');

if ($path === '/v1/health' || $path === '/health') {
	$st = $hub->hubStatus();
	echo json_encode([
		'ok' => true,
		'module' => 'xrflowsoftphone',
		'license' => 'GPLv3+',
		'free' => true,
		'api_allowed' => true,
		'reason' => $st['reason'] ?? 'free',
	]);
	exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST' && preg_match('#^/v1/enroll/redeem/?$#', $path)) {
	$rawBody = file_get_contents('php://input');
	$data = json_decode((string) $rawBody, true);
	$token = is_array($data) ? (string) ($data['token'] ?? '') : '';
	if ($token === '' && isset($_POST['token'])) {
		$token = (string) $_POST['token'];
	}
	$result = $hub->redeemEnrollToken($token);
	if (empty($result['ok'])) {
		http_response_code((int) ($result['http'] ?? 400));
		echo json_encode(['error' => $result['error'] ?? 'redeem_failed']);
		exit;
	}
	echo json_encode($result['payload']);
	exit;
}

if ($method === 'GET' && preg_match('#^/enroll/([0-9a-f]{32})/?$#', $path, $m)) {
	echo json_encode([
		'ok' => true,
		'hint' => 'POST /xrflow-hub/v1/enroll/redeem with {"token":"..."} from the desktop app.',
		'token_length' => strlen($m[1]),
	]);
	exit;
}

http_response_code(501);
echo json_encode([
	'error' => 'not_implemented',
	'path' => $path,
	'message' => 'Heartbeat and presence proxy land in later Hub versions.',
]);
