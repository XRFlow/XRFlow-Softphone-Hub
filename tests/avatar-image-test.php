<?php
/**
 * CLI checks for User Manager photo encoding. No FreePBX and no network.
 */
require __DIR__ . '/../XrflowAvatarImage.php';

use FreePBX\modules\XrflowAvatarImage;

function xrflow_fail($message) {
	fwrite(STDERR, "FAIL {$message}\n");
	exit(1);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
if (!is_string($png) || strlen($png) < 32) {
	xrflow_fail('fixture png');
}

$url = XrflowAvatarImage::dataUrl($png, 'image/png');
if (!is_string($url) || strncmp($url, 'data:image/png;base64,', 22) !== 0) {
	xrflow_fail('png data url');
}

$byMagic = XrflowAvatarImage::dataUrl($png, '');
if (!is_string($byMagic) || strncmp($byMagic, 'data:image/', 11) !== 0) {
	xrflow_fail('magic mime');
}

$junk = XrflowAvatarImage::dataUrl(str_repeat('x', 64), 'text/plain');
if ($junk !== null) {
	xrflow_fail('reject non-image');
}

$tiny = XrflowAvatarImage::dataUrl('short', 'image/png');
if ($tiny !== null) {
	xrflow_fail('reject tiny blob');
}

$row = XrflowAvatarImage::userPayload(
	[
		'id' => 5,
		'username' => '1016',
		'default_extension' => '1016',
		'fname' => 'Dan',
		'lname' => 'Russo',
		'email' => 'dan@j2b.com',
	],
	$url,
	'["981016"]'
);
if (!is_array($row)) {
	xrflow_fail('payload');
}
if (!in_array('1016', $row['extensions'], true) || !in_array('981016', $row['extensions'], true)) {
	xrflow_fail('assigned device extension');
}
if (($row['avatar'] ?? '') !== $url || $row['email'] !== 'dan@j2b.com' || $row['name'] !== 'Dan Russo') {
	xrflow_fail('photo email name');
}
if ($row['extension'] !== '1016') {
	xrflow_fail('primary extension');
}

$emailOnly = XrflowAvatarImage::userPayload(
	[
		'username' => '2000',
		'default_extension' => '2000',
		'email' => 'a@b.com',
	],
	null,
	null
);
if (!is_array($emailOnly) || $emailOnly['email'] !== 'a@b.com' || isset($emailOnly['avatar'])) {
	xrflow_fail('email only');
}

$none = XrflowAvatarImage::userPayload(
	[
		'username' => '2001',
		'default_extension' => 'none',
		'email' => '',
	],
	null,
	null
);
if ($none !== null) {
	xrflow_fail('skip empty');
}

$assigned = XrflowAvatarImage::assignedExtensions(['1016', 'none', 'not an ext!', '981016']);
if ($assigned !== ['1016', '981016']) {
	xrflow_fail('assigned filter ' . json_encode($assigned));
}

echo "ok\n";
