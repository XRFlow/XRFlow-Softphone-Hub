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
	/** Companion PJSIP device id = prefix + user ext. 99=UCP, 98=Sangoma Connect. */
	public const SOFTPHONE_PREFIX = '97';
	public const AMI_USER = 'xrflow-hub';
	public const AMI_ACL = 'system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate';

	public function __construct($freepbx = null) {
		$this->FreePBX = $freepbx ?: \FreePBX::create();
	}

	public function install() {
		if (!$this->getConfig('deployment_uuid')) {
			$this->setConfig('deployment_uuid', $this->newUuid());
		}
		$this->installTables();
		$this->ensureAmiUser();
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
		try {
			$this->FreePBX->Database()->query(
				'ALTER TABLE xrflowsoftphone_enroll ADD COLUMN need_openvpn TINYINT(1) NOT NULL DEFAULT 0'
			);
		} catch (\Throwable $e) {
			// column already present
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
		if ($action === 'enroll' || $action === 'enroll_repair') {
			$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($_POST['enroll_ext'] ?? ''));
			$override = !empty($_POST['enroll_override']);
			$needVpn = !empty($_POST['enroll_vpn']);
			$this->setExtensionVpn($ext, $needVpn);
			if ($action === 'enroll_repair') {
				$repair = $this->applyWebrtcTemplate([$ext], $override);
				if (empty($repair['ok'])) {
					$_SESSION['xrflow_hub_flash'] = [
						'ok' => false,
						'error' => $repair['message'] ?? 'Could not set up the softphone device.',
						'extension' => $ext,
					];
					return;
				}
			}
			$flash = $this->generateEnrollToken($ext, $override, $needVpn);
			if ($action === 'enroll_repair' && !empty($flash['ok'])) {
				$flash['repaired'] = true;
				$flash['message'] = ($repair['message'] ?? '') . ' ' . ($flash['message'] ?? '');
			}
			$_SESSION['xrflow_hub_flash'] = $flash;
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
			'compliance' => ($view === 'compliance' || $view === 'enroll') ? $this->scanWebrtcCompliance() : [],
			'openvpnHint' => $this->openVpnSubnetHint(),
			'extensions' => $view === 'enroll' ? $this->listExtensions() : [],
			'vpnByExt' => $view === 'enroll' ? $this->vpnMap() : [],
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
		$ami = $this->ensureAmiUser();
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
			'ami_user' => $ami['username'] ?? '',
			'ami_port' => $ami['port'] ?? 5038,
			'ami_ready' => $ami !== [] && ($ami['secret'] ?? '') !== '',
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
			$softId = $this->findSoftphoneDevice($ext);
			$softKv = $softId !== '' ? $this->pjsipKeywords($softId) : [];
			$issues = $this->webrtcIssues($softKv);
			$rows[] = [
				'extension' => $ext,
				'name' => $name,
				'softphone_device' => $softId,
				'desk_phone' => $this->hasDeskPhone($ext),
				'ok' => $softId !== '' && $issues === [],
				'issues' => $issues,
				'keywords' => $softKv,
			];
		}
		return $rows;
	}

	public function applyWebrtcTemplate(array $exts, $overrideLogged = false) {
		$applied = [];
		$skipped = [];
		$devices = [];
		foreach ($exts as $ext) {
			$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
			if ($ext === '') {
				continue;
			}
			$dev = $this->ensureSoftphoneDevice($ext);
			if ($dev === '') {
				$skipped[] = $ext;
				continue;
			}
			$template = self::webrtcExpected();
			$existingKv = $this->pjsipKeywords($dev);
			if (($existingKv['media_encryption'] ?? '') === 'dtls' && ($existingKv['dtls_auto_generate_cert'] ?? '') !== 'yes') {
				unset($template['dtls_auto_generate_cert']);
			}
			if ($this->writePjsipKeywords($dev, $template)) {
				$applied[] = $ext;
				$devices[$ext] = $dev;
			} else {
				$skipped[] = $ext;
			}
		}
		if ($applied) {
			try {
				needreload();
			} catch (\Throwable $e) {
				// CLI / missing admin DB handle — device rows are already written.
			}
		}
		$msg = $applied
			? ('Set up a separate softphone device for ' . implode(', ', $applied)
				. ' (desk phones were not changed). Click Apply Config in FreePBX (red button) before they call.')
			: 'No softphone devices were updated.';
		return [
			'ok' => $applied !== [],
			'message' => $msg,
			'applied' => $applied,
			'skipped' => $skipped,
			'devices' => $devices,
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
		$table = $this->endpointTable($ext);
		try {
			$st = $this->FreePBX->Database->prepare("SELECT keyword, data FROM `$table` WHERE id = ?");
			$st->execute([$ext]);
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$key = strtolower((string) $row['keyword']);
				$val = trim((string) $row['data']);
				if ($key !== 'secret') {
					$val = strtolower($val);
				}
				$kv[$key] = $val;
			}
		} catch (\Throwable $e) {
			return $kv;
		}
		return $kv;
	}

	/**
	 * FreePBX stores chan_pjsip *extensions* in `sip`. The `pjsip` table is trunks.
	 */
	private function endpointTable($id) {
		$db = $this->FreePBX->Database;
		foreach (['sip', 'pjsip'] as $table) {
			try {
				$st = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ?");
				$st->execute([$id]);
				if ((int) $st->fetchColumn() > 0) {
					return $table;
				}
			} catch (\Throwable $e) {
				continue;
			}
		}
		return 'sip';
	}

	public static function webrtcIssueLabels() {
		return [
			'webrtc' => 'Softphone calling is not enabled on the softphone device',
			'avpf' => 'AVPF (the audio profile the app needs) is off',
			'icesupport' => 'ICE is off (needed through firewalls and NAT)',
			'rtcp_mux' => 'RTCP multiplexing is off',
			'media_encryption' => 'Call encryption is not DTLS-SRTP',
			'dtls_auto_generate_cert' => 'DTLS certificate auto-generate is off',
			'direct_media' => 'Direct media is on (the app needs it off)',
			'media_use_received_transport' => 'The device is not using the caller media path',
		];
	}

	private function webrtcIssues(array $kv) {
		$labels = self::webrtcIssueLabels();
		$issues = [];
		if ($kv === []) {
			return [_('No softphone device yet. Hub will add one next to the desk phone; the desk phone is not changed.')];
		}
		foreach (self::webrtcExpected() as $key => $want) {
			$have = $kv[$key] ?? '';
			// Certman-managed DTLS (Sangoma Connect / UCP) is as good as auto-generate.
			if ($key === 'dtls_auto_generate_cert' && ($kv['media_encryption'] ?? '') === 'dtls') {
				continue;
			}
			if ($have !== $want) {
				$issues[] = $labels[$key] ?? ($key . ' needs to be ' . $want);
			}
		}
		return $issues;
	}

	public function generateEnrollToken($ext, $override = false, $needVpn = false) {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
		if ($ext === '') {
			return ['ok' => false, 'error' => 'Pick an extension.'];
		}
		$softId = $this->findSoftphoneDevice($ext);
		$issues = $this->webrtcIssues($softId !== '' ? $this->pjsipKeywords($softId) : []);
		if ($issues && !$override) {
			return [
				'ok' => false,
				'error' => 'This extension is not ready for the softphone yet.',
				'needs_repair' => true,
				'extension' => $ext,
				'issues' => $issues,
				'hint' => 'Use “Fix calling settings & enroll”. That adds a separate softphone device and does not change the desk phone. Then click Apply Config (red button) in FreePBX.',
			];
		}
		$raw = bin2hex(random_bytes(16));
		$hash = hash('sha256', $raw);
		$now = time();
		try {
			$st = $this->FreePBX->Database()->prepare(
				'INSERT INTO xrflowsoftphone_enroll (token_hash, extension, override_compliance, created_at, expires_at, need_openvpn) VALUES (?,?,?,?,?,?)'
			);
			$st->execute([$hash, $ext, $override ? 1 : 0, $now, $now + 900, $needVpn ? 1 : 0]);
		} catch (\Throwable $e) {
			try {
				$st = $this->FreePBX->Database()->prepare(
					'INSERT INTO xrflowsoftphone_enroll (token_hash, extension, override_compliance, created_at, expires_at) VALUES (?,?,?,?,?)'
				);
				$st->execute([$hash, $ext, $override ? 1 : 0, $now, $now + 900]);
			} catch (\Throwable $e2) {
				return ['ok' => false, 'error' => 'Could not store enroll token (install tables / fwconsole ma install).'];
			}
		}
		$host = $this->publicHost();
		$https = 'https://' . $host . '/xrflow-hub/enroll/' . $raw;
		return [
			'ok' => true,
			'message' => 'One-time enroll code (15 minutes, one use). Desk phone on this extension was not changed.',
			'token' => $raw,
			'deep_link' => 'xrflow://enroll/' . $raw,
			'https_link' => $https,
			'extension' => $ext,
			'softphone_device' => $softId,
			'need_openvpn' => (bool) $needVpn,
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
		$needVpn = !empty($row['need_openvpn']) || $this->extensionNeedsVpn((string) $row['extension']);
		$payload = $this->buildEnrollPayload((string) $row['extension'], $needVpn);
		$payload['companyPresence'] = !empty($this->detectCompanyPresence()['presence_sync_port_open']);
		return ['ok' => true, 'payload' => $payload];
	}

	public function buildEnrollPayload($ext, $needVpn = false) {
		$host = $this->publicHost();
		$amiLan = $this->amiLanHost();
		$sipId = $this->findSoftphoneDevice($ext);
		if ($sipId === '') {
			$sipId = $ext;
		}
		$secret = '';
		$display = $ext;
		$authUser = $sipId;
		try {
			$core = $this->FreePBX->Core;
			if (is_object($core) && method_exists($core, 'getDevice')) {
				$dev = $core->getDevice($sipId);
				if (is_array($dev)) {
					$secret = (string) ($dev['secret'] ?? $dev['sippasswd'] ?? '');
					$display = (string) ($dev['description'] ?? $dev['name'] ?? $display);
					$authUser = (string) ($dev['username'] ?? $dev['sipname'] ?? $authUser);
				}
				$user = method_exists($core, 'getUser') ? $core->getUser($ext) : null;
				if (is_array($user) && !empty($user['name'])) {
					$display = (string) $user['name'];
				}
			}
		} catch (\Throwable $e) {
			// keep defaults
		}
		$kv = $this->pjsipKeywords($sipId);
		if ($secret === '' && !empty($kv['secret'])) {
			$secret = $kv['secret'];
		}
		$vpnHint = $needVpn
			? 'Use the System Admin .ovpn already issued; remotes unmodified.'
			: '';
		$ami = $this->ensureAmiUser();
		$amiHost = $needVpn ? $amiLan : $this->amiOfficeHost();
		return [
			'host' => $host,
			'https' => true,
			'sipDomain' => $host,
			'uriUser' => $sipId,
			'authUser' => $authUser ?: $sipId,
			'sipSecret' => $secret,
			'displayName' => $display,
			'sipWebsocketUrl' => 'wss://' . $host . ':6443/ws',
			'sipLanWebsocketUrl' => $needVpn ? ('wss://' . $amiLan . ':6443/ws') : ('wss://' . $host . ':6443/ws'),
			'sipPreferVpnLan' => (bool) $needVpn,
			'amiHost' => $amiHost,
			'amiPort' => (int) ($ami['port'] ?? 5038),
			'amiUsername' => (string) ($ami['username'] ?? self::AMI_USER),
			'amiSecret' => (string) ($ami['secret'] ?? ''),
			'openVpnHint' => $vpnHint,
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
		if ($this->isPrimaryDeskPhone($ext)) {
			return false;
		}
		try {
			$table = $this->endpointTable($ext);
			$st = $this->FreePBX->Database->prepare(
				"REPLACE INTO `$table` (id, keyword, data, flags) VALUES (?, ?, ?, 0)"
			);
			foreach ($kv as $k => $v) {
				$st->execute([$ext, $k, $v]);
			}
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function vpnMap() {
		$m = $this->getConfig('vpn_by_extension');
		return is_array($m) ? $m : [];
	}

	private function extensionNeedsVpn($ext) {
		$m = $this->vpnMap();
		return !empty($m[$ext]);
	}

	private function setExtensionVpn($ext, $need) {
		if ($ext === '') {
			return;
		}
		$m = $this->vpnMap();
		if ($need) {
			$m[$ext] = true;
		} else {
			unset($m[$ext]);
		}
		$this->setConfig('vpn_by_extension', $m);
	}

	private function hasDeskPhone($ext) {
		$kv = $this->pjsipKeywords($ext);
		if ($kv === []) {
			return false;
		}
		$webrtc = $kv['webrtc'] ?? '';
		return $webrtc !== 'yes';
	}

	private function isPrimaryDeskPhone($id) {
		try {
			$core = $this->FreePBX->Core;
			$dev = (is_object($core) && method_exists($core, 'getDevice')) ? $core->getDevice($id) : [];
		} catch (\Throwable $e) {
			$dev = [];
		}
		if (!is_array($dev) || $dev === []) {
			return false;
		}
		$user = (string) ($dev['user'] ?? $id);
		if ($user !== (string) $id) {
			return false;
		}
		return $this->hasDeskPhone($id);
	}

	/**
	 * Prefer an existing WebRTC companion (97/98/99 + ext, or any other device
	 * on this user that already has webrtc=yes). Never return the desk phone.
	 */
	private function findSoftphoneDevice($ext) {
		$candidates = [];
		foreach ([self::SOFTPHONE_PREFIX, '98', '99'] as $prefix) {
			$candidates[] = $prefix . $ext;
		}
		try {
			$st = $this->FreePBX->Database->prepare(
				'SELECT id FROM devices WHERE user = ? AND id <> ? ORDER BY id'
			);
			$st->execute([$ext, $ext]);
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$candidates[] = (string) $row['id'];
			}
		} catch (\Throwable $e) {
			// devices table query is best-effort
		}
		$seen = [];
		foreach ($candidates as $id) {
			if ($id === '' || $id === (string) $ext || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$kv = $this->pjsipKeywords($id);
			if ($kv === []) {
				continue;
			}
			if (($kv['webrtc'] ?? '') === 'yes' || strpos($id, self::SOFTPHONE_PREFIX . $ext) === 0) {
				return $id;
			}
		}
		foreach (array_keys($seen) as $id) {
			if ($this->pjsipKeywords($id) !== []) {
				return $id;
			}
		}
		return '';
	}

	private function ensureSoftphoneDevice($ext) {
		$existing = $this->findSoftphoneDevice($ext);
		if ($existing !== '') {
			return $existing;
		}
		$id = self::SOFTPHONE_PREFIX . $ext;
		try {
			$core = $this->FreePBX->Core;
			if (!is_object($core) || !method_exists($core, 'addDevice')) {
				return '';
			}
			$already = method_exists($core, 'getDevice') ? $core->getDevice($id) : [];
			if (is_array($already) && $already !== []) {
				return $id;
			}
			$user = method_exists($core, 'getUser') ? $core->getUser($ext) : [];
			$name = (is_array($user) && !empty($user['name'])) ? (string) $user['name'] : $ext;
			$settings = $core->generateDefaultDeviceSettings('pjsip', $id, 'XRFlow ' . $name);
			if (!is_array($settings) || $settings === []) {
				return '';
			}
			$primary = method_exists($core, 'getDevice') ? $core->getDevice($ext) : [];
			$settings['devicetype']['value'] = 'fixed';
			$settings['user']['value'] = $ext;
			$settings['hint_override']['value'] = $settings['hint_override']['value'] ?? null;
			$settings['context']['value'] = !empty($primary['context']) ? $primary['context'] : 'from-internal';
			$settings['callerid']['value'] = $name . ' <' . $ext . '>';
			$settings['force_callerid']['value'] = 'yes';
			$settings['accountcode']['value'] = $primary['accountcode'] ?? '';
			$settings['namedcallgroup']['value'] = $primary['namedcallgroup'] ?? '';
			$settings['namedpickupgroup']['value'] = $primary['namedpickupgroup'] ?? '';
			$settings['mailbox']['value'] = $ext . '@device';
			foreach (self::webrtcExpected() as $k => $v) {
				$settings[$k] = ['value' => $v];
			}
			$settings['timers']['value'] = 'no';
			$core->addDevice($id, 'pjsip', $settings);
			return $id;
		} catch (\Throwable $e) {
			return $existing;
		}
	}

	/**
	 * Dedicated AMI user for enrolled desktops. Not the FreePBX admin manager
	 * user. Allowed from localhost, office LAN, and OpenVPN only.
	 *
	 * @return array{username:string,secret:string,port:int}
	 */
	private function ensureAmiUser() {
		$port = $this->amiPort();
		$name = self::AMI_USER;
		$row = $this->amiUserRow($name);
		$permit = implode('&', $this->amiPermitNets());
		if ($row) {
			return [
				'username' => (string) $row['name'],
				'secret' => (string) $row['secret'],
				'port' => $port,
			];
		}
		$secret = bin2hex(random_bytes(12));
		$created = false;
		try {
			$mgr = $this->FreePBX->Manager;
			if (is_object($mgr) && method_exists($mgr, 'add_manager')) {
				$mgr->add_manager(
					$name,
					$secret,
					'0.0.0.0/0.0.0.0',
					$permit,
					self::AMI_ACL,
					self::AMI_ACL,
					5000
				);
				$created = true;
			}
		} catch (\Throwable $e) {
			$created = false;
		}
		if (!$created) {
			try {
				$st = $this->FreePBX->Database->prepare(
					'INSERT INTO manager (`name`, `secret`, `deny`, `permit`, `read`, `write`, `writetimeout`) VALUES (?,?,?,?,?,?,?)'
				);
				$st->execute([$name, $secret, '0.0.0.0/0.0.0.0', $permit, self::AMI_ACL, self::AMI_ACL, 5000]);
				$created = true;
			} catch (\Throwable $e) {
				$row = $this->amiUserRow($name);
				if ($row) {
					return [
						'username' => (string) $row['name'],
						'secret' => (string) $row['secret'],
						'port' => $port,
					];
				}
				return ['username' => $name, 'secret' => '', 'port' => $port];
			}
		}
		if ($created) {
			try {
				needreload();
			} catch (\Throwable $e) {
				// Apply Config in the GUI
			}
		}
		return ['username' => $name, 'secret' => $secret, 'port' => $port];
	}

	private function amiUserRow($name) {
		try {
			$st = $this->FreePBX->Database->prepare('SELECT name, secret, permit FROM manager WHERE name = ? LIMIT 1');
			$st->execute([$name]);
			$row = $st->fetch(\PDO::FETCH_ASSOC);
			return is_array($row) ? $row : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function amiPort() {
		$conf = '/etc/asterisk/manager.conf';
		if (is_readable($conf)) {
			$txt = (string) @file_get_contents($conf);
			if (preg_match('/^\s*port\s*=\s*(\d+)/mi', $txt, $m)) {
				return (int) $m[1];
			}
		}
		return 5038;
	}

	private function amiOfficeHost() {
		foreach ($this->lanIpv4s() as $row) {
			if (strpos($row['name'], 'tun') === 0 || strpos($row['name'], 'tap') === 0) {
				continue;
			}
			return $row['ip'];
		}
		$host = $this->publicHost();
		if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
			return $host;
		}
		return '127.0.0.1';
	}

	private function amiPermitNets() {
		$nets = ['127.0.0.1/255.255.255.0'];
		$seen = ['127.0.0.1/255.255.255.0' => true];
		foreach ($this->lanIpv4s() as $row) {
			$permit = $row['network'] . '/' . $row['mask'];
			if (!isset($seen[$permit])) {
				$nets[] = $permit;
				$seen[$permit] = true;
			}
		}
		$ovpn = $this->amiLanHost();
		if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ovpn, $m)) {
			$permit = $m[1] . '.0/255.255.255.0';
			if (!isset($seen[$permit])) {
				$nets[] = $permit;
			}
		}
		return $nets;
	}

	/**
	 * @return list<array{name:string,ip:string,network:string,mask:string}>
	 */
	private function lanIpv4s() {
		$out = [];
		if (!function_exists('net_get_interfaces')) {
			return $out;
		}
		$ifs = @net_get_interfaces();
		if (!is_array($ifs)) {
			return $out;
		}
		foreach ($ifs as $name => $info) {
			$name = (string) $name;
			if ($name === 'lo' || strpos($name, 'docker') === 0 || strpos($name, 'br-') === 0 || strpos($name, 'veth') === 0) {
				continue;
			}
			foreach ((array) ($info['unicast'] ?? []) as $addr) {
				$ip = (string) ($addr['address'] ?? '');
				if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					continue;
				}
				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
					continue;
				}
				$mask = (string) ($addr['netmask'] ?? '255.255.255.0');
				if (!filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$mask = '255.255.255.0';
				}
				$network = long2ip(ip2long($ip) & ip2long($mask));
				$out[] = ['name' => $name, 'ip' => $ip, 'network' => $network, 'mask' => $mask];
			}
		}
		return $out;
	}

	/**
	 * Directory email/name for a softphone device (97/98/99 + user ext).
	 * Auth: PJSIP/SIP secret for that device. Used for Gravatar without OAuth.
	 *
	 * @return array{ok:bool,http?:int,error?:string,email?:string,name?:string,user_extension?:string}
	 */
	public function userProfile($extension, $secret) {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$secret = (string) $secret;
		if ($ext === '' || $secret === '') {
			return ['ok' => false, 'http' => 400, 'error' => 'missing_credentials'];
		}
		$stored = (string) ($this->pjsipKeywords($ext)['secret'] ?? '');
		if ($stored === '' || !hash_equals($stored, $secret)) {
			return ['ok' => false, 'http' => 401, 'error' => 'auth_failed'];
		}
		$found = $this->lookupUserDirectoryProfile($ext);
		return [
			'ok' => true,
			'extension' => $ext,
			'user_extension' => $found['user_extension'] ?? $ext,
			'email' => $found['email'] ?? '',
			'name' => $found['name'] ?? '',
		];
	}

	/**
	 * @return array{email:string,name:string,user_extension:string}
	 */
	private function lookupUserDirectoryProfile($deviceExt) {
		$ids = [$deviceExt];
		if (preg_match('/^(97|98|99)(\d{3,6})$/', $deviceExt, $m)) {
			$ids[] = $m[2];
		}
		$ids = array_values(array_unique($ids));
		$out = ['email' => '', 'name' => '', 'user_extension' => $ids[count($ids) - 1]];

		try {
			$um = $this->FreePBX->Userman;
			if (is_object($um)) {
				foreach ($ids as $id) {
					$user = null;
					if (method_exists($um, 'getUserByDefaultExtension')) {
						$user = $um->getUserByDefaultExtension($id);
					}
					if ((!is_array($user) || empty($user['email'])) && method_exists($um, 'getUserByUsername')) {
						$user = $um->getUserByUsername($id);
					}
					if (is_array($user)) {
						$email = trim((string) ($user['email'] ?? ''));
						$fname = trim((string) ($user['fname'] ?? ''));
						$lname = trim((string) ($user['lname'] ?? ''));
						$disp = trim($fname . ' ' . $lname);
						if ($disp === '') {
							$disp = trim((string) ($user['displayname'] ?? $user['username'] ?? ''));
						}
						if ($email !== '' && strpos($email, '@') !== false) {
							$out['email'] = $email;
							$out['name'] = $disp;
							$out['user_extension'] = (string) ($user['default_extension'] ?? $id);
							return $out;
						}
						if ($disp !== '' && $out['name'] === '') {
							$out['name'] = $disp;
						}
					}
				}
				if (method_exists($um, 'getAllUsers') && $out['email'] === '') {
					foreach ((array) $um->getAllUsers() as $user) {
						if (!is_array($user)) {
							continue;
						}
						$def = (string) ($user['default_extension'] ?? '');
						$userName = (string) ($user['username'] ?? '');
						if (!in_array($def, $ids, true) && !in_array($userName, $ids, true)) {
							continue;
						}
						$email = trim((string) ($user['email'] ?? ''));
						if ($email !== '' && strpos($email, '@') !== false) {
							$out['email'] = $email;
							$fname = trim((string) ($user['fname'] ?? ''));
							$lname = trim((string) ($user['lname'] ?? ''));
							$out['name'] = trim($fname . ' ' . $lname);
							$out['user_extension'] = $def !== '' ? $def : $userName;
							return $out;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// SQL fallback
		}

		try {
			$db = $this->FreePBX->Database;
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			try {
				$st = $db->prepare(
					"SELECT email, fname, lname, username, default_extension FROM userman_users
					 WHERE default_extension IN ($placeholders) OR username IN ($placeholders)"
				);
				$st->execute(array_merge($ids, $ids));
				foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$email = trim((string) ($row['email'] ?? ''));
					if ($email !== '' && strpos($email, '@') !== false) {
						$out['email'] = $email;
						$out['name'] = trim((string) ($row['fname'] ?? '') . ' ' . (string) ($row['lname'] ?? ''));
						$out['user_extension'] = (string) ($row['default_extension'] ?? $row['username'] ?? $out['user_extension']);
						return $out;
					}
				}
			} catch (\Throwable $e) {
				// table name may differ
			}
			try {
				$st = $db->prepare("SELECT mailbox, email, fullname FROM voicemail WHERE mailbox IN ($placeholders)");
				$st->execute($ids);
				foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$email = trim((string) ($row['email'] ?? ''));
					if ($email !== '' && strpos($email, '@') !== false) {
						$out['email'] = $email;
						$name = trim((string) ($row['fullname'] ?? ''));
						if ($name !== '') {
							$out['name'] = $name;
						}
						$out['user_extension'] = (string) ($row['mailbox'] ?? $out['user_extension']);
						return $out;
					}
				}
			} catch (\Throwable $e) {
				// voicemail table missing
			}
		} catch (\Throwable $e) {
			// ignore
		}
		return $out;
	}

	private function newUuid() {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
