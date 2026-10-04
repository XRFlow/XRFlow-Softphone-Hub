<?php
/**
 * CLI checks for FreePBX API allowed_scopes. No FreePBX and no network.
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

if (Xrflowsoftphone::OAUTH_SCOPES !== 'gql rest') {
	xrflow_fail('new API apps must store space-separated gql rest');
}
if (strpos(Xrflowsoftphone::OAUTH_SCOPES, ',') !== false) {
	xrflow_fail('comma-separated scopes are one invalid FreePBX scope');
}

if (Xrflowsoftphone::normalizeOauthAllowedScopes('gql,rest') !== 'gql rest') {
	xrflow_fail('comma form');
}
if (Xrflowsoftphone::normalizeOauthAllowedScopes('gql rest') !== 'gql rest') {
	xrflow_fail('already valid');
}
if (Xrflowsoftphone::normalizeOauthAllowedScopes('') !== '') {
	xrflow_fail('empty means unrestricted and must stay empty');
}
if (Xrflowsoftphone::normalizeOauthAllowedScopes('rest:contactmanager:read') !== 'rest:contactmanager:read gql rest') {
	xrflow_fail('missing masters');
}
if (!Xrflowsoftphone::oauthScopesNeedUpdate('gql,rest')) {
	xrflow_fail('comma form should be rewritten');
}
if (Xrflowsoftphone::oauthScopesNeedUpdate('gql rest')) {
	xrflow_fail('valid scopes should stay');
}
if (Xrflowsoftphone::oauthScopesNeedUpdate('')) {
	xrflow_fail('empty scopes should stay');
}

echo "ok\n";
