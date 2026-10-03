<?php
/**
 * CLI checks for multiple softphone registrations on one extension.
 * No FreePBX and no network.
 */
if (!class_exists('FreePBX_Helpers')) {
	class FreePBX_Helpers {}
}
if (!interface_exists('BMO')) {
	interface BMO {}
}

require __DIR__ . '/../Xrflowsoftphone.class.php';

use FreePBX\modules\Xrflowsoftphone;

function xrflow_fail($message) {
	fwrite(STDERR, "FAIL {$message}\n");
	exit(1);
}

$expected = Xrflowsoftphone::webrtcExpected();
if (($expected['max_contacts'] ?? '') !== Xrflowsoftphone::SOFTPHONE_MAX_CONTACTS) {
	xrflow_fail('max_contacts missing from softphone template');
}
if ((int) $expected['max_contacts'] < 2) {
	xrflow_fail('max_contacts still one');
}
if (($expected['remove_existing'] ?? '') !== 'yes') {
	xrflow_fail('remove_existing');
}
if (isset($expected['secret'])) {
	xrflow_fail('template must not touch the desk-phone secret');
}

$labels = Xrflowsoftphone::webrtcIssueLabels();
if (empty($labels['max_contacts'])) {
	xrflow_fail('max_contacts label');
}

// Office code stays off VPN even after a later home code set the extension flag.
if (Xrflowsoftphone::enrollCodeNeedsVpn(['need_openvpn' => 0], true)) {
	xrflow_fail('office code followed the extension flag');
}
if (!Xrflowsoftphone::enrollCodeNeedsVpn(['need_openvpn' => '1'], false)) {
	xrflow_fail('home code ignored its own flag');
}
if (!Xrflowsoftphone::enrollCodeNeedsVpn(['need_openvpn' => 1], false)) {
	xrflow_fail('home code int');
}
if (Xrflowsoftphone::enrollCodeNeedsVpn(['need_openvpn' => '0'], true)) {
	xrflow_fail('string zero is VPN on');
}
// Codes from before the column still use the extension map.
if (!Xrflowsoftphone::enrollCodeNeedsVpn(['extension' => '1016'], true)) {
	xrflow_fail('legacy row should follow the extension flag');
}
if (Xrflowsoftphone::enrollCodeNeedsVpn(['extension' => '1016'], false)) {
	xrflow_fail('legacy row without the flag');
}

$office = Xrflowsoftphone::parseProfileRequest('1016|office');
if ($office === null || $office['extension'] !== '1016' || $office['kind'] !== 'office' || $office['needVpn'] !== false) {
	xrflow_fail('office profile request');
}
$home = Xrflowsoftphone::parseProfileRequest('981016|home');
if ($home === null || $home['needVpn'] !== true || $home['kind'] !== 'home') {
	xrflow_fail('home profile request');
}
if (Xrflowsoftphone::parseProfileRequest('1016|vpn') !== null) {
	xrflow_fail('unknown profile kind');
}
if (Xrflowsoftphone::parseProfileRequest('office') !== null) {
	xrflow_fail('profile without extension');
}
if (Xrflowsoftphone::parseProfileRequest('|office') !== null) {
	xrflow_fail('empty extension');
}

$fixture = sys_get_temp_dir() . '/xrflow-ovpn-test-' . getmypid();
if (!mkdir($fixture, 0700, true) && !is_dir($fixture)) {
	xrflow_fail('temp dir');
}
$conf = "client\ndev tun\nproto udp\nremote pbx.example.com 1194\nca sysadmin_ca.crt\ncert sysadmin_client3.crt\nkey sysadmin_client3.key\n";
file_put_contents($fixture . '/sysadmin_client3.conf', $conf);
file_put_contents($fixture . '/sysadmin_client3.crt', "CERT3\n");
file_put_contents($fixture . '/sysadmin_client3.key', "KEY3\n");
file_put_contents($fixture . '/sysadmin_ca.crt', "CA\n");
$conf9 = "client\ndev tun\nremote other.example.com 1194\nca sysadmin_ca.crt\ncert sysadmin_client9.crt\nkey sysadmin_client9.key\n";
file_put_contents($fixture . '/sysadmin_client9.conf', $conf9);
file_put_contents($fixture . '/sysadmin_client9.crt', "CERT9\n");
file_put_contents($fixture . '/sysadmin_client9.key', "KEY9\n");

$ids = Xrflowsoftphone::sysadminClientIdsInDirs([$fixture]);
sort($ids);
if ($ids !== ['3', '9']) {
	xrflow_fail('client ids ' . json_encode($ids));
}
$clients = [
	'3' => ['description' => '1016 - Dan', 'enabled' => 1],
	'9' => ['description' => '1000 - Other', 'enabled' => '0'],
];
if (Xrflowsoftphone::selectSysadminClientId('1016', $clients, $ids) !== '3') {
	xrflow_fail('home client should match extension 1016');
}
if (Xrflowsoftphone::selectSysadminClientId('1000', $clients, $ids) !== '') {
	xrflow_fail('disabled client must not be selected');
}
if (Xrflowsoftphone::selectSysadminClientId('1016', $clients, ['3', '9'], ['9']) !== '9') {
	xrflow_fail('assigned client id should win');
}
if (Xrflowsoftphone::selectSysadminClientId('1016', [], ['3']) !== '3') {
	xrflow_fail('single unlabeled client');
}
if (Xrflowsoftphone::selectSysadminClientId('1016', ['3' => ['description' => '1000 - Other', 'enabled' => 1]], ['3']) !== '') {
	xrflow_fail('single client labeled for someone else');
}
if (Xrflowsoftphone::selectSysadminClientId('1016', [], ['3', '9']) !== '') {
	xrflow_fail('two unlabeled clients must not be guessed');
}
if (Xrflowsoftphone::clientIdsFromUserSetting('vpn_enabled', 'yes') !== []) {
	xrflow_fail('vpn_enabled is not a client id');
}
if (Xrflowsoftphone::clientIdsFromUserSetting('vpnclient', '3') !== ['3']) {
	xrflow_fail('vpnclient id');
}

$bundle = Xrflowsoftphone::bundleFromClientDirs('3', [$fixture]);
if ($bundle === null) {
	xrflow_fail('bundle missing');
}
if ($bundle['config'] !== $conf) {
	xrflow_fail('config was rewritten');
}
if (strpos($bundle['config'], 'remote pbx.example.com 1194') === false) {
	xrflow_fail('remote missing');
}
if (strpos($bundle['config'], 'ca sysadmin_ca.crt') === false) {
	xrflow_fail('ca line rewritten');
}
$names = [];
foreach ($bundle['files'] as $file) {
	$names[$file['name']] = $file['content'];
}
if (($names['sysadmin_ca.crt'] ?? '') !== "CA\n" || ($names['sysadmin_client3.crt'] ?? '') !== "CERT3\n" || ($names['sysadmin_client3.key'] ?? '') !== "KEY3\n") {
	xrflow_fail('sibling files');
}
if (Xrflowsoftphone::bundleFromClientDirs('../3', [$fixture]) !== null) {
	xrflow_fail('path traversal client id');
}
if (Xrflowsoftphone::findReadableBasename('../sysadmin_ca.crt', [$fixture]) !== null) {
	xrflow_fail('path traversal basename');
}

foreach (scandir($fixture) as $name) {
	if ($name === '.' || $name === '..') {
		continue;
	}
	unlink($fixture . '/' . $name);
}
rmdir($fixture);

echo "ok\n";
