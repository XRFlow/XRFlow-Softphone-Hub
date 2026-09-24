<?php
/**
 * Desks that have checked in from XRFlow Softphone.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $view ?? 'fleet';
$fleetRows = $fleetRows ?? [];
$online = 0;
foreach ($fleetRows as $row) {
	if (!empty($row['online'])) {
		$online++;
	}
}
$ago = static function ($ts) {
	$ts = (int) $ts;
	if ($ts <= 0) {
		return _('never');
	}
	$d = time() - $ts;
	if ($d < 90) {
		return _('just now');
	}
	if ($d < 3600) {
		return sprintf(_('%d min ago'), (int) floor($d / 60));
	}
	if ($d < 86400) {
		return sprintf(_('%d h ago'), (int) floor($d / 3600));
	}
	return date('Y-m-d H:i', $ts);
};
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub") ?></h1>
					<p class="text-muted"><?php echo _("Who is signed in, which app version, and whether that desk has a Softphone license. Desks on 0.2.60 or later check in about once a minute.") ?></p>
					<?php include __DIR__ . '/nav.php'; ?>

					<div class="alert alert-info" style="margin-top:1rem;">
						<?php echo sprintf(_("%d online · %d seen in the last 30 days"), $online, count($fleetRows)) ?>
					</div>

					<div class="panel panel-default">
						<div class="panel-heading"><strong><?php echo _("Fleet") ?></strong></div>
						<div class="panel-body">
							<?php if (!$fleetRows) { ?>
								<p class="text-muted"><?php echo _("No softphones have checked in yet.") ?></p>
							<?php } else { ?>
								<div class="table-responsive">
									<table class="table table-striped table-condensed">
										<thead>
											<tr>
												<th><?php echo _("Extension") ?></th>
												<th><?php echo _("Name") ?></th>
												<th><?php echo _("Version") ?></th>
												<th><?php echo _("License") ?></th>
												<th><?php echo _("SIP") ?></th>
												<th><?php echo _("Status") ?></th>
												<th><?php echo _("Last seen") ?></th>
											</tr>
										</thead>
										<tbody>
										<?php foreach ($fleetRows as $row) { ?>
											<tr>
												<td><code><?php echo htmlspecialchars((string) $row['extension']) ?></code></td>
												<td><?php echo htmlspecialchars((string) ($row['name'] ?? '')) ?></td>
												<td><code><?php echo htmlspecialchars((string) ($row['version'] ?? '')) ?></code></td>
												<td><?php echo !empty($row['licensed']) ? _("Yes") : _("No") ?></td>
												<td><?php echo !empty($row['registered']) ? _("Registered") : _("Not registered") ?></td>
												<td><?php echo !empty($row['online']) ? _("Online") : _("Offline") ?></td>
												<td><?php echo htmlspecialchars($ago($row['last_seen'] ?? 0)) ?></td>
											</tr>
										<?php } ?>
										</tbody>
									</table>
								</div>
							<?php } ?>
							<p class="small text-muted"><?php echo _("Online means a check-in in the last 3 minutes. The SIP secret is checked and not stored with the row.") ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
