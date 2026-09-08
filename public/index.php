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
