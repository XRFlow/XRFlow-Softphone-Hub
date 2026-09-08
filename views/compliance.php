<?php
/**
 * WebRTC compliance page for XRFlow Softphone Hub.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$flash = $flash ?? null;
$rows = $compliance ?? [];
$ovpn = $openvpnHint ?? '';
$view = $view ?? 'compliance';
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub — WebRTC") ?></h1>
					<?php include __DIR__ . '/nav.php'; ?>

					<?php if (is_array($flash) && isset($flash['message'])) { ?>
						<div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-warning' ?>" style="margin-top:1rem;">
							<?php echo htmlspecialchars($flash['message']) ?>
						</div>
					<?php } ?>

					<p class="text-muted" style="margin-top:1rem;">
						<?php echo _("Hub adds a separate softphone device next to the desk phone. The desk phone’s SIP settings are not changed, so a Yealink/Poly on the same extension keeps working.") ?>
					</p>
					<p class="small"><?php echo htmlspecialchars($ovpn) ?></p>

					<form method="post" action="config.php?display=xrflowsoftphone&amp;view=compliance">
						<input type="hidden" name="xrflow_hub_action" value="apply_webrtc"/>
						<table class="table table-striped">
							<thead>
								<tr>
									<th></th>
									<th><?php echo _("Ext") ?></th>
									<th><?php echo _("Name") ?></th>
									<th><?php echo _("Desk phone") ?></th>
									<th><?php echo _("Softphone") ?></th>
									<th><?php echo _("Notes") ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($rows as $row) { ?>
								<tr>
									<td><input type="checkbox" name="ext[]" value="<?php echo htmlspecialchars($row['extension']) ?>" <?php echo empty($row['ok']) ? 'checked' : '' ?>/></td>
									<td><?php echo htmlspecialchars($row['extension']) ?></td>
									<td><?php echo htmlspecialchars($row['name']) ?></td>
									<td><?php echo !empty($row['desk_phone']) ? _("Left as-is") : _("None") ?></td>
									<td>
										<?php if (!empty($row['ok'])) { ?>
											<span class="label label-success"><?php echo _("Ready") ?></span>
											<?php if (!empty($row['softphone_device'])) { ?>
												<code><?php echo htmlspecialchars($row['softphone_device']) ?></code>
											<?php } ?>
										<?php } else { ?>
											<span class="label label-warning"><?php echo _("Needs setup") ?></span>
										<?php } ?>
									</td>
									<td class="small"><?php echo htmlspecialchars(implode('; ', $row['issues'])) ?></td>
								</tr>
							<?php } ?>
							<?php if (!$rows) { ?>
								<tr><td colspan="6"><?php echo _("No extensions found.") ?></td></tr>
							<?php } ?>
							</tbody>
						</table>
						<div style="margin-top:0.75rem;">
							<button type="submit" class="btn btn-primary"><?php echo _("Set up selected softphones") ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
