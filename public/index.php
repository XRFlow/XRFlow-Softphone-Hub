<?php
/**
 * Public Hub REST front controller.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Apache alias (packaging/apache/xrflow-softphone-hub.conf):
 *   Alias /xrflow-hub /var/www/html/admin/modules/xrflowsoftphone/public
 *   FallbackResource /xrflow-hub/index.php
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$bootstrap_settings = [
	'freepbx_auth' => false,
];
$restrict_mods = [
	'xrflowsoftphone' => true,
	'core' => true,
	'manager' => true,
	'api' => true,
];

$bootstrap = '/etc/freepbx.conf';
if (!is_readable($bootstrap)) {
	http_response_code(503);
	echo json_encode(['error' => 'freepbx_unavailable']);
	exit;
}

include $bootstrap;

$hub = null;
try {
	$hub = \FreePBX::create()->Xrflowsoftphone();
} catch (Throwable $e) {
	try {
		require_once dirname(__DIR__) . '/Xrflowsoftphone.class.php';
		$hub = new \FreePBX\modules\Xrflowsoftphone(\FreePBX::create());
	} catch (Throwable $e2) {
		http_response_code(503);
		echo json_encode(['error' => 'module_unavailable']);
		exit;
	}
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^/xrflow-hub#', '', (string) $path);
$path = '/' . ltrim((string) $path, '/');
if ($path === '/index.php') {
	$path = '/';
}

if ($path === '/v1/health' || $path === '/health' || $path === '/') {
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

$sendRedeem = static function ($hub, $token) {
	$result = $hub->redeemEnrollToken($token);
	if (empty($result['ok'])) {
		http_response_code((int) ($result['http'] ?? 400));
		echo json_encode(['error' => $result['error'] ?? 'redeem_failed']);
		exit;
	}
	echo json_encode($result['payload']);
	exit;
};

if ($method === 'POST' && preg_match('#^/v1/enroll/redeem/?$#', $path)) {
	$rawBody = file_get_contents('php://input');
	$data = json_decode((string) $rawBody, true);
	$token = is_array($data) ? (string) ($data['token'] ?? '') : '';
	if ($token === '' && isset($_POST['token'])) {
		$token = (string) $_POST['token'];
	}
	$sendRedeem($hub, $token);
}

if (preg_match('#^/enroll/([0-9a-f]{32})/?$#', $path, $m)) {
	$sendRedeem($hub, $m[1]);
}

if ($method === 'POST' && preg_match('#^/v1/user-profile/?$#', $path)) {
	$rawBody = file_get_contents('php://input');
	$data = json_decode((string) $rawBody, true);
	if (!is_array($data)) {
		$data = $_POST;
	}
	$result = $hub->userProfile(
		(string) ($data['extension'] ?? ''),
		(string) ($data['secret'] ?? ''),
		(string) ($data['name'] ?? '')
	);
	if (empty($result['ok'])) {
		http_response_code((int) ($result['http'] ?? 400));
		echo json_encode(['error' => $result['error'] ?? 'profile_failed']);
		exit;
	}
	echo json_encode([
		'ok' => true,
		'extension' => $result['extension'] ?? '',
		'user_extension' => $result['user_extension'] ?? '',
		'email' => $result['email'] ?? '',
		'name' => $result['name'] ?? '',
	]);
	exit;
}

http_response_code(404);
echo json_encode([
	'error' => 'not_found',
	'path' => $path,
]);
