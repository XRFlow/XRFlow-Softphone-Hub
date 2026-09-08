<?php
/**
 * About / open-source page for XRFlow Softphone Hub.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$status = $status ?? [];
$view = $view ?? 'about';
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub — About") ?></h1>
					<?php include __DIR__ . '/nav.php'; ?>

					<div class="panel panel-default" style="margin-top:1rem;">
						<div class="panel-heading"><strong><?php echo _("Free software") ?></strong></div>
						<div class="panel-body">
							<p><?php echo _("This FreePBX module is free software under the GNU GPL v3 or later. There is no Hub license key, no trial, and no paid SKU for the module itself.") ?></p>
							<p><?php echo _("XRFlow Softphone desktop seats are a separate commercial product. Enroll still works without a Hub key; each desk still needs a desktop seat unless you are evaluating the client.") ?></p>
							<ul>
								<li><?php echo _("License") ?>: <code>GPLv3+</code> — <a href="https://www.gnu.org/licenses/gpl-3.0.txt" target="_blank" rel="noopener">gnu.org/licenses/gpl-3.0.txt</a></li>
								<li><?php echo _("Source") ?>: <a href="https://github.com/XRFlow/XRFlow-Softphone-Hub" target="_blank" rel="noopener">github.com/XRFlow/XRFlow-Softphone-Hub</a></li>
								<li><?php echo _("Instance") ?>: <code><?php echo htmlspecialchars($status['deployment_uuid'] ?? '') ?></code></li>
							</ul>
							<p class="small text-muted mb-0">
								<?php echo _("Module signing (module.sig) uses a developer GPG key. After Sangoma signs that key, Module Admin will treat official tarballs as signed. Unsigned third-party installs may show a warning until then.") ?>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
