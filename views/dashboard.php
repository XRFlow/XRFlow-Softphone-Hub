<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$status = $status ?? [];
$cp = $companyPresence ?? [];
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub") ?></h1>
					<p class="text-muted"><?php echo _("One-button enroll, fleet monitoring, seat pool, and Company Presence proxy. Desktop seats are sold separately.") ?></p>

					<ul class="nav nav-tabs">
						<li class="active"><a href="?display=xrflowsoftphone&amp;view=dashboard"><?php echo _("Dashboard") ?></a></li>
						<li><a href="?display=xrflowsoftphone&amp;view=compliance"><?php echo _("WebRTC") ?></a></li>
						<li><a href="?display=xrflowsoftphone&amp;view=enroll"><?php echo _("Enroll") ?></a></li>
						<li><a href="?display=xrflowsoftphone&amp;view=license"><?php echo _("License") ?></a></li>
					</ul>

					<?php if (!empty($status['in_trial'])) { ?>
					<div class="alert alert-info" style="margin-top:1rem;">
						<?php echo sprintf(_("Trial: %s day(s) left. Enroll and fleet APIs work until the trial ends."), (int) $status['trial_days_left']) ?>
					</div>
					<?php } elseif (empty($status['api_allowed'])) { ?>
					<div class="alert alert-warning" style="margin-top:1rem;">
						<?php echo _("Trial ended. Activate a Hub key on the License tab. This page still opens; enroll and fleet APIs return 402.") ?>
					</div>
					<?php } elseif (!empty($status['licensed'])) { ?>
					<div class="alert alert-success" style="margin-top:1rem;">
						<?php echo _("Hub licensed for this PBX.") ?>
						<?php if (!empty($status['key_prefix'])) { ?>
							<code><?php echo htmlspecialchars($status['key_prefix']) ?></code>
						<?php } ?>
					</div>
					<?php } ?>

					<div class="row" style="margin-top:1rem;">
						<div class="col-md-4">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("License") ?></strong></div>
								<div class="panel-body">
									<p><?php echo htmlspecialchars($status['reason'] ?? '') ?></p>
									<p class="small text-muted"><?php echo _("Deployment") ?>: <code><?php echo htmlspecialchars($status['deployment_uuid'] ?? '') ?></code></p>
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
										<li><?php echo _("WebRTC compliance + repair") ?></li>
										<li><?php echo _("Enroll QR / one-time token") ?></li>
										<li><?php echo _("Fleet heartbeat") ?></li>
										<li><?php echo _("Seat pool (org token)") ?></li>
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
