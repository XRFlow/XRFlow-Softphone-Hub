<?php
/**
 * Enroll page for XRFlow Softphone Hub.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$flash = $flash ?? null;
$exts = $extensions ?? [];
$rows = $compliance ?? [];
$vpnByExt = $vpnByExt ?? [];
$view = $view ?? 'enroll';
$byExt = [];
foreach ($rows as $row) {
	$byExt[$row['extension']] = $row;
}
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub — Enroll") ?></h1>
					<?php include __DIR__ . '/nav.php'; ?>

					<?php if (is_array($flash) && empty($flash['ok']) && !empty($flash['error'])) { ?>
						<div class="alert alert-danger" style="margin-top:1rem;">
							<p><strong><?php echo htmlspecialchars((string) $flash['error']) ?></strong></p>
							<?php if (!empty($flash['issues']) && is_array($flash['issues'])) { ?>
								<p><?php echo _("What is missing") ?>:</p>
								<ul>
									<?php foreach ($flash['issues'] as $issue) { ?>
										<li><?php echo htmlspecialchars((string) $issue) ?></li>
									<?php } ?>
								</ul>
							<?php } ?>
							<?php if (!empty($flash['hint'])) { ?>
								<p class="mb-0"><?php echo htmlspecialchars((string) $flash['hint']) ?></p>
							<?php } ?>
						</div>
					<?php } ?>
					<?php if (is_array($flash) && !empty($flash['ok']) && !empty($flash['token'])) { ?>
						<div class="alert alert-success" style="margin-top:1rem;">
							<p><?php echo _("Copy this once — 15 minutes, one use.") ?></p>
							<?php if (!empty($flash['repaired'])) { ?>
								<p><?php echo _("Calling settings were added on a separate softphone device. The desk phone was not changed. Click Apply Config (red button) in FreePBX before they place a call.") ?></p>
							<?php } ?>
							<?php if (!empty($flash['softphone_device'])) { ?>
								<p class="small"><?php echo _("Softphone SIP device") ?>: <code><?php echo htmlspecialchars((string) $flash['softphone_device']) ?></code>
									— <?php echo _("same person / same extension; desk phone keeps its own device.") ?></p>
							<?php } ?>
							<?php if (!empty($flash['need_openvpn'])) { ?>
								<p><?php echo _("This desk is set to use OpenVPN. Give them the System Admin .ovpn profile (do not change remotes).") ?></p>
							<?php } else { ?>
								<p><?php echo _("This desk is set to connect without OpenVPN.") ?></p>
							<?php } ?>
							<p><strong><?php echo _("Deep link") ?>:</strong><br/>
								<code style="word-break:break-all;user-select:all"><?php echo htmlspecialchars($flash['deep_link']) ?></code></p>
							<p><strong><?php echo _("Token") ?>:</strong>
								<code style="user-select:all"><?php echo htmlspecialchars($flash['token']) ?></code></p>
							<p><strong><?php echo _("HTTPS") ?>:</strong><br/>
								<code style="word-break:break-all"><?php echo htmlspecialchars($flash['https_link']) ?></code></p>
							<p class="small"><?php echo _("Paste the token in XRFlow Softphone → Use Hub enroll code. SIP secret is delivered only on redeem over TLS.") ?></p>
						</div>
					<?php } ?>

					<p class="text-muted" style="margin-top:1rem;">
						<?php echo _("Pick a person, say whether they need OpenVPN (home / off-site), then generate a code. Desk phones on the same extension are left alone — Hub adds a separate softphone device.") ?>
					</p>

					<?php if (!$exts) { ?>
						<p class="text-warning"><?php echo _("No Core extensions were returned. Confirm Applications → Extensions exist, then reload FreePBX.") ?></p>
					<?php } else { ?>
						<div class="form-group" style="max-width:28rem;">
							<label for="xrflow-enroll-filter"><?php echo _("Find an extension") ?></label>
							<input type="search" class="form-control" id="xrflow-enroll-filter" placeholder="<?php echo htmlspecialchars(_('Name or number')) ?>" autocomplete="off"/>
						</div>
						<table class="table table-striped" id="xrflow-enroll-table">
							<thead>
								<tr>
									<th><?php echo _("Ext") ?></th>
									<th><?php echo _("Name") ?></th>
									<th><?php echo _("Softphone") ?></th>
									<th><?php echo _("Needs OpenVPN") ?></th>
									<th><?php echo _("Enroll") ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($exts as $ext => $name) {
								$info = $byExt[$ext] ?? [];
								$ready = !empty($info['ok']);
								$search = strtolower($ext . ' ' . $name);
								$vpnOn = !empty($vpnByExt[$ext]);
								?>
								<tr data-search="<?php echo htmlspecialchars($search) ?>">
									<td><?php echo htmlspecialchars((string) $ext) ?></td>
									<td><?php echo htmlspecialchars((string) $name) ?></td>
									<td>
										<?php if ($ready) { ?>
											<span class="label label-success"><?php echo _("Ready") ?></span>
										<?php } else { ?>
											<span class="label label-warning"><?php echo _("Needs setup") ?></span>
										<?php } ?>
									</td>
									<td colspan="2">
										<form method="post" action="config.php?display=xrflowsoftphone&amp;view=enroll" class="form-inline" style="margin:0;white-space:nowrap;">
											<input type="hidden" name="enroll_ext" value="<?php echo htmlspecialchars((string) $ext) ?>"/>
											<label class="small" style="font-weight:normal;margin-right:0.75rem;">
												<input type="checkbox" name="enroll_vpn" value="1" <?php echo $vpnOn ? 'checked' : '' ?>/>
												<?php echo _("This desk uses VPN") ?>
											</label>
											<?php if ($ready) { ?>
												<button type="submit" name="xrflow_hub_action" value="enroll" class="btn btn-primary btn-sm"><?php echo _("Generate enroll code") ?></button>
											<?php } else { ?>
												<button type="submit" name="xrflow_hub_action" value="enroll_repair" class="btn btn-primary btn-sm"><?php echo _("Fix calling settings & enroll") ?></button>
											<?php } ?>
										</form>
									</td>
								</tr>
							<?php } ?>
							</tbody>
						</table>
						<script>
						(function () {
							var input = document.getElementById('xrflow-enroll-filter');
							var table = document.getElementById('xrflow-enroll-table');
							if (!input || !table) { return; }
							input.addEventListener('input', function () {
								var q = (input.value || '').toLowerCase();
								var rows = table.querySelectorAll('tbody tr[data-search]');
								for (var i = 0; i < rows.length; i++) {
									var hay = rows[i].getAttribute('data-search') || '';
									rows[i].style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
								}
							});
						})();
						</script>
					<?php } ?>
				</div>
			</div>
		</div>
	</div>
</div>
