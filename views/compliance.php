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

					<?php if (is_array($flash) && !empty($flash['ok']) && !empty($flash['token'])) { ?>
						<div class="alert alert-success" style="margin-top:1rem;">
							<p><?php echo _("Copy this once — 15 minutes, one use.") ?></p>
							<p><?php echo htmlspecialchars((string) ($flash['message'] ?? '')) ?></p>
							<?php if (!empty($flash['repaired'])) { ?>
								<p><?php echo _("Calling settings were added on a separate softphone device. The desk phone was not changed. Click Apply Config (red button) in FreePBX before they place a call.") ?></p>
							<?php } ?>
							<?php if (!empty($flash['softphone_device'])) { ?>
								<p class="small"><?php echo _("Softphone SIP device") ?>: <code><?php echo htmlspecialchars((string) $flash['softphone_device']) ?></code>
									— <?php echo _("office and home profiles share this device and can both be registered.") ?></p>
							<?php } ?>
							<?php if (($flash['profile'] ?? '') === 'home' || !empty($flash['need_openvpn'])) { ?>
								<p><?php echo _("This is the home profile. It uses OpenVPN. Give them the System Admin .ovpn file unchanged. The office profile is a separate code.") ?></p>
							<?php } else { ?>
								<p><?php echo _("This is the office profile. It connects on the office LAN without OpenVPN. The home profile is a separate code from the other button.") ?></p>
							<?php } ?>
							<p><strong><?php echo _("Deep link") ?>:</strong><br/>
								<code style="word-break:break-all;user-select:all"><?php echo htmlspecialchars((string) $flash['deep_link']) ?></code></p>
							<p><strong><?php echo _("Token") ?>:</strong>
								<code style="user-select:all"><?php echo htmlspecialchars((string) $flash['token']) ?></code></p>
							<p><strong><?php echo _("HTTPS") ?>:</strong><br/>
								<code style="word-break:break-all"><?php echo htmlspecialchars((string) $flash['https_link']) ?></code></p>
							<p class="small mb-0"><?php echo _("Paste the token in XRFlow Softphone → Use Hub enroll code on that computer. Create the other profile with the other button on the same row.") ?></p>
						</div>
					<?php } elseif (is_array($flash) && !empty($flash['error'])) { ?>
						<div class="alert alert-danger" style="margin-top:1rem;">
							<p class="mb-0"><strong><?php echo htmlspecialchars((string) $flash['error']) ?></strong></p>
						</div>
					<?php } elseif (is_array($flash) && isset($flash['message'])) { ?>
						<div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-warning' ?>" style="margin-top:1rem;">
							<?php echo htmlspecialchars($flash['message']) ?>
						</div>
					<?php } ?>

					<p class="text-muted" style="margin-top:1rem;">
						<?php echo _("Each person has one softphone device next to the desk phone. On that row, create an office profile (no VPN) and a home profile (VPN). Each button makes its own enroll code. Redeem each code on its own computer. Both can stay registered. The desk phone is not changed.") ?>
					</p>
					<p class="small"><?php echo htmlspecialchars($ovpn) ?></p>

					<form method="post" action="config.php?display=xrflowsoftphone&amp;view=compliance" id="xrflow-webrtc-form">
						<input type="hidden" name="xrflow_hub_action" value="apply_webrtc"/>
						<div style="margin:0.75rem 0;">
							<button type="button" class="btn btn-default btn-sm" id="xrflow-webrtc-select-all"><?php echo _("Select all") ?></button>
							<button type="button" class="btn btn-default btn-sm" id="xrflow-webrtc-deselect-all"><?php echo _("Deselect all") ?></button>
						</div>
						<table class="table table-striped" id="xrflow-webrtc-table">
							<thead>
								<tr>
									<th></th>
									<th><?php echo _("Ext") ?></th>
									<th><?php echo _("Name") ?></th>
									<th><?php echo _("Desk phone") ?></th>
									<th><?php echo _("Softphone") ?></th>
									<th><?php echo _("Profiles") ?></th>
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
									<td style="white-space:nowrap;">
										<button type="submit" class="btn btn-default btn-sm" name="xrflow_profile" value="<?php echo htmlspecialchars((string) $row['extension']) ?>|office"><?php echo _("Create office profile") ?></button>
										<button type="submit" class="btn btn-default btn-sm" name="xrflow_profile" value="<?php echo htmlspecialchars((string) $row['extension']) ?>|home"><?php echo _("Create home profile") ?></button>
									</td>
									<td class="small"><?php echo htmlspecialchars(implode('; ', $row['issues'])) ?></td>
								</tr>
							<?php } ?>
							<?php if (!$rows) { ?>
								<tr><td colspan="7"><?php echo _("No extensions found.") ?></td></tr>
							<?php } ?>
							</tbody>
						</table>
						<div style="margin-top:0.75rem;">
							<button type="button" class="btn btn-default btn-sm" id="xrflow-webrtc-select-all-bottom"><?php echo _("Select all") ?></button>
							<button type="button" class="btn btn-default btn-sm" id="xrflow-webrtc-deselect-all-bottom"><?php echo _("Deselect all") ?></button>
							<button type="submit" class="btn btn-primary"><?php echo _("Set up selected softphones") ?></button>
						</div>
					</form>
					<script>
					(function () {
						var form = document.getElementById('xrflow-webrtc-form');
						if (!form) { return; }
						function setAll(checked) {
							var boxes = form.querySelectorAll('input[type="checkbox"][name="ext[]"]');
							for (var i = 0; i < boxes.length; i++) {
								boxes[i].checked = checked;
							}
						}
						function bind(id, checked) {
							var button = document.getElementById(id);
							if (!button) { return; }
							button.addEventListener('click', function () {
								setAll(checked);
							});
						}
						bind('xrflow-webrtc-select-all', true);
						bind('xrflow-webrtc-deselect-all', false);
						bind('xrflow-webrtc-select-all-bottom', true);
						bind('xrflow-webrtc-deselect-all-bottom', false);
					})();
					</script>
				</div>
			</div>
		</div>
	</div>
</div>
