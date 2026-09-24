<?php
/**
 * Fleet heartbeat and desktop seat pool for XRFlow Softphone Hub.
 *
 * Heartbeats are SIP-secret authenticated and stay on this PBX.
 * Seats are listed with the org token from xrflows.com. Hub does not
 * store an xrflows.com user password and does not mint license keys.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
namespace FreePBX\modules;

trait XrflowFleet {

	public const FLEET_ONLINE_SEC = 180;
	public const FLEET_KEEP_SEC = 2592000;
	public const ORG_API_DEFAULT = 'https://xrflows.com';
	public const ORG_CACHE_SEC = 180;

	/**
	 * Desk check-in. Auth is the softphone device SIP secret.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{ok:bool,http?:int,error?:string}
	 */
	public function recordHeartbeat($extension, $secret, array $meta): array {
		$auth = $this->authenticateDeskSecret($extension, $secret);
		if (empty($auth['ok'])) {
			return $auth;
		}
		$ext = $auth['extension'];
		$rows = $this->fleetMap();
		$prev = is_array($rows[$ext] ?? null) ? $rows[$ext] : [];
		$name = $this->fleetClip((string) ($meta['name'] ?? ''), 80);
		if ($name === '' && !empty($prev['name'])) {
			$name = (string) $prev['name'];
		}
		$rows[$ext] = [
			'extension' => $ext,
			'name' => $name,
			'version' => $this->fleetVersion((string) ($meta['app_version'] ?? '')),
			'licensed' => $this->fleetBool($meta['licensed'] ?? false),
			'registered' => $this->fleetBool($meta['registered'] ?? false),
			'last_seen' => time(),
		];
		$this->setConfig('fleet_heartbeats', $this->trimFleet($rows));
		return ['ok' => true];
	}

	/**
	 * @return list<array{extension:string,name:string,version:string,licensed:bool,registered:bool,last_seen:int,online:bool}>
	 */
	public function fleetRows(): array {
		$rows = [];
		$now = time();
		foreach ($this->fleetMap() as $row) {
			if (!is_array($row)) {
				continue;
			}
			$seen = (int) ($row['last_seen'] ?? 0);
			$rows[] = [
				'extension' => (string) ($row['extension'] ?? ''),
				'name' => (string) ($row['name'] ?? ''),
				'version' => (string) ($row['version'] ?? ''),
				'licensed' => !empty($row['licensed']),
				'registered' => !empty($row['registered']),
				'last_seen' => $seen,
				'online' => $seen > 0 && ($now - $seen) < self::FLEET_ONLINE_SEC,
			];
		}
		usort($rows, static function ($a, $b) {
			return strnatcmp($a['extension'], $b['extension']);
		});
		return $rows;
	}

	public function fleetSummary(): array {
		$rows = $this->fleetRows();
		$online = 0;
		foreach ($rows as $row) {
			if (!empty($row['online'])) {
				$online++;
			}
		}
		return ['total' => count($rows), 'online' => $online];
	}

	public function orgApiBase(): string {
		$base = $this->normalizeOrgApiBase((string) $this->getConfig('org_api_base'));
		return $base !== '' ? $base : self::ORG_API_DEFAULT;
	}

	public function orgTokenSaved(): bool {
		return strlen((string) $this->getConfig('org_token')) >= 16;
	}

	public function orgTokenHint(): string {
		$token = (string) $this->getConfig('org_token');
		if (strlen($token) < 4) {
			return '';
		}
		return substr($token, -4);
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function saveOrgSettings($base, $token): array {
		$norm = $this->normalizeOrgApiBase($base);
		if ($norm === '') {
			return [
				'ok' => false,
				'message' => 'API address must be https://hostname with no path, user, or password.',
			];
		}
		$this->setConfig('org_api_base', $norm);
		$token = trim((string) $token);
		if ($token !== '') {
			if (strlen($token) < 16 || preg_match('/\s/', $token)) {
				return [
					'ok' => false,
					'message' => 'That org token is too short. Paste the full token from License Authority → Org Tokens.',
				];
			}
			$this->setConfig('org_token', $token);
			$this->setConfig('org_grants_cache', []);
		}
		return ['ok' => true, 'message' => 'Seat pool settings saved.'];
	}

	public function clearOrgToken(): array {
		$this->setConfig('org_token', '');
		$this->setConfig('org_grants_cache', []);
		return ['ok' => true, 'message' => 'Org token removed from this PBX.'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function seatPoolView($force = false): array {
		$view = [
			'base' => $this->orgApiBase(),
			'token_saved' => $this->orgTokenSaved(),
			'token_hint' => $this->orgTokenHint(),
			'grants' => [],
			'unused_capacity' => 0,
			'count' => 0,
			'fetched_at' => 0,
			'from_cache' => false,
			'stale' => false,
			'error' => '',
			'message' => '',
		];
		if (!$view['token_saved']) {
			return $view;
		}
		$sync = $this->syncOrgGrants($force);
		if (!empty($sync['grants']) && is_array($sync['grants'])) {
			$view['grants'] = $sync['grants'];
			$view['unused_capacity'] = $sync['unused_capacity'] ?? 0;
			$view['count'] = (int) ($sync['count'] ?? count($sync['grants']));
			$view['fetched_at'] = (int) ($sync['fetched_at'] ?? 0);
			$view['from_cache'] = !empty($sync['from_cache']);
			$view['stale'] = !empty($sync['stale']);
		}
		if (empty($sync['ok'])) {
			$view['error'] = (string) ($sync['error'] ?? 'sync_failed');
			$view['message'] = (string) ($sync['message'] ?? $this->orgErrorMessage($view['error']));
		}
		return $view;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function syncOrgGrants($force = false): array {
		$cached = $this->getConfig('org_grants_cache');
		$cached = is_array($cached) ? $cached : [];
		$age = time() - (int) ($cached['fetched_at'] ?? 0);
		if (!$force && $age >= 0 && $age < self::ORG_CACHE_SEC && !empty($cached['grants'])) {
			$cached['ok'] = true;
			$cached['from_cache'] = true;
			return $cached;
		}
		$res = $this->orgRequest('GET', '/api/v1/org/grants?product=xrflow_softphone', null);
		if (empty($res['ok'])) {
			if (!empty($cached['grants'])) {
				$cached['ok'] = false;
				$cached['stale'] = true;
				$cached['from_cache'] = true;
				$cached['error'] = $res['error'] ?? 'sync_failed';
				$cached['message'] = $res['message'] ?? $this->orgErrorMessage((string) $cached['error']);
				return $cached;
			}
			return $res;
		}
		$body = is_array($res['body'] ?? null) ? $res['body'] : [];
		$grants = [];
		foreach ((array) ($body['grants'] ?? []) as $row) {
			if (is_array($row)) {
				$grants[] = $this->publicGrantRow($row);
			}
		}
		$store = [
			'ok' => true,
			'fetched_at' => time(),
			'product' => (string) ($body['product'] ?? 'xrflow_softphone'),
			'count' => count($grants),
			'unused_capacity' => $body['unused_capacity'] ?? 0,
			'grants' => $grants,
		];
		$this->setConfig('org_grants_cache', $store);
		return $store;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function assignOrgGrant($grantId, $extension): array {
		$id = (int) $grantId;
		if ($id <= 0) {
			return ['ok' => false, 'message' => 'Pick a seat grant.'];
		}
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$ext = substr((string) $ext, 0, 20);
		$res = $this->orgRequest('POST', '/api/v1/org/grants/assign', [
			'grant_id' => $id,
			'extension' => $ext,
		]);
		if (empty($res['ok'])) {
			return $res;
		}
		$grant = is_array($res['body']['grant'] ?? null) ? $res['body']['grant'] : [];
		$this->patchCachedGrant($id, $grant);
		$label = $ext !== '' ? $ext : 'cleared';
		return ['ok' => true, 'message' => 'Assignment saved (' . $label . ').'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function mutateOrgGrant($action, $grantId): array {
		$id = (int) $grantId;
		if ($id <= 0) {
			return ['ok' => false, 'message' => 'Pick a seat grant.'];
		}
		$path = [
			'deactivate' => '/api/v1/org/grants/deactivate',
			'reactivate' => '/api/v1/org/grants/reactivate',
		][$action] ?? '';
		if ($path === '') {
			return ['ok' => false, 'message' => 'Unknown seat action.'];
		}
		$res = $this->orgRequest('POST', $path, ['grant_id' => $id]);
		if (empty($res['ok'])) {
			return $res;
		}
		$grant = is_array($res['body']['grant'] ?? null) ? $res['body']['grant'] : [];
		$this->patchCachedGrant($id, $grant);
		if ($action === 'deactivate') {
			$n = (int) ($res['body']['deactivated'] ?? 0);
			return ['ok' => true, 'message' => 'Deactivated ' . $n . ' active seat(s) on this grant.'];
		}
		$n = (int) ($res['body']['reactivated'] ?? 0);
		return ['ok' => true, 'message' => 'Reactivated ' . $n . ' seat(s) on this grant.'];
	}

	/**
	 * One-time key. Returned to the admin session only. Not stored.
	 *
	 * @return array<string,mixed>
	 */
	public function revealOrgGrant($grantId): array {
		$id = (int) $grantId;
		if ($id <= 0) {
			return ['ok' => false, 'message' => 'Pick a seat grant.'];
		}
		$res = $this->orgRequest('POST', '/api/v1/org/grants/reveal', ['grant_id' => $id]);
		if (empty($res['ok'])) {
			return $res;
		}
		$body = is_array($res['body'] ?? null) ? $res['body'] : [];
		$key = trim((string) ($body['key'] ?? ''));
		$grant = is_array($body['grant'] ?? null) ? $body['grant'] : [];
		unset($body['key']);
		$this->patchCachedGrant($id, $grant);
		if ($key === '') {
			return ['ok' => false, 'message' => 'No license key was returned.'];
		}
		return [
			'ok' => true,
			'message' => 'Copy this license key now. Hub will not show it again.',
			'license_key' => $key,
		];
	}

	/**
	 * @return array{ok:bool,http?:int,error?:string,extension?:string}
	 */
	private function authenticateDeskSecret($extension, $secret): array {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$secret = (string) $secret;
		if ($ext === '' || $secret === '') {
			return ['ok' => false, 'http' => 400, 'error' => 'missing_credentials'];
		}
		$stored = (string) ($this->pjsipKeywords($ext)['secret'] ?? '');
		if ($stored === '' || !hash_equals($stored, $secret)) {
			return ['ok' => false, 'http' => 401, 'error' => 'auth_failed'];
		}
		return ['ok' => true, 'extension' => $ext];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function fleetMap(): array {
		$raw = $this->getConfig('fleet_heartbeats');
		return is_array($raw) ? $raw : [];
	}

	/**
	 * @param array<string,array<string,mixed>> $rows
	 * @return array<string,array<string,mixed>>
	 */
	private function trimFleet(array $rows): array {
		$cut = time() - self::FLEET_KEEP_SEC;
		foreach ($rows as $ext => $row) {
			if (!is_array($row) || (int) ($row['last_seen'] ?? 0) < $cut) {
				unset($rows[$ext]);
			}
		}
		if (count($rows) > 2000) {
			uasort($rows, static function ($a, $b) {
				return ((int) ($a['last_seen'] ?? 0)) <=> ((int) ($b['last_seen'] ?? 0));
			});
			$rows = array_slice($rows, -2000, null, true);
		}
		return $rows;
	}

	private function fleetClip($text, $max): string {
		$text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $max);
		}
		return substr($text, 0, $max);
	}

	private function fleetVersion($version): string {
		$version = trim((string) $version);
		if ($version === '' || !preg_match('/^[0-9A-Za-z._+-]{1,32}$/', $version)) {
			return 'unknown';
		}
		return $version;
	}

	private function fleetBool($value): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}

	private function normalizeOrgApiBase($raw): string {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return self::ORG_API_DEFAULT;
		}
		$raw = rtrim($raw, '/');
		if (!preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?$#', $raw)) {
			return '';
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed>|null $payload
	 * @return array<string,mixed>
	 */
	private function orgRequest($method, $path, $payload): array {
		$token = (string) $this->getConfig('org_token');
		if (strlen($token) < 16) {
			return [
				'ok' => false,
				'http' => 400,
				'error' => 'org_token_missing',
				'message' => $this->orgErrorMessage('org_token_missing'),
			];
		}
		if (!function_exists('curl_init')) {
			return [
				'ok' => false,
				'error' => 'curl_missing',
				'message' => 'PHP curl is not installed on this PBX.',
			];
		}
		$url = $this->orgApiBase() . $path;
		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
			'User-Agent: XRFlow-Softphone-Hub',
		];
		$ch = curl_init($url);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_CUSTOMREQUEST => strtoupper((string) $method),
			CURLOPT_FOLLOWLOCATION => false,
		];
		if (defined('CURLPROTO_HTTPS')) {
			$opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
			if (defined('CURLOPT_REDIR_PROTOCOLS')) {
				$opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
			}
		}
		if ($payload !== null) {
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($payload);
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		if ($raw === false || $errno !== 0) {
			return [
				'ok' => false,
				'http' => 502,
				'error' => 'org_unreachable',
				'message' => $this->orgErrorMessage('org_unreachable'),
			];
		}
		if (!is_string($raw) || strlen($raw) > 1000000) {
			return [
				'ok' => false,
				'error' => 'response_too_large',
				'message' => 'License service response was too large.',
			];
		}
		$json = json_decode($raw, true);
		if (!is_array($json)) {
			return [
				'ok' => false,
				'http' => $code ?: 502,
				'error' => 'bad_json',
				'message' => 'License service did not return JSON.',
			];
		}
		$revealed = '';
		if (isset($json['key']) && is_string($json['key'])) {
			$revealed = $json['key'];
		}
		unset($json['key'], $json['pending_full_key'], $json['full_key']);
		if ($code < 200 || $code >= 300) {
			$err = (string) ($json['error'] ?? 'request_failed');
			return [
				'ok' => false,
				'http' => $code,
				'error' => $err,
				'message' => $this->orgErrorMessage($err),
			];
		}
		if ($revealed !== '') {
			$json['key'] = $revealed;
		}
		return ['ok' => true, 'http' => $code, 'body' => $json];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function publicGrantRow(array $row): array {
		unset($row['key'], $row['pending_full_key'], $row['full_key'], $row['token']);
		$keep = [
			'grant_id', 'product', 'tier', 'state', 'perpetual', 'valid_until',
			'max_activations', 'used_activations', 'unused_capacity',
			'assigned_extension', 'key_prefix', 'has_pending_key',
		];
		$out = [];
		foreach ($keep as $key) {
			if (array_key_exists($key, $row)) {
				$out[$key] = $row[$key];
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $grant
	 */
	private function patchCachedGrant($grantId, array $grant): void {
		$cached = $this->getConfig('org_grants_cache');
		if (!is_array($cached) || empty($cached['grants']) || !is_array($cached['grants'])) {
			return;
		}
		$clean = $this->publicGrantRow($grant);
		if ($clean === []) {
			return;
		}
		foreach ($cached['grants'] as $i => $row) {
			if (is_array($row) && (int) ($row['grant_id'] ?? 0) === (int) $grantId) {
				$cached['grants'][$i] = array_merge($row, $clean);
				$this->setConfig('org_grants_cache', $cached);
				return;
			}
		}
	}

	private function orgErrorMessage($error): string {
		switch ((string) $error) {
			case 'unauthorized':
				return 'The org token was rejected. Generate a new one under License Authority → Org Tokens and paste it here.';
			case 'product_not_allowed':
				return 'This token cannot list Softphone seats.';
			case 'grant_not_found':
				return 'That seat was not found for this token.';
			case 'key_already_delivered':
				return 'This key was already revealed. It cannot be shown again.';
			case 'rate_limited':
				return 'Too many license lookups from this PBX today. Try again later.';
			case 'org_unreachable':
				return 'Could not reach the license service from this PBX.';
			case 'org_token_missing':
				return 'Paste the org token from xrflows.com first.';
			case 'invalid_json':
				return 'The license service rejected the request.';
			default:
				return 'License service returned ' . preg_replace('/[^a-z0-9_]/', '', (string) $error) . '.';
		}
	}
}
