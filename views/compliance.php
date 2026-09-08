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
						<?php echo _("This is the 488 checklist: AVPF, ICE, rtcp-mux, DTLS-SRTP, Direct Media=No, webrtc, dtls_auto_generate_cert. Enroll will refuse non-compliant extensions unless you override (logged).") ?>
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
									<th><?php echo _("WebRTC") ?></th>
									<th><?php echo _("Issues") ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($rows as $row) { ?>
								<tr>
									<td><input type="checkbox" name="ext[]" value="<?php echo htmlspecialchars($row['extension']) ?>"/></td>
									<td><?php echo htmlspecialchars($row['extension']) ?></td>
									<td><?php echo htmlspecialchars($row['name']) ?></td>
									<td><?php echo !empty($row['ok']) ? _("OK") : _("Needs repair") ?></td>
									<td class="small"><?php echo htmlspecialchars(implode('; ', $row['issues'])) ?></td>
								</tr>
							<?php } ?>
							<?php if (!$rows) { ?>
								<tr><td colspan="5"><?php echo _("No extensions found.") ?></td></tr>
							<?php } ?>
							</tbody>
						</table>
						<label class="small">
							<input type="checkbox" name="force_override" value="1"/>
							<?php echo _("Log an enroll override for selected extensions (still apply template now)") ?>
						</label>
						<div style="margin-top:0.75rem;">
							<button type="submit" class="btn btn-primary"><?php echo _("Apply XRFlow WebRTC template") ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
