<?php
/**
 * Public Hub REST front controller.
 *
 * Install an Apache alias (Module Admin / docs):
 *   Alias /xrflow-hub /var/www/html/admin/modules/xrflowsoftphone/public
 *
 * Enroll/fleet routes are implemented in later versions. Unlicensed after trial → 402.
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
	$st = $hub->licenseStatus();
	echo json_encode([
		'ok' => true,
		'product' => 'xrflow_softphone_hub',
		'api_allowed' => !empty($st['api_allowed']),
		'reason' => $st['reason'] ?? null,
	]);
	exit;
}

if (!$hub->apiAllowed()) {
	http_response_code(402);
	echo json_encode($hub->restForbiddenPayload());
	exit;
}

http_response_code(501);
echo json_encode([
	'error' => 'not_implemented',
	'path' => $path,
	'message' => 'Enroll, heartbeat, and presence proxy land in later Hub versions.',
]);
