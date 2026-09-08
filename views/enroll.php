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
$view = $view ?? 'enroll';
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub — Enroll") ?></h1>
					<?php include __DIR__ . '/nav.php'; ?>

					<?php if (is_array($flash) && empty($flash['ok']) && !empty($flash['error'])) { ?>
						<div class="alert alert-danger" style="margin-top:1rem;"><?php echo htmlspecialchars($flash['error']) ?></div>
					<?php } ?>
					<?php if (is_array($flash) && !empty($flash['ok']) && !empty($flash['token'])) { ?>
						<div class="alert alert-success" style="margin-top:1rem;">
							<p><?php echo _("Copy this once — 15 minutes, one use.") ?></p>
							<p><strong><?php echo _("Deep link") ?>:</strong><br/>
								<code style="word-break:break-all;user-select:all"><?php echo htmlspecialchars($flash['deep_link']) ?></code></p>
							<p><strong><?php echo _("Token") ?>:</strong>
								<code style="user-select:all"><?php echo htmlspecialchars($flash['token']) ?></code></p>
							<p><strong><?php echo _("HTTPS") ?>:</strong><br/>
								<code style="word-break:break-all"><?php echo htmlspecialchars($flash['https_link']) ?></code></p>
							<p class="small"><?php echo _("Paste the token in XRFlow Softphone → Use Hub enroll code. SIP secret is delivered only on redeem over TLS.") ?></p>
						</div>
					<?php } ?>

					<form method="post" action="config.php?display=xrflowsoftphone&amp;view=enroll" style="margin-top:1rem;">
						<input type="hidden" name="xrflow_hub_action" value="enroll"/>
						<div class="form-group">
							<label for="enroll_ext"><?php echo _("Extension") ?></label>
							<select class="form-control" id="enroll_ext" name="enroll_ext">
								<?php foreach ($exts as $ext => $name) { ?>
									<option value="<?php echo htmlspecialchars($ext) ?>"><?php echo htmlspecialchars($ext . ' — ' . $name) ?></option>
								<?php } ?>
							</select>
						</div>
						<label class="small">
							<input type="checkbox" name="enroll_override" value="1"/>
							<?php echo _("Override WebRTC compliance (logged). Prefer Repair on the WebRTC tab.") ?>
						</label>
						<div style="margin-top:0.75rem;">
							<button type="submit" class="btn btn-primary"><?php echo _("Generate enroll link") ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
