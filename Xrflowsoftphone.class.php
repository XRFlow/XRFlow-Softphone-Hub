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

require_once __DIR__ . '/XrflowCorporateLogo.php';
require_once __DIR__ . '/XrflowFleet.php';
require_once __DIR__ . '/XrflowAvatarImage.php';

/**
 * Free GPLv3+ companion to the commercial XRFlow Softphone desktop client.
 *
 * Does not rewrite System Admin OpenVPN remotes, Easy-RSA, or sysadmin_server1.conf.
 * Does not mint desktop seat keys. Presence-sync write keys stay on this PBX.
 * This module is not sold and does not require a Hub license key.
 */
class Xrflowsoftphone extends FreePBX_Helpers implements BMO {
	use XrflowCorporateLogo;
	use XrflowFleet;

	public const MODULE_RAWNAME = 'xrflowsoftphone';
	public const LICENSE = 'GPLv3+';
	/** Companion PJSIP device id = prefix + user ext. 99=UCP, 98=Sangoma Connect. */
	public const SOFTPHONE_PREFIX = '97';
	/**
	 * Simultaneous SIP contacts on the companion device (office + home + spares).
	 * FreePBX defaults max_contacts to 1, which drops the other registration.
	 */
	public const SOFTPHONE_MAX_CONTACTS = '5';
	public const AMI_USER = 'xrflow-hub';
	public const AMI_ACL = 'system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate';
	public const OAUTH_APP_NAME = 'XRFlow Softphone Hub';
	/** Space-separated. FreePBX splits allowed_scopes on spaces; a comma makes one invalid scope. */
	public const OAUTH_SCOPES = 'gql rest';

	public function __construct($freepbx = null) {
		$this->FreePBX = $freepbx ?: \FreePBX::create();
	}

