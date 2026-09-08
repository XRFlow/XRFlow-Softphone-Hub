<?php
/**
 * Shared admin tabs for XRFlow Softphone Hub.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $view ?? 'dashboard';
$tabs = [
	'dashboard' => _('Dashboard'),
	'compliance' => _('WebRTC'),
	'enroll' => _('Enroll'),
	'about' => _('About'),
];
?>
<ul class="nav nav-tabs">
<?php foreach ($tabs as $id => $label) { ?>
	<li<?php echo $view === $id ? ' class="active"' : '' ?>>
		<a href="?display=xrflowsoftphone&amp;view=<?php echo htmlspecialchars($id) ?>"><?php echo $label ?></a>
	</li>
<?php } ?>
</ul>
