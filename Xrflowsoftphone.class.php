<?php
/**
 * XRFlow Softphone Hub — FreePBX/PBXact companion module.
 *
 * Copyright (C) 2026 XRFlow
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;

/**
 * Free GPLv3+ companion to the commercial XRFlow Softphone desktop client.
 *
 * Does not rewrite System Admin OpenVPN remotes, Easy-RSA, or sysadmin_server1.conf.
 * Does not mint desktop seat keys. Presence-sync write keys stay on this PBX.
 * This module is not sold and does not require a Hub license key.
 */
class Xrflowsoftphone extends FreePBX_Helpers implements BMO {

	public const MODULE_RAWNAME = 'xrflowsoftphone';
	public const LICENSE = 'GPLv3+';

	public function __construct($freepbx = null) {
		$this->FreePBX = $freepbx ?: \FreePBX::create();
	}

	public function install() {
		if (!$this->getConfig('deployment_uuid')) {
			$this->setConfig('deployment_uuid', $this->newUuid());
		}
		$this->installTables();
	}

	private function installTables() {
		$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS xrflowsoftphone_enroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  extension VARCHAR(20) NOT NULL,
  override_compliance TINYINT(1) NOT NULL DEFAULT 0,
  created_at INT NOT NULL,
  expires_at INT NOT NULL,
  redeemed_at INT NULL,
  UNIQUE KEY uq_hash (token_hash),
  KEY idx_ext (extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
		try {
			$this->FreePBX->Database()->query($sql);
		} catch (\Throwable $e) {
			// table may already exist (module.xml <database> also creates it)
		}
	}

	public function uninstall() {}
	public function backup() {}
	public function restore($backup) {}

	public function doConfigPageInit($page) {
		if (empty($_POST['xrflow_hub_action'])) {
			return;
		}
		$action = (string) $_POST['xrflow_hub_action'];
		if ($action === 'apply_webrtc') {
			$exts = $_POST['ext'] ?? [];
			if (!is_array($exts)) {
				$exts = [];
			}
			$override = !empty($_POST['force_override']);
			$_SESSION['xrflow_hub_flash'] = $this->applyWebrtcTemplate($exts, $override);
			return;
		}
		if ($action === 'enroll') {
			$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($_POST['enroll_ext'] ?? ''));
			$override = !empty($_POST['enroll_override']);
			$_SESSION['xrflow_hub_flash'] = $this->generateEnrollToken($ext, $override);
		}
	}

	public function getActionBar($request) {
		return [];
	}

	public function showPage() {
		$view = isset($_GET['view']) ? (string) $_GET['view'] : 'dashboard';
		if ($view === 'license') {
			$view = 'about';
		}
		$allowed = ['dashboard', 'about', 'compliance', 'enroll'];
		if (!in_array($view, $allowed, true)) {
			$view = 'dashboard';
		}
		$vars = [
			'status' => $this->hubStatus(),
			'flash' => $_SESSION['xrflow_hub_flash'] ?? null,
			'view' => $view,
			'companyPresence' => $this->detectCompanyPresence(),
			'compliance' => $view === 'compliance' ? $this->scanWebrtcCompliance() : [],
			'openvpnHint' => $this->openVpnSubnetHint(),
			'extensions' => $view === 'enroll' ? $this->listExtensions() : [],
		];
		unset($_SESSION['xrflow_hub_flash']);
		return load_view(__DIR__ . '/views/' . $view . '.php', $vars);
	}

	public function ajaxRequest($req, &$setting) {
		return in_array($req, ['hubStatus', 'licenseStatus'], true);
	}

	public function ajaxHandler() {
		$command = $_REQUEST['command'] ?? '';
		if ($command === 'hubStatus' || $command === 'licenseStatus') {
			return $this->hubStatus();
		}
		return ['status' => false, 'message' => 'unknown'];
	}

	/**
	 * Enroll/fleet REST is always on. The Hub is free software.
	 */
	public function apiAllowed() {
		return true;
	}

