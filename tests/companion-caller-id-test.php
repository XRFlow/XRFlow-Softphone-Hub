<?php
/**
 * Companion caller ID stays the desk extension.
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

$rewritten = Xrflowsoftphone::companionCallerId('971016', '1016', 'Dan Russo', 'Dan Russo <971016>');
if ($rewritten !== 'Dan Russo <1016>') {
	xrflow_fail('companion number was not rewritten: ' . var_export($rewritten, true));
}

$fromEmpty = Xrflowsoftphone::companionCallerId('981016', '1016', 'Dan Russo', '');
if ($fromEmpty !== 'Dan Russo <1016>') {
	xrflow_fail('empty caller ID was not filled: ' . var_export($fromEmpty, true));
}

$otherPrefix = Xrflowsoftphone::companionCallerId('981016', '1016', 'Dan Russo', 'dan russo <971016>');
if ($otherPrefix !== 'Dan Russo <1016>') {
	xrflow_fail('97 number on a 98 device was kept: ' . var_export($otherPrefix, true));
}

$alreadyDesk = Xrflowsoftphone::companionCallerId('971016', '1016', 'Dan Russo', 'dan russo <1016>');
if ($alreadyDesk !== null) {
	xrflow_fail('desk caller ID should stay: ' . var_export($alreadyDesk, true));
}

$customDid = Xrflowsoftphone::companionCallerId('971016', '1016', 'Dan Russo', 'Company <15551234567>');
if ($customDid !== null) {
	xrflow_fail('custom DID should stay: ' . var_export($customDid, true));
}

$deskDevice = Xrflowsoftphone::companionCallerId('1016', '1016', 'Dan Russo', '<971016>');
if ($deskDevice !== null) {
	xrflow_fail('desk phone must not be rewritten');
}

$linked = Xrflowsoftphone::companionDeviceUser('971016', '1016', '971016');
if ($linked !== '1016') {
	xrflow_fail('companion user was not moved to the desk: ' . var_export($linked, true));
}

$blankUser = Xrflowsoftphone::companionDeviceUser('991001', '1001', '');
if ($blankUser !== '1001') {
	xrflow_fail('blank device user should become the desk');
}

$keepDesk = Xrflowsoftphone::companionDeviceUser('971016', '1016', '1016');
if ($keepDesk !== null) {
	xrflow_fail('desk user link should stay');
}

$keepOther = Xrflowsoftphone::companionDeviceUser('971016', '1016', '2000');
if ($keepOther !== null) {
	xrflow_fail('a different user must not be replaced');
}

$dirtyName = Xrflowsoftphone::companionCallerId('971016', '1016', "Dan\nRusso <x>", '');
if ($dirtyName !== 'Dan Russo x <1016>') {
	xrflow_fail('name was not cleaned: ' . var_export($dirtyName, true));
}

echo "ok\n";