	public function install() {
		if (!$this->getConfig('deployment_uuid')) {
			$this->setConfig('deployment_uuid', $this->newUuid());
		}
		$this->installTables();
		$this->ensureAmiUser();
		$this->ensureOauthClient();
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
		if (!empty($_POST['xrflow_profile'])) {
			$_SESSION['xrflow_hub_flash'] = $this->createProfileFromRequest((string) $_POST['xrflow_profile']);
			return;
		}
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
		if ($action === 'save_logo') {
			$result = $this->saveCorporateLogoUpload($_FILES['logo'] ?? [], (string) ($_POST['company_name'] ?? ''));
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => !empty($result['ok']),
				'message' => (string) ($result['message'] ?? 'Could not save the logo.'),
			];
			return;
		}
		if ($action === 'clear_logo') {
			$this->clearCorporateLogo();
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => true,
				'message' => 'Corporate logo removed. Softphones will show the XRFlow mark again.',
			];
			return;
		}
		if ($action === 'save_org') {
			$result = $this->saveOrgSettings((string) ($_POST['org_api_base'] ?? ''), (string) ($_POST['org_token'] ?? ''));
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => !empty($result['ok']),
				'message' => (string) ($result['message'] ?? 'Could not save seat pool settings.'),
			];
			return;
		}
		if ($action === 'clear_org') {
			$_SESSION['xrflow_hub_flash'] = $this->clearOrgToken();
			return;
		}
		if ($action === 'org_refresh') {
			$result = $this->syncOrgGrants(true);
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => !empty($result['ok']),
				'message' => !empty($result['ok'])
					? 'Seats synced.'
					: (string) ($result['message'] ?? 'Could not sync seats.'),
			];
			return;
		}
		if ($action === 'org_assign') {
			$result = $this->assignOrgGrant($_POST['grant_id'] ?? 0, (string) ($_POST['assign_extension'] ?? ''));
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => !empty($result['ok']),
				'message' => (string) ($result['message'] ?? 'Could not assign that seat.'),
			];
			return;
		}
		if ($action === 'org_deactivate' || $action === 'org_reactivate') {
			$which = $action === 'org_deactivate' ? 'deactivate' : 'reactivate';
			$result = $this->mutateOrgGrant($which, $_POST['grant_id'] ?? 0);
			$_SESSION['xrflow_hub_flash'] = [
				'ok' => !empty($result['ok']),
				'message' => (string) ($result['message'] ?? 'Could not update that seat.'),
			];
			return;
		}
		if ($action === 'org_reveal') {
			$result = $this->revealOrgGrant($_POST['grant_id'] ?? 0);
			$flash = [
				'ok' => !empty($result['ok']),
				'message' => (string) ($result['message'] ?? 'Could not reveal that key.'),
			];
			if (!empty($result['license_key'])) {
				$flash['license_key'] = (string) $result['license_key'];
			}
			$_SESSION['xrflow_hub_flash'] = $flash;
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
		$allowed = ['dashboard', 'about', 'compliance', 'enroll', 'branding', 'fleet', 'seats'];
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
			'logoMeta' => $view === 'branding' ? $this->corporateLogoMeta() : [],
			'logoPreview' => $view === 'branding' ? $this->corporateLogoDataUri() : '',
			'logoRequirements' => $view === 'branding' ? $this->corporateLogoRequirements() : [],
			'fleetRows' => ($view === 'fleet' || $view === 'dashboard') ? $this->fleetRows() : [],
			'fleetSummary' => $view === 'dashboard' ? $this->fleetSummary() : [],
			'seatPool' => $view === 'seats' ? $this->seatPoolView(false) : [],
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
		$oauth = $this->ensureOauthClient();
		$oauthId = (string) ($oauth['client_id'] ?? '');
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
			'oauth_ready' => ($oauth['client_secret'] ?? '') !== '' && $oauthId !== '',
			'oauth_client_id' => $oauthId,
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
			'max_contacts' => self::SOFTPHONE_MAX_CONTACTS,
			// A new contact past the cap replaces the oldest. Two live desks stay registered.
			'remove_existing' => 'yes',
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
			'max_contacts' => 'This softphone device allows only one registration. Office and home each need a slot.',
			'remove_existing' => 'A full registration list would reject another softphone instead of dropping the oldest contact.',
		];
	}

	/**
	 * WebRTC row button value: "{extension}|office" or "{extension}|home".
	 *
	 * @return array{extension:string,kind:string,needVpn:bool}|null
	 */
	public static function parseProfileRequest($raw) {
		$parts = explode('|', (string) $raw, 2);
		if (count($parts) !== 2) {
			return null;
		}
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', $parts[0]);
		$kind = $parts[1];
		if ($ext === '' || ($kind !== 'office' && $kind !== 'home')) {
			return null;
		}
		return [
			'extension' => $ext,
			'kind' => $kind,
			'needVpn' => $kind === 'home',
		];
	}

	/**
	 * Office (no VPN) or home (VPN) enroll code for one extension.
	 * Sets up the companion device first, then issues a one-time code.
	 * A second profile does not remove the first registration.
	 *
	 * @return array<string, mixed>
	 */
	public function createProfileFromRequest($raw) {
		$parsed = self::parseProfileRequest($raw);
		if ($parsed === null) {
			return ['ok' => false, 'error' => 'Pick an office or home profile for one extension.'];
		}
		$ext = $parsed['extension'];
		$needVpn = $parsed['needVpn'];
		$this->setExtensionVpn($ext, $needVpn);
		$repair = $this->applyWebrtcTemplate([$ext], false);
		if (empty($repair['ok'])) {
			return [
				'ok' => false,
				'error' => $repair['message'] ?? 'Could not set up the softphone device.',
				'extension' => $ext,
				'profile' => $parsed['kind'],
			];
		}
		$flash = $this->generateEnrollToken($ext, false, $needVpn);
		if (!empty($flash['ok'])) {
			$flash['repaired'] = true;
			$flash['profile'] = $parsed['kind'];
			$label = $needVpn ? 'Home profile (VPN)' : 'Office profile (no VPN)';
			$flash['message'] = $label . ' for extension ' . $ext . '. ' . ($flash['message'] ?? '');
		}
		return $flash;
	}

	/**
	 * VPN follows this enroll code. A later code for the same extension must not
	 * change a code that was already issued for the office (or for home).
	 * Codes stored before the need_openvpn column fall back to the extension map.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function enrollCodeNeedsVpn(array $row, $extensionFlag = false) {
		if (array_key_exists('need_openvpn', $row)) {
			return !empty($row['need_openvpn']);
		}
		return !empty($extensionFlag);
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
		if ($needVpn && $this->systemAdminOpenVpnBundle($ext) === null) {
			return [
				'ok' => false,
				'error' => self::missingOpenVpnProfileMessage($ext),
				'extension' => $ext,
			];
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
			'message' => 'One-time enroll code (15 minutes, one use). Desk phone on this extension was not changed. Another code for this extension can stay registered at the same time (office without VPN, home with VPN).',
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
		$needVpn = self::enrollCodeNeedsVpn($row, $this->extensionNeedsVpn((string) $row['extension']));
		if ($needVpn && $this->systemAdminOpenVpnBundle((string) $row['extension']) === null) {
			return ['ok' => false, 'http' => 409, 'error' => 'openvpn_profile_missing'];
		}
		$upd = $db->prepare('UPDATE xrflowsoftphone_enroll SET redeemed_at = ? WHERE id = ? AND redeemed_at IS NULL');
		$upd->execute([time(), $row['id']]);
		if ($upd->rowCount() < 1) {
			return ['ok' => false, 'http' => 410, 'error' => 'token_used'];
		}
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
		$profile = null;
		if ($needVpn) {
			$profile = $this->systemAdminOpenVpnBundle($ext);
			$vpnHint = $profile !== null
				? 'OpenVPN client from System Admin is included. Remotes are unchanged.'
				: 'Use the System Admin .ovpn already issued; remotes unmodified.';
		} else {
			$vpnHint = '';
		}
		$ami = $this->ensureAmiUser();
		$amiHost = $needVpn ? $amiLan : $this->amiOfficeHost();
		$oauth = $this->ensureOauthClient();
		$payload = [
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
			'oauthClientId' => (string) ($oauth['client_id'] ?? ''),
			'oauthClientSecret' => (string) ($oauth['client_secret'] ?? ''),
			'openVpnHint' => $vpnHint,
			'policy' => ['minVersion' => '0.2.25', 'lockSettings' => true],
		];
		if ($profile !== null) {
			$payload['openVpnProfile'] = $profile;
		}
		return $payload;
	}

	/**
	 * Read the System Admin client profile already on disk. Does not write
	 * server config, Easy-RSA, or sysadmin_server1.conf. Remotes stay as exported.
	 *
	 * @return array{filename:string,config:string,files:list<array{name:string,content:string}>}|null
	 */
	public function systemAdminOpenVpnBundle($ext) {
		$dirs = self::systemAdminOpenVpnDirs();
		$diskIds = self::sysadminClientIdsInDirs($dirs);
		$clients = $this->readSysadminVpnClients();
		$preferred = $this->usermanVpnClientIds($ext);
		$id = self::selectSysadminClientId($ext, $clients, $diskIds, $preferred);
		if ($id === '') {
			return null;
		}
		return self::bundleFromClientDirs($id, $dirs);
	}

	public static function missingOpenVpnProfileMessage($ext) {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
		return 'Home enroll needs the System Admin OpenVPN client for extension ' . $ext
			. '. In User Management, enable VPN for that user, or create the client in System Admin → VPN and set its description to start with the extension. Remotes are not changed. Then create the home profile again.';
	}

	/** @return list<string> */
	public static function systemAdminOpenVpnDirs() {
		return ['/etc/openvpn/clients', '/etc/openvpn'];
	}

	/**
	 * @param list<string> $dirs
	 * @return list<string>
	 */
	public static function sysadminClientIdsInDirs(array $dirs) {
		$ids = [];
		foreach ($dirs as $dir) {
			if (!is_string($dir) || !is_dir($dir)) {
				continue;
			}
			$list = @scandir($dir);
			if (!is_array($list)) {
				continue;
			}
			foreach ($list as $name) {
				if (preg_match('/^sysadmin_client([0-9A-Za-z_-]+)\.(conf|ovpn)$/', (string) $name, $m)) {
					$ids[$m[1]] = true;
				}
			}
		}
		$out = array_map('strval', array_keys($ids));
		sort($out, SORT_STRING);
		return $out;
	}

	/**
	 * Prefer the client assigned to this extension. One client on the PBX is used
	 * when nothing is labeled. Several unlabeled clients are not guessed.
	 *
	 * @param mixed $vpnClients
	 * @param list<string> $diskIds
	 * @param list<string> $preferredIds
	 */
	public static function selectSysadminClientId($extension, $vpnClients, array $diskIds, array $preferredIds = []) {
		$disk = [];
		foreach ($diskIds as $id) {
			$cid = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $id);
			if ($cid !== '') {
				$disk[$cid] = true;
			}
		}
		$pref = [];
		foreach ($preferredIds as $id) {
			$cid = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $id);
			if ($cid !== '' && isset($disk[$cid])) {
				$pref[$cid] = true;
			}
		}
		$prefIds = array_keys($pref);
		if (count($prefIds) === 1) {
			return (string) $prefIds[0];
		}
		$matched = [];
		if (is_array($vpnClients)) {
			foreach ($vpnClients as $id => $row) {
				$cid = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $id);
				if ($cid === '' || !isset($disk[$cid]) || self::clientRowDisabled($row)) {
					continue;
				}
				$hit = false;
				if (is_array($row)) {
					$hit = self::clientRowMatchesExtension($row, (string) $extension);
				} elseif (is_string($row) || is_int($row)) {
					$hit = self::labelMatchesExtension((string) $row, (string) $extension);
				}
				if ($hit) {
					$matched[$cid] = true;
				}
			}
		}
		$matchedIds = array_keys($matched);
		if (count($prefIds) > 1) {
			$both = array_values(array_intersect($prefIds, $matchedIds));
			return count($both) === 1 ? (string) $both[0] : '';
		}
		if (count($matchedIds) === 1) {
			return (string) $matchedIds[0];
		}
		if (count($matchedIds) > 1) {
			return '';
		}
		$diskIds = array_keys($disk);
		if (count($diskIds) === 1) {
			$only = $diskIds[0];
			$row = is_array($vpnClients) ? ($vpnClients[$only] ?? null) : null;
			if (is_array($row) && self::clientRowNamesOtherExtension($row, (string) $extension)) {
				return '';
			}
			return (string) $only;
		}
		return '';
	}

	/** A client labeled for a different extension is not the fallback profile. */
	public static function clientRowNamesOtherExtension($row, $ext) {
		if (!is_array($row)) {
			return false;
		}
		foreach (['description', 'name', 'username', 'user', 'extension'] as $key) {
			if (!isset($row[$key]) || (!is_string($row[$key]) && !is_int($row[$key]))) {
				continue;
			}
			$label = trim((string) $row[$key]);
			if ($label === '' || self::labelMatchesExtension($label, (string) $ext)) {
				continue;
			}
			if (preg_match('/^(\d+)/', $label, $m) && $m[1] !== (string) $ext) {
				return true;
			}
		}
		return false;
	}

	/** @param mixed $row */
	public static function clientRowDisabled($row) {
		if (!is_array($row) || !array_key_exists('enabled', $row)) {
			return false;
		}
		$v = $row['enabled'];
		if ($v === true || $v === 1 || $v === '1') {
			return false;
		}
		return $v === false || $v === 0 || $v === '0' || $v === 'no' || $v === 'false' || $v === '';
	}

	/** @param array<mixed> $row */
	public static function clientRowMatchesExtension(array $row, $ext) {
		foreach ($row as $value) {
			if ((is_string($value) || is_int($value)) && self::labelMatchesExtension((string) $value, (string) $ext)) {
				return true;
			}
		}
		return false;
	}

	public static function labelMatchesExtension($label, $ext) {
		$ext = (string) $ext;
		$label = (string) $label;
		if ($ext === '' || $label === '') {
			return false;
		}
		return (bool) preg_match('/(^|[^0-9A-Za-z])' . preg_quote($ext, '/') . '([^0-9A-Za-z]|$)/', $label);
	}

	/**
	 * A User Management value is a client id only when the setting is about the
	 * client (not vpn_enabled=yes) or it names sysadmin_clientN.
	 *
	 * @return list<string>
	 */
	public static function clientIdsFromUserSetting($key, $val) {
		$key = strtolower((string) $key);
		$val = trim((string) $val);
		if ($key === '' || $val === '' || !preg_match('/vpn/', $key)) {
			return [];
		}
		$out = [];
		if (preg_match_all('/sysadmin_client([0-9A-Za-z_-]+)/', $val, $m)) {
			foreach ($m[1] as $id) {
				$out[] = $id;
			}
		}
		if (!preg_match('/client/', $key)) {
			return array_values(array_unique($out));
		}
		if (preg_match('/^[0-9A-Za-z_-]+$/', $val)) {
			$out[] = $val;
		}
		$decoded = json_decode($val, true);
		if (is_array($decoded)) {
			foreach ($decoded as $item) {
				if (is_scalar($item) && preg_match('/^[0-9A-Za-z_-]+$/', (string) $item)) {
					$out[] = (string) $item;
				} elseif (is_array($item) && isset($item['id']) && preg_match('/^[0-9A-Za-z_-]+$/', (string) $item['id'])) {
					$out[] = (string) $item['id'];
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param list<string> $dirs
	 * @return array{filename:string,config:string,files:list<array{name:string,content:string}>}|null
	 */
	public static function bundleFromClientDirs($clientId, array $dirs) {
		$rawId = (string) $clientId;
		$clientId = preg_replace('/[^0-9A-Za-z_-]/', '', $rawId);
		if ($clientId === '' || $clientId !== $rawId) {
			return null;
		}
		$confPath = null;
		$confName = null;
		foreach ($dirs as $dir) {
			if (!is_string($dir)) {
				continue;
			}
			foreach (['sysadmin_client' . $clientId . '.conf', 'sysadmin_client' . $clientId . '.ovpn'] as $name) {
				$path = rtrim($dir, '/') . '/' . $name;
				if (is_file($path) && is_readable($path)) {
					$confPath = $path;
					$confName = $name;
					break 2;
				}
			}
		}
		if ($confPath === null || $confName === null) {
			return null;
		}
		$config = @file_get_contents($confPath);
		if (!is_string($config) || strlen($config) > 262144 || strpos($config, "\0") !== false) {
			return null;
		}
		if (!preg_match('/^\s*remote\s+\S+/m', $config)) {
			return null;
		}
		$files = [];
		$total = strlen($config);
		foreach (self::referencedOvpnFiles($config) as $name) {
			$path = self::findReadableBasename($name, $dirs);
			if ($path === null) {
				$directive = self::ovpnDirectiveForFile($config, $name);
				if ($directive !== '' && self::inlineBlockPresent($config, $directive)) {
					continue;
				}
				return null;
			}
			$body = @file_get_contents($path);
			if (!is_string($body) || strlen($body) > 262144 || strpos($body, "\0") !== false) {
				return null;
			}
			$total += strlen($body);
			if ($total > 1048576 || count($files) >= 8) {
				return null;
			}
			$files[] = ['name' => $name, 'content' => $body];
		}
		return [
			'filename' => $confName,
			'config' => $config,
			'files' => $files,
		];
	}

	/** @return list<string> */
	public static function referencedOvpnFiles($configText) {
		$names = [];
		foreach (preg_split('/\r?\n/', (string) $configText) as $line) {
			$t = trim((string) $line);
			if ($t === '' || $t[0] === '#' || $t[0] === ';') {
				continue;
			}
			if (!preg_match('/^(ca|cert|key|tls-auth|tls-crypt|tls-crypt-v2|dh|extra-certs|pkcs12|crl-verify|secret)\s+(\S+)/i', $t, $m)) {
				continue;
			}
			$file = trim($m[2], "\"'");
			if ($file === '' || $file[0] === '<' || strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
				continue;
			}
			if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $file)) {
				$names[$file] = true;
			}
		}
		return array_keys($names);
	}

	/** @param list<string> $dirs */
	public static function findReadableBasename($name, array $dirs) {
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', (string) $name) || strpos((string) $name, '..') !== false) {
			return null;
		}
		foreach ($dirs as $dir) {
			if (!is_string($dir)) {
				continue;
			}
			$path = rtrim($dir, '/') . '/' . $name;
			if (is_file($path) && is_readable($path)) {
				return $path;
			}
		}
		return null;
	}

	private static function ovpnDirectiveForFile($config, $name) {
		$quoted = preg_quote((string) $name, '/');
		if (preg_match('/^\s*(ca|cert|key|tls-auth|tls-crypt|tls-crypt-v2|dh|extra-certs|pkcs12|crl-verify|secret)\s+["\']?' . $quoted . '\b/mi', (string) $config, $m)) {
			return strtolower($m[1]);
		}
		return '';
	}

	private static function inlineBlockPresent($config, $directive) {
		return (bool) preg_match('/<' . preg_quote((string) $directive, '/') . '>/i', (string) $config);
	}

	/** @return array<mixed> */
	private function readSysadminVpnClients() {
		try {
			$st = $this->FreePBX->Database()->query("SELECT `key`, `value` FROM sysadmin_options WHERE `key` = 'vpnclients' LIMIT 1");
			$row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : false;
			if (!is_array($row) || !isset($row['value'])) {
				return [];
			}
			$decoded = json_decode((string) $row['value'], true);
			return is_array($decoded) ? $decoded : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** @return list<string> */
	private function usermanVpnClientIds($ext) {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $ext);
		if ($ext === '') {
			return [];
		}
		try {
			$st = $this->FreePBX->Database()->prepare(
				'SELECT s.`key` AS setting_key, s.val FROM userman_users u
				 INNER JOIN userman_users_settings s ON s.uid = u.id
				 WHERE (u.default_extension = ? OR u.username = ?)
				 AND s.`key` LIKE ?'
			);
			$st->execute([$ext, $ext, '%vpn%']);
			$ids = [];
			while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
				if (!is_array($row)) {
					continue;
				}
				foreach (self::clientIdsFromUserSetting((string) ($row['setting_key'] ?? ''), (string) ($row['val'] ?? '')) as $id) {
					$ids[$id] = true;
				}
			}
			return array_keys($ids);
		} catch (\Throwable $e) {
			return [];
		}
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
	 * PBX API (Admin → API) client_credentials app for desktop Contacts.
	 * FreePBX stores only a hash of client_secret, so plaintext is kept in Hub config.
	 *
	 * @return array{client_id:string,client_secret:string}
	 */
	private function ensureOauthClient() {
		$empty = ['client_id' => '', 'client_secret' => ''];
		$id = (string) $this->getConfig('oauth_client_id');
		$secret = (string) $this->getConfig('oauth_client_secret');
		if ($id !== '' && $secret !== '' && $this->oauthClientValid($id, $secret)) {
			$this->ensureOauthScopes($id);
			return ['client_id' => $id, 'client_secret' => $secret];
		}
		try {
			$api = $this->FreePBX->Api;
			if (!is_object($api)) {
				return $empty;
			}
			$apps = $api->applications;
			if (!is_object($apps) || !method_exists($apps, 'add')) {
				return $empty;
			}
			$existing = $this->oauthAppByName($apps, self::OAUTH_APP_NAME);
			if ($existing && $id === (string) $existing['client_id'] && $secret !== '' && $this->oauthSecretMatches($existing, $secret)) {
				$this->ensureOauthScopes($id);
				return ['client_id' => $id, 'client_secret' => $secret];
			}
			if ($existing && !empty($existing['client_id'])) {
				$created = $apps->regenerate((int) ($existing['owner'] ?? 0), (string) $existing['client_id']);
			} else {
				$created = $apps->add(
					0,
					'client_credentials',
					self::OAUTH_APP_NAME,
					'Desktop enroll: Contacts directory (REST/GraphQL). Created by XRFlow Softphone Hub.',
					null,
					null,
					self::OAUTH_SCOPES
				);
			}
			$newId = (string) ($created['client_id'] ?? '');
			$newSecret = (string) ($created['client_secret'] ?? '');
			if ($newId === '' || $newSecret === '') {
				return $empty;
			}
			$this->setConfig('oauth_client_id', $newId);
			$this->setConfig('oauth_client_secret', $newSecret);
			$this->ensureOauthScopes($newId);
			return ['client_id' => $newId, 'client_secret' => $newSecret];
		} catch (\Throwable $e) {
			return $empty;
		}
	}

	/**
	 * FreePBX ScopeRepository splits allowed_scopes on spaces. Empty means gql and rest.
	 * "gql,rest" is one identifier, so the token is issued with no scopes and GraphQL
	 * hides fetchAllExtensions.
	 *
	 * @param mixed $raw
	 */
	public static function normalizeOauthAllowedScopes($raw) {
		$current = trim((string) $raw);
		if ($current === '') {
			return '';
		}
		$kept = [];
		foreach (preg_split('/\s+/', $current) ?: [] as $token) {
			foreach (explode(',', (string) $token) as $part) {
				$part = trim($part);
				if ($part !== '' && !in_array($part, $kept, true)) {
					$kept[] = $part;
				}
			}
		}
		foreach (['gql', 'rest'] as $need) {
			if (!in_array($need, $kept, true)) {
				$kept[] = $need;
			}
		}
		return implode(' ', $kept);
	}

	/**
	 * @param mixed $raw
	 */
	public static function oauthScopesNeedUpdate($raw) {
		$current = trim((string) $raw);
		if ($current === '') {
			return false;
		}
		return self::normalizeOauthAllowedScopes($current) !== $current;
	}

	private function ensureOauthScopes($clientId) {
		$clientId = (string) $clientId;
		if ($clientId === '') {
			return;
		}
		try {
			$st = $this->FreePBX->Database->prepare(
				'SELECT allowed_scopes FROM api_applications WHERE client_id = ? LIMIT 1'
			);
			$st->execute([$clientId]);
			$row = $st->fetch(\PDO::FETCH_ASSOC);
			if (!is_array($row) || !self::oauthScopesNeedUpdate($row['allowed_scopes'] ?? '')) {
				return;
			}
			$next = self::normalizeOauthAllowedScopes($row['allowed_scopes'] ?? '');
			$up = $this->FreePBX->Database->prepare(
				'UPDATE api_applications SET allowed_scopes = ? WHERE client_id = ?'
			);
			$up->execute([$next, $clientId]);
		} catch (\Throwable $e) {
			// Directory keeps working once an admin saves gql and rest on the API app.
		}
	}

	private function oauthAppByName($apps, $name) {
		try {
			foreach ((array) $apps->getAll() as $row) {
				if (is_array($row) && (string) ($row['name'] ?? '') === $name) {
					return $row;
				}
			}
		} catch (\Throwable $e) {
			return null;
		}
		return null;
	}

	private function oauthClientValid($clientId, $secret) {
		try {
			$st = $this->FreePBX->Database->prepare('SELECT client_secret, algo FROM api_applications WHERE client_id = ? LIMIT 1');
			$st->execute([$clientId]);
			$row = $st->fetch(\PDO::FETCH_ASSOC);
			return is_array($row) && $this->oauthSecretMatches($row, $secret);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function oauthSecretMatches(array $row, $plain) {
		$hash = (string) ($row['client_secret'] ?? '');
		$algo = (string) ($row['algo'] ?? 'sha256');
		if ($hash === '' || $plain === '') {
			return false;
		}
		if (!in_array($algo, ['sha256', 'sha1', 'md5'], true)) {
			$algo = 'sha256';
		}
		return hash($algo, $plain) === $hash;
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
			$this->syncAmiUserPermit($name, $permit, (string) ($row['permit'] ?? ''));
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

	private function syncAmiUserPermit($name, $permit, $current = '') {
		if ($permit === '' || $permit === $current) {
			return;
		}
		try {
			$st = $this->FreePBX->Database->prepare('UPDATE manager SET permit = ? WHERE name = ?');
			$st->execute([$permit, $name]);
			needreload();
		} catch (\Throwable $e) {
			// Keep the existing secret; Apply Config / manager reload picks up permit.
		}
	}

	private function amiPermitNets() {
		$nets = ['127.0.0.1/255.255.255.0'];
		$seen = ['127.0.0.1/255.255.255.0' => true];
		$add = function ($permit) use (&$nets, &$seen) {
			if ($permit === '' || isset($seen[$permit])) {
				return;
			}
			$nets[] = $permit;
			$seen[$permit] = true;
		};
		foreach ($this->lanIpv4s() as $row) {
			$add($row['network'] . '/' . $row['mask']);
		}
		$ovpn = $this->amiLanHost();
		if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ovpn, $m)) {
			$add($m[1] . '.0/255.255.255.0');
		}
		foreach ($this->pjsipLocalNets() as $permit) {
			$add($permit);
		}
		return $nets;
	}

	/**
	 * Office IPsec / other local_net CIDRs from PJSIP (Asterisk manager uses netmask form).
	 *
	 * @return list<string>
	 */
	private function pjsipLocalNets() {
		$out = [];
		foreach (['/etc/asterisk/pjsip.transports.conf', '/etc/asterisk/pjsip.transports_custom.conf'] as $f) {
			if (!is_readable($f)) {
				continue;
			}
			$txt = (string) @file_get_contents($f);
			if (!preg_match_all('/^\s*local_net\s*=\s*(\S+)/mi', $txt, $m)) {
				continue;
			}
			foreach ($m[1] as $cidr) {
				$permit = $this->cidrToAmiPermit($cidr);
				if ($permit !== '') {
					$out[] = $permit;
				}
			}
		}
		return $out;
	}

	private function cidrToAmiPermit($cidr) {
		$cidr = trim((string) $cidr);
		if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d+\.\d+\.\d+\.\d+)$/', $cidr, $m)) {
			return $m[1] . '/' . $m[2];
		}
		if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d{1,2})$/', $cidr, $m)) {
			return '';
		}
		$prefix = (int) $m[2];
		if ($prefix < 0 || $prefix > 32) {
			return '';
		}
		$maskLong = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
		return $m[1] . '/' . long2ip($maskLong);
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
	 * User Manager photos and emails for the directory.
	 * Auth is the same PJSIP secret as user-profile. Pictures are the blobs in
	 * contactmanager_entry_userman_images (uid = userman user id), resized to a
	 * PNG data URL. Assigned devices (981016 on user 1016) are included.
	 * Missing table or GD: that picture is skipped; emails are still returned.
	 *
	 * @return array{ok:bool,http?:int,error?:string,users?:list<array<string,mixed>>}
	 */
	public function directoryAvatars($extension, $secret) {
		$auth = $this->userProfile($extension, $secret);
		if (empty($auth['ok'])) {
			return $auth;
		}
		try {
			$db = $this->FreePBX->Database;
		} catch (\Throwable $e) {
			return ['ok' => true, 'users' => []];
		}
		try {
			$st = $db->prepare(
				'SELECT id, username, default_extension, fname, lname, displayname, email FROM userman_users'
			);
			$st->execute();
			$userRows = $st->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return ['ok' => true, 'users' => []];
		}
		if (!is_array($userRows)) {
			return ['ok' => true, 'users' => []];
		}

		$images = [];
		try {
			$st = $db->prepare('SELECT uid, image, format FROM contactmanager_entry_userman_images');
			$st->execute();
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				if (!is_array($row)) {
					continue;
				}
				$images[(string) ($row['uid'] ?? '')] = $row;
			}
		} catch (\Throwable $e) {
			// Contact Manager image table is optional. Emails still help Gravatar.
		}

		$assigned = [];
		try {
			$st = $db->prepare(
				"SELECT uid, val FROM userman_users_settings WHERE module = 'global' AND `key` = 'assigned'"
			);
			$st->execute();
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				if (!is_array($row)) {
					continue;
				}
				$assigned[(string) ($row['uid'] ?? '')] = $row['val'] ?? '';
			}
		} catch (\Throwable $e) {
			// Assigned devices are optional. default_extension still matches.
		}

		$users = [];
		foreach ($userRows as $user) {
			if (!is_array($user)) {
				continue;
			}
			$uid = (string) ($user['id'] ?? '');
			$dataUrl = null;
			if (isset($images[$uid]) && is_array($images[$uid])) {
				$dataUrl = XrflowAvatarImage::dataUrl(
					$images[$uid]['image'] ?? '',
					(string) ($images[$uid]['format'] ?? '')
				);
			}
			$payload = XrflowAvatarImage::userPayload($user, $dataUrl, $assigned[$uid] ?? null);
			if ($payload !== null) {
				$users[] = $payload;
			}
		}
		return ['ok' => true, 'users' => $users];
	}

	/**
	 * Directory email/name for a softphone device (97/98/99 + user ext).
	 * Auth: PJSIP/SIP secret for that device. Used for Gravatar without OAuth.
	 *
	 * @return array{ok:bool,http?:int,error?:string,email?:string,name?:string,user_extension?:string}
	 */
	public function userProfile($extension, $secret, $hintName = '') {
		$ext = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$secret = (string) $secret;
		$hintName = trim((string) $hintName);
		if ($ext === '' || $secret === '') {
			return ['ok' => false, 'http' => 400, 'error' => 'missing_credentials'];
		}
		$stored = (string) ($this->pjsipKeywords($ext)['secret'] ?? '');
		if ($stored === '' || !hash_equals($stored, $secret)) {
			return ['ok' => false, 'http' => 401, 'error' => 'auth_failed'];
		}
		$found = $this->lookupUserDirectoryProfile($ext, $hintName);
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
	private function lookupUserDirectoryProfile($deviceExt, $hintName = '') {
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
					$hint = strtolower(trim($hintName));
					foreach ((array) $um->getAllUsers() as $user) {
						if (!is_array($user)) {
							continue;
						}
						$def = (string) ($user['default_extension'] ?? '');
						$userName = (string) ($user['username'] ?? '');
						$fname = trim((string) ($user['fname'] ?? ''));
						$lname = trim((string) ($user['lname'] ?? ''));
						$disp = strtolower(trim($fname . ' ' . $lname));
						$assigned = (array) ($user['assigned'] ?? $user['extensions'] ?? []);
						$assignedStr = array_map('strval', $assigned);
						$matchExt = in_array($def, $ids, true) || in_array($userName, $ids, true)
							|| count(array_intersect($assignedStr, $ids)) > 0;
						$matchName = $hint !== '' && $disp !== '' && ($disp === $hint || strpos($disp, $hint) !== false || strpos($hint, $disp) !== false);
						if (!$matchExt && !$matchName) {
							continue;
						}
						$email = trim((string) ($user['email'] ?? ''));
						if ($email !== '' && strpos($email, '@') !== false) {
							$out['email'] = $email;
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
				if ($hintName !== '' && $out['email'] === '') {
					$st = $db->prepare(
						"SELECT email, fname, lname, username, default_extension FROM userman_users
						 WHERE LOWER(TRIM(CONCAT(IFNULL(fname,''), ' ', IFNULL(lname,'')))) = LOWER(?)"
					);
					$st->execute([$hintName]);
					foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
						$email = trim((string) ($row['email'] ?? ''));
						if ($email !== '' && strpos($email, '@') !== false) {
							$out['email'] = $email;
							$out['name'] = trim((string) ($row['fname'] ?? '') . ' ' . (string) ($row['lname'] ?? ''));
							$out['user_extension'] = (string) ($row['default_extension'] ?? $row['username'] ?? $out['user_extension']);
							return $out;
						}
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

	/**
	 * Companion device 981016 and the desk extension 1016 are the same person.
	 * SMS DIDs are stored on the User Manager user, not on the softphone device id.
	 *
	 * @return list<string>
	 */
	public static function smsIdentityIds($deviceExt, $userExtension = '') {
		$ids = [];
		$deviceExt = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $deviceExt);
		$userExtension = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $userExtension);
		if ($deviceExt !== '') {
			$ids[] = $deviceExt;
		}
		if (preg_match('/^(97|98|99)(\d{2,8})$/', $deviceExt, $m)) {
			$ids[] = $m[2];
		}
		if ($userExtension !== '') {
			$ids[] = $userExtension;
		}
		return array_values(array_unique($ids));
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	public static function assignedDeviceIds($raw) {
		$list = XrflowAvatarImage::assignedExtensions($raw);
		if ($list !== []) {
			return $list;
		}
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}
		$decoded = @unserialize($raw, ['allowed_classes' => false]);
		if (!is_array($decoded)) {
			return [];
		}
		return XrflowAvatarImage::assignedExtensions($decoded);
	}

	/**
	 * DIDs in sms_routing for the desk user behind this softphone device.
	 *
	 * @return array{ok:bool,http?:int,error?:string,user_extension?:string,dids?:list<array{did:string,adaptor?:string}>}
	 */
	public function smsDids($extension, $secret) {
		$ctx = $this->smsContext($extension, $secret);
		if (empty($ctx['ok'])) {
			return $ctx;
		}
		return [
			'ok' => true,
			'user_extension' => $ctx['user_extension'],
			'dids' => $ctx['dids'],
		];
	}

	/**
	 * @return array{ok:bool,http?:int,error?:string,threads?:list<array<string,mixed>>}
	 */
	public function smsThreads($extension, $secret) {
		$ctx = $this->smsContext($extension, $secret);
		if (empty($ctx['ok'])) {
			return $ctx;
		}
		$rows = $this->smsMessageRows($ctx['routing_dids']);
		$mine = $ctx['did_digits'];
		$threads = [];
		foreach ($rows as $row) {
			$from = preg_replace('/\D/', '', (string) ($row['from'] ?? ''));
			$to = preg_replace('/\D/', '', (string) ($row['to'] ?? ''));
			$outbound = $this->smsDidMatches($from, $mine);
			$remote = $outbound ? $to : $from;
			if ($remote === '' || $this->smsDidMatches($remote, $mine)) {
				continue;
			}
			if (!isset($threads[$remote])) {
				$threads[$remote] = [
					'id' => (string) ($row['threadid'] ?? $remote),
					'with' => $remote,
					'direction' => $outbound ? 'out' : 'in',
					'body' => (string) ($row['body'] ?? ''),
					'timestamp' => (int) ($row['timestamp'] ?? 0),
					'unread' => 0,
				];
			}
			if (!$outbound && (int) ($row['read'] ?? 1) === 0) {
				$threads[$remote]['unread']++;
			}
		}
		return ['ok' => true, 'threads' => array_values($threads)];
	}

	/**
	 * @return array{ok:bool,http?:int,error?:string,messages?:list<array<string,mixed>>}
	 */
	public function smsMessages($extension, $secret, $with) {
		$ctx = $this->smsContext($extension, $secret);
		if (empty($ctx['ok'])) {
			return $ctx;
		}
		$peer = preg_replace('/\D/', '', (string) $with);
		$mine = $ctx['did_digits'];
		$messages = [];
		foreach ($this->smsMessageRows($ctx['routing_dids']) as $row) {
			$from = preg_replace('/\D/', '', (string) ($row['from'] ?? ''));
			$to = preg_replace('/\D/', '', (string) ($row['to'] ?? ''));
			$outbound = $this->smsDidMatches($from, $mine);
			$remote = $outbound ? $to : $from;
			if ($peer === '' || !$this->smsDidMatches($remote, [$peer])) {
				continue;
			}
			$messages[] = [
				'id' => (string) ($row['id'] ?? ''),
				'body' => (string) ($row['body'] ?? ''),
				'direction' => $outbound ? 'out' : 'in',
				'timestamp' => (int) ($row['timestamp'] ?? 0),
			];
		}
		usort($messages, static function ($a, $b) {
			return ((int) $a['timestamp']) <=> ((int) $b['timestamp']);
		});
		return ['ok' => true, 'messages' => $messages];
	}

	/**
	 * @return array{ok:bool,http?:int,error?:string,id?:mixed,from?:string,to?:string}
	 */
	public function smsSend($extension, $secret, $from, $to, $message) {
		$ctx = $this->smsContext($extension, $secret);
		if (empty($ctx['ok'])) {
			return $ctx;
		}
		$fromDigits = preg_replace('/\D/', '', (string) $from);
		$toDigits = preg_replace('/\D/', '', (string) $to);
		$message = trim((string) $message);
		if ($fromDigits === '' || $toDigits === '' || $message === '') {
			return ['ok' => false, 'http' => 400, 'error' => 'Enter a phone number and message'];
		}
		$routingDid = '';
		foreach ($ctx['routing_dids'] as $did) {
			$digits = preg_replace('/\D/', '', (string) $did);
			if ($this->smsDidMatches($digits, [$fromDigits])) {
				$routingDid = (string) $did;
				break;
			}
		}
		if ($routingDid === '') {
			return ['ok' => false, 'http' => 403, 'error' => 'No SMS DID assigned'];
		}
		try {
			$sms = $this->FreePBX->Sms;
		} catch (\Throwable $e) {
			$sms = null;
		}
		if (!is_object($sms) || !method_exists($sms, 'sendSms')) {
			return ['ok' => false, 'http' => 503, 'error' => 'SMS module is not available on this PBX'];
		}
		$result = $sms->sendSms($routingDid, $toDigits, $message);
		if (empty($result['status'])) {
			return ['ok' => false, 'http' => 502, 'error' => 'SMS send failed'];
		}
		return [
			'ok' => true,
			'id' => $result['id'] ?? '',
			'from' => preg_replace('/\D/', '', $routingDid),
			'to' => $toDigits,
		];
	}

	/**
	 * @return array{ok:bool,http?:int,error?:string,user_extension?:string,dids?:list<array{did:string,adaptor?:string}>,did_digits?:list<string>,routing_dids?:list<string>}
	 */
	private function smsContext($extension, $secret) {
		$profile = $this->userProfile($extension, $secret);
		if (empty($profile['ok'])) {
			return $profile;
		}
		$device = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $extension);
		$desk = (string) ($profile['user_extension'] ?? '');
		try {
			$st = $this->FreePBX->Database->prepare('SELECT user FROM devices WHERE id = ?');
			$st->execute([$device]);
			$row = $st->fetch(\PDO::FETCH_ASSOC);
			if (is_array($row) && !empty($row['user'])) {
				$linked = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $row['user']);
				if ($linked !== '') {
					$desk = $linked;
				}
			}
		} catch (\Throwable $e) {
			// devices.user is optional; the 97/98/99 prefix still applies
		}
		$ids = self::smsIdentityIds($device, $desk);
		$uids = $this->usermanIdsForSms($ids);
		$dids = [];
		$routing = [];
		$digits = [];
		if ($uids !== []) {
			$placeholders = implode(',', array_fill(0, count($uids), '?'));
			try {
				$st = $this->FreePBX->Database->prepare(
					"SELECT did, adaptor FROM sms_routing WHERE uid IN ($placeholders)"
				);
				$st->execute($uids);
				foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					if (!is_array($row)) {
						continue;
					}
					$rawDid = trim((string) ($row['did'] ?? ''));
					$only = preg_replace('/\D/', '', $rawDid);
					if ($only === '' || isset($dids[$only])) {
						continue;
					}
					$adaptor = trim((string) ($row['adaptor'] ?? ''));
					$dids[$only] = $adaptor !== ''
						? ['did' => $only, 'adaptor' => $adaptor]
						: ['did' => $only];
					$routing[] = $rawDid;
					$digits[] = $only;
				}
			} catch (\Throwable $e) {
				// SMS module tables are not installed
			}
		}
		return [
			'ok' => true,
			'user_extension' => $desk !== '' ? $desk : ($ids[count($ids) - 1] ?? $device),
			'dids' => array_values($dids),
			'did_digits' => $digits,
			'routing_dids' => $routing,
		];
	}

	/**
	 * @param list<string> $ids
	 * @return list<int>
	 */
	private function usermanIdsForSms(array $ids) {
		$uids = [];
		if ($ids === []) {
			return [];
		}
		try {
			$db = $this->FreePBX->Database;
		} catch (\Throwable $e) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		try {
			$st = $db->prepare(
				"SELECT id FROM userman_users WHERE default_extension IN ($placeholders) OR username IN ($placeholders)"
			);
			$st->execute(array_merge($ids, $ids));
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				if (is_array($row) && isset($row['id'])) {
					$uids[] = (int) $row['id'];
				}
			}
		} catch (\Throwable $e) {
			// userman table missing
		}
		try {
			$st = $db->prepare(
				"SELECT uid, val FROM userman_users_settings WHERE module = 'global' AND `key` = 'assigned'"
			);
			$st->execute();
			foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				if (!is_array($row)) {
					continue;
				}
				$assigned = self::assignedDeviceIds($row['val'] ?? '');
				if (array_intersect($assigned, $ids) !== []) {
					$uids[] = (int) ($row['uid'] ?? 0);
				}
			}
		} catch (\Throwable $e) {
			// assigned devices are optional
		}
		$uids = array_values(array_unique(array_filter($uids)));
		return $uids;
	}

	/**
	 * @param list<string> $routingDids
	 * @return list<array<string,mixed>>
	 */
	private function smsMessageRows(array $routingDids) {
		if ($routingDids === []) {
			return [];
		}
		$wanted = [];
		foreach ($routingDids as $did) {
			$digits = preg_replace('/\D/', '', (string) $did);
			if ($digits !== '') {
				$wanted[] = $digits;
			}
		}
		if ($wanted === []) {
			return [];
		}
		try {
			$st = $this->FreePBX->Database->prepare(
				'SELECT id, `from`, `to`, direction, body, threadid, timestamp, `read` FROM sms_messages ORDER BY timestamp DESC LIMIT 400'
			);
			$st->execute();
			$rows = $st->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
		if (!is_array($rows)) {
			return [];
		}
		$matched = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$from = preg_replace('/\D/', '', (string) ($row['from'] ?? ''));
			$to = preg_replace('/\D/', '', (string) ($row['to'] ?? ''));
			if ($this->smsDidMatches($from, $wanted) || $this->smsDidMatches($to, $wanted)) {
				$matched[] = $row;
			}
		}
		return $matched;
	}

	/**
	 * @param list<string> $candidates
	 */
	private function smsDidMatches($value, array $candidates) {
		$value = preg_replace('/\D/', '', (string) $value);
		if ($value === '') {
			return false;
		}
		foreach ($candidates as $candidate) {
			$candidate = preg_replace('/\D/', '', (string) $candidate);
			if ($candidate === '') {
				continue;
			}
			if ($value === $candidate) {
				return true;
			}
			$shortValue = strlen($value) > 10 ? substr($value, -10) : $value;
			$shortCandidate = strlen($candidate) > 10 ? substr($candidate, -10) : $candidate;
			if ($shortValue === $shortCandidate) {
				return true;
			}
		}
		return false;
	}

	private function newUuid() {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