	public function hubStatus() {
		$uuid = (string) $this->getConfig('deployment_uuid');
		if ($uuid === '') {
			$uuid = $this->newUuid();
			$this->setConfig('deployment_uuid', $uuid);
		}
		return [
			'module' => self::MODULE_RAWNAME,
			'license' => self::LICENSE,
			'free' => true,
			'deployment_uuid' => $uuid,
			'api_allowed' => true,
			'reason' => 'free',
			// Compatibility for older dashboard snippets / desktop probes.
			'licensed' => true,
			'in_trial' => false,
			'product' => self::MODULE_RAWNAME,
		];
	}

	/** @deprecated Use hubStatus(). Kept so older AJAX clients keep working. */
	public function licenseStatus() {
		return $this->hubStatus();
	}

	public function detectCompanyPresence() {
		$modDir = '/var/www/html/admin/modules/companypresence';
		$installed = is_dir($modDir);
		$errno = 0;
		$errstr = '';
		$fp = @fsockopen('127.0.0.1', 3921, $errno, $errstr, 0.4);
		$reachable = is_resource($fp);
		if ($reachable) {
			fclose($fp);
		}
		return [
			'module_present' => $installed,
			'presence_sync_port_open' => $reachable,
		];
	}

	/** 488 checklist — match desktop pjsip-endpoint-media.ts */
	public static function webrtcExpected() {
		return [
			'webrtc' => 'yes',
			'avpf' => 'yes',
			'icesupport' => 'yes',
			'rtcp_mux' => 'yes',
			'media_encryption' => 'dtls',
			'dtls_auto_generate_cert' => 'yes',
			'direct_media' => 'no',
			'media_use_received_transport' => 'yes',
		];
	}

	public function scanWebrtcCompliance() {
		$rows = [];
		foreach ($this->listExtensions() as $ext => $name) {
			$kv = $this->pjsipKeywords($ext);
			$issues = $this->webrtcIssues($kv);
			$rows[] = [
				'extension' => $ext,
				'name' => $name,
				'ok' => $issues === [],
				'issues' => $issues,
				'keywords' => $kv,
			];
		}
		return $rows;
	}

	public function applyWebrtcTemplate(array $exts, $overrideLogged = false) {
		$expected = self::webrtcExpected();
		$applied = [];
		$skipped = [];
		foreach ($exts as $ext) {
			$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
			if ($ext === '') {
				continue;
			}
			$ok = $this->writePjsipKeywords($ext, $expected);
			if ($ok) {
				$applied[] = $ext;
			} else {
				$skipped[] = $ext;
			}
		}
		if ($applied) {
			needreload();
		}
		return [
			'ok' => $applied !== [],
			'message' => $applied
				? ('Applied WebRTC template to ' . implode(', ', $applied) . '. Apply Config in FreePBX.')
				: 'No extensions updated.',
			'applied' => $applied,
			'skipped' => $skipped,
			'override' => (bool) $overrideLogged,
		];
	}

	public function openVpnSubnetHint() {
		$conf = '/etc/asterisk/sysadmin_server1.conf';
		if (!is_readable($conf)) {
			return 'OpenVPN sysadmin_server1.conf not found (optional). AMI permit should include LAN plus tun0 (often 10.8.0.0/24). Do not rewrite remotes.';
		}
		$txt = (string) @file_get_contents($conf);
		if (preg_match('/server\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+)/', $txt, $m)) {
			return 'OpenVPN server ' . $m[1] . ' (do not rewrite remotes). Permit AMI for that subnet.';
		}
		return 'OpenVPN config present. Permit AMI for the tun subnet (often 10.8.0.0/24). Do not rewrite remotes.';
	}

