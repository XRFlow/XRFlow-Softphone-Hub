<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$status = $status ?? [];
$flash = $flash ?? null;
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub — License") ?></h1>
					<ul class="nav nav-tabs">
						<li><a href="?display=xrflowsoftphone&amp;view=dashboard"><?php echo _("Dashboard") ?></a></li>
						<li><a href="?display=xrflowsoftphone&amp;view=compliance"><?php echo _("WebRTC") ?></a></li>
						<li class="active"><a href="?display=xrflowsoftphone&amp;view=license"><?php echo _("License") ?></a></li>
					</ul>

					<?php if (is_array($flash)) { ?>
						<?php if (!empty($flash['ok'])) { ?>
						<div class="alert alert-success" style="margin-top:1rem;"><?php echo htmlspecialchars($flash['message'] ?? '') ?></div>
						<?php } else { ?>
						<div class="alert alert-danger" style="margin-top:1rem;"><?php echo htmlspecialchars($flash['error'] ?? '') ?></div>
						<?php } ?>
					<?php } ?>

					<div class="panel panel-default" style="margin-top:1rem;">
						<div class="panel-heading"><strong><?php echo _("Activate Hub key") ?></strong></div>
						<div class="panel-body">
							<p><?php echo _("Product code") ?>: <code>xrflow_softphone_hub</code>. <?php echo _("One activation per PBX. Desktop seats are a different product.") ?></p>
							<form method="post" action="config.php?display=xrflowsoftphone&amp;view=license">
								<input type="hidden" name="xrflow_hub_action" value="activate"/>
								<div class="form-group">
									<label for="hub_license_key"><?php echo _("Hub license key") ?></label>
									<input class="form-control" type="password" autocomplete="off" id="hub_license_key" name="hub_license_key" placeholder="XRFL1-…"/>
								</div>
								<button type="submit" class="btn btn-primary"><?php echo _("Activate") ?></button>
							</form>
							<p class="small text-muted" style="margin-top:1rem;">
								<?php echo _("14-day trial starts at install. After that, enroll and fleet APIs return HTTP 402 until a key is activated against xrflows.com.") ?>
							</p>
							<p class="small text-muted">
								<?php echo _("Instance") ?>: <code><?php echo htmlspecialchars($status['deployment_uuid'] ?? '') ?></code>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
