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

echo "ok\n";