	private function listExtensions() {
		$out = [];
		// FreePBX loads Core via __get and does not implement __isset, so
		// isset($this->FreePBX->Core) is always false. Touch the object instead.
		try {
			$core = $this->FreePBX->Core;
			if (is_object($core) && method_exists($core, 'listUsers')) {
				foreach ((array) $core->listUsers() as $row) {
					if (!is_array($row)) {
						continue;
					}
					$parsed = $this->parseExtensionRow($row);
					if ($parsed !== null) {
						$out[$parsed[0]] = $parsed[1];
					}
				}
			}
		} catch (\Throwable $e) {
			// fall through to SQL
		}
		if ($out) {
			return $out;
		}
		// users.extension is the FreePBX column (not users.user).
		try {
			$db = $this->FreePBX->Database;
			$st = $db->query('SELECT extension, name FROM users ORDER BY extension');
			if ($st) {
				foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$parsed = $this->parseExtensionRow($row);
					if ($parsed !== null) {
						$out[$parsed[0]] = $parsed[1];
					}
				}
			}
		} catch (\Throwable $e) {
			return $out;
		}
		return $out;
	}

	/**
	 * @param array<int|string, mixed> $row
	 * @return array{0:string,1:string}|null
	 */
	private function parseExtensionRow(array $row) {
		$ext = (string) ($row['extension'] ?? $row['user'] ?? $row[0] ?? '');
		if ($ext === '') {
			return null;
		}
		$name = (string) ($row['name'] ?? $row['description'] ?? $row[1] ?? $ext);
		return [$ext, $name !== '' ? $name : $ext];
	}

	private function pjsipKeywords($ext) {
		$kv = [];
		try {
			$db = $this->FreePBX->Database();
			foreach (['pjsip', 'sip'] as $table) {
				try {
					$st = $db->prepare("SELECT keyword, data FROM `$table` WHERE id = ?");
					$st->execute([$ext]);
					foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
						$kv[strtolower((string) $row['keyword'])] = strtolower(trim((string) $row['data']));
					}
					if ($kv) {
						return $kv;
					}
				} catch (\Throwable $e) {
					continue;
				}
			}
		} catch (\Throwable $e) {
			return $kv;
		}
		return $kv;
	}

	private function webrtcIssues(array $kv) {
		$issues = [];
		foreach (self::webrtcExpected() as $key => $want) {
			$have = $kv[$key] ?? '';
			if ($have !== $want) {
				$issues[] = $key . '=' . ($have !== '' ? $have : 'unset') . ' (want ' . $want . ')';
			}
		}
		return $issues;
	}

	public function generateEnrollToken($ext, $override = false) {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
		if ($ext === '') {
			return ['ok' => false, 'error' => 'Pick an extension.'];
		}
		$issues = $this->webrtcIssues($this->pjsipKeywords($ext));
		if ($issues && !$override) {
			return [
				'ok' => false,
				'error' => 'Extension fails WebRTC checks: ' . implode('; ', $issues) . '. Repair first or check override (logged).',
			];
		}
		$raw = bin2hex(random_bytes(16));
		$hash = hash('sha256', $raw);
		$now = time();
		try {
			$st = $this->FreePBX->Database()->prepare(
				'INSERT INTO xrflowsoftphone_enroll (token_hash, extension, override_compliance, created_at, expires_at) VALUES (?,?,?,?,?)'
			);
			$st->execute([$hash, $ext, $override ? 1 : 0, $now, $now + 900]);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Could not store enroll token (install tables / fwconsole ma install).'];
		}
		$host = $this->publicHost();
		$https = 'https://' . $host . '/xrflow-hub/enroll/' . $raw;
		return [
			'ok' => true,
			'message' => 'One-time enroll token (15 minutes).',
			'token' => $raw,
			'deep_link' => 'xrflow://enroll/' . $raw,
			'https_link' => $https,
			'extension' => $ext,
			'override' => (bool) $override,
		];
	}

	public function redeemEnrollToken($raw) {
		$raw = preg_replace('/[^0-9a-f]/', '', strtolower((string) $raw));
		if (strlen($raw) !== 32) {
			return ['ok' => false, 'http' => 400, 'error' => 'invalid_token'];
		}
		$hash = hash('sha256', $raw);
		$db = $this->FreePBX->Database();
		$st = $db->prepare('SELECT * FROM xrflowsoftphone_enroll WHERE token_hash = ? LIMIT 1');
		$st->execute([$hash]);
		$row = $st->fetch(\PDO::FETCH_ASSOC);
		if (!$row) {
			return ['ok' => false, 'http' => 404, 'error' => 'unknown_token'];
		}
		if (!empty($row['redeemed_at'])) {
			return ['ok' => false, 'http' => 410, 'error' => 'token_used'];
		}
		if ((int) $row['expires_at'] < time()) {
			return ['ok' => false, 'http' => 410, 'error' => 'token_expired'];
		}
		$upd = $db->prepare('UPDATE xrflowsoftphone_enroll SET redeemed_at = ? WHERE id = ? AND redeemed_at IS NULL');
		$upd->execute([time(), $row['id']]);
		if ($upd->rowCount() < 1) {
			return ['ok' => false, 'http' => 410, 'error' => 'token_used'];
		}
		$payload = $this->buildEnrollPayload((string) $row['extension']);
		$payload['companyPresence'] = !empty($this->detectCompanyPresence()['presence_sync_port_open']);
		return ['ok' => true, 'payload' => $payload];
	}

	public function buildEnrollPayload($ext) {
		$host = $this->publicHost();
		$amiLan = $this->amiLanHost();
		$secret = '';
		$display = $ext;
		$authUser = $ext;
		try {
			$core = $this->FreePBX->Core;
			if (is_object($core) && method_exists($core, 'getDevice')) {
				$dev = $core->getDevice($ext);
				if (is_array($dev)) {
					$secret = (string) ($dev['secret'] ?? $dev['sippasswd'] ?? '');
					$display = (string) ($dev['description'] ?? $dev['name'] ?? $display);
					$authUser = (string) ($dev['username'] ?? $dev['sipname'] ?? $authUser);
				}
			}
		} catch (\Throwable $e) {
			// keep defaults
		}
		$kv = $this->pjsipKeywords($ext);
		if ($secret === '' && !empty($kv['secret'])) {
			$secret = $kv['secret'];
		}
		return [
			'host' => $host,
			'https' => true,
			'sipDomain' => $host,
			'uriUser' => $ext,
			'authUser' => $authUser ?: $ext,
			'sipSecret' => $secret,
			'displayName' => $display,
			'sipWebsocketUrl' => 'wss://' . $host . ':6443/ws',
			'sipLanWebsocketUrl' => 'wss://' . $amiLan . ':6443/ws',
			'sipPreferVpnLan' => $amiLan !== $host,
			'amiHost' => $amiLan,
			'amiPort' => 5038,
			'amiUsername' => 'xrflow-hub',
			'amiSecret' => '',
			'openVpnHint' => 'Use the System Admin .ovpn already issued; remotes unmodified.',
			'policy' => ['minVersion' => '0.2.25', 'lockSettings' => true],
		];
	}

	private function publicHost() {
		$h = (string) ($_SERVER['HTTP_HOST'] ?? '');
		$h = preg_replace('/:\d+$/', '', $h);
		if ($h !== '' && $h !== 'localhost') {
			return $h;
		}
		return gethostname() ?: 'pbx.local';
	}

	private function amiLanHost() {
		$conf = '/etc/asterisk/sysadmin_server1.conf';
		if (is_readable($conf)) {
			$txt = (string) @file_get_contents($conf);
			if (preg_match('/server\s+(\d+\.\d+\.\d+\.\d+)/', $txt, $m)) {
				return $m[1];
			}
			if (preg_match('/ifconfig-push\s+(\d+\.\d+\.\d+\.\d+)/', $txt, $m)) {
				return $m[1];
			}
		}
		if (is_readable('/etc/asterisk/openvpn/server1.conf')) {
			$txt = (string) @file_get_contents('/etc/asterisk/openvpn/server1.conf');
			if (preg_match('/server\s+(\d+\.\d+\.\d+\.\d+)/', $txt, $m)) {
				return $m[1];
			}
		}
		return '10.8.0.1';
	}

	private function writePjsipKeywords($ext, array $kv) {
		try {
			$db = $this->FreePBX->Database();
			$table = 'pjsip';
			try {
				$db->query("SELECT 1 FROM `$table` LIMIT 1");
			} catch (\Throwable $e) {
				$table = 'sip';
			}
			$st = $db->prepare("REPLACE INTO `$table` (id, keyword, data, flags) VALUES (?, ?, ?, 0)");
			foreach ($kv as $k => $v) {
				$st->execute([$ext, $k, $v]);
			}
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function newUuid() {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
