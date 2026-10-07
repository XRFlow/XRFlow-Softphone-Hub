<?php
/**
 * Companion device ids that must be checked for an SMS DID.
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

$ids = Xrflowsoftphone::smsIdentityIds('981016', '1016');
foreach (['981016', '1016'] as $need) {
	if (!in_array($need, $ids, true)) {
		xrflow_fail("missing {$need} in " . implode(',', $ids));
	}
}

$stripped = Xrflowsoftphone::smsIdentityIds('971001', '');
if (!in_array('1001', $stripped, true) || !in_array('971001', $stripped, true)) {
	xrflow_fail('97 prefix did not add the desk extension');
}

$deskOnly = Xrflowsoftphone::smsIdentityIds('1016', '1016');
if ($deskOnly !== ['1016']) {
	xrflow_fail('desk extension duplicated: ' . implode(',', $deskOnly));
}

$assigned = Xrflowsoftphone::assignedDeviceIds(serialize(['981016', 'none']));
if (!in_array('981016', $assigned, true)) {
	xrflow_fail('serialized assigned devices were dropped');
}
if (in_array('none', $assigned, true)) {
	xrflow_fail('none should not be an extension');
}

echo "ok\n";
