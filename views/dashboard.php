<?php
/**
 * Dashboard for XRFlow Softphone Hub.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$status = $status ?? [];
$cp = $companyPresence ?? [];
$view = $view ?? 'dashboard';
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub") ?></h1>
					<p class="text-muted"><?php echo _("Free companion: one-button enroll, WebRTC repair, fleet hooks, and Company Presence detect. Desktop seats are sold separately.") ?></p>

					<?php include __DIR__ . '/nav.php'; ?>

					<div class="alert alert-success" style="margin-top:1rem;">
						<?php echo _("This Hub is free software (GPLv3+). Enroll and fleet APIs are enabled. No Hub license key is required.") ?>
					</div>

					<div class="row" style="margin-top:1rem;">
						<div class="col-md-4">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Status") ?></strong></div>
								<div class="panel-body">
									<p><?php echo _("Free") ?> · <code>GPLv3+</code></p>
									<p class="small text-muted"><?php echo _("Deployment") ?>: <code><?php echo htmlspecialchars($status['deployment_uuid'] ?? '') ?></code></p>
									<?php if (!empty($status['ami_ready'])) { ?>
										<p><?php echo _("Asterisk AMI for desktops") ?>: <code><?php echo htmlspecialchars((string) ($status['ami_user'] ?? 'xrflow-hub')) ?></code>
											:<?php echo (int) ($status['ami_port'] ?? 5038) ?></p>
										<p class="small text-muted"><?php echo _("Enroll sends this AMI login to the app. Allowed from the office LAN and OpenVPN, not the public internet. Apply Config after the first install.") ?></p>
									<?php } else { ?>
										<p class="text-warning"><?php echo _("Asterisk AMI user is not ready. Open Settings → Asterisk Manager Users or reinstall this module, then Apply Config.") ?></p>
									<?php } ?>
									<?php if (!empty($status['oauth_ready'])) { ?>
										<p><?php echo _("PBX API (Contacts)") ?>: <code><?php echo htmlspecialchars((string) ($status['oauth_client_id'] ?? '')) ?></code></p>
										<p class="small text-muted"><?php echo _("Enroll sends this Admin → API Client ID and secret to the desktop for the company directory. Listed as “XRFlow Softphone Hub”.") ?></p>
									<?php } else { ?>
										<p class="text-warning"><?php echo _("PBX API client is not ready. Install/enable the API module (Admin → API), then reload this page.") ?></p>
									<?php } ?>
								</div>
							</div>
						</div>
						<div class="col-md-4">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Company Presence") ?></strong></div>
								<div class="panel-body">
									<p><?php echo !empty($cp['module_present']) ? _("Module installed") : _("Module not found") ?></p>
									<p><?php echo !empty($cp['presence_sync_port_open']) ? _("presence-sync port 3921 open on localhost") : _("presence-sync not reachable on 127.0.0.1:3921") ?></p>
									<p class="small text-muted"><?php echo _("Hub will proxy presence; it does not fork Company Presence or call Odoo.") ?></p>
								</div>
							</div>
						</div>
						<div class="col-md-4">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Coming next") ?></strong></div>
								<div class="panel-body">
									<ul class="small">
										<li><?php echo _("Fleet heartbeat") ?></li>
										<li><?php echo _("Seat pool (org token for desktop licenses)") ?></li>
										<li><?php echo _("Company Presence proxy") ?></li>
									</ul>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
