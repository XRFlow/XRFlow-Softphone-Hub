<?php
/**
 * Corporate logo upload for Enterprise Softphone seats.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $view ?? 'branding';
$flash = $flash ?? null;
$logoMeta = $logoMeta ?? [];
$logoPreview = $logoPreview ?? '';
$req = $logoRequirements ?? [];
$enabled = !empty($logoMeta['enabled']);
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub") ?></h1>
					<p class="text-muted"><?php echo _("Replace the XRFlow mark in the Softphone upper-left corner. This only works with this Hub and an Enterprise seat.") ?></p>
					<?php include __DIR__ . '/nav.php'; ?>

					<?php if (is_array($flash) && !empty($flash['message'])) { ?>
						<div class="alert alert-<?php echo !empty($flash['ok']) ? 'success' : 'danger' ?>" style="margin-top:1rem;">
							<?php echo htmlspecialchars((string) $flash['message']) ?>
						</div>
					<?php } ?>

					<div class="alert alert-info" style="margin-top:1rem;">
						<strong><?php echo _("Not a Softphone setting.") ?></strong>
						<?php echo htmlspecialchars((string) ($req['license'] ?? '')) ?>
					</div>

					<div class="row" style="margin-top:1rem;">
						<div class="col-md-7">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("File type, size, and DPI") ?></strong></div>
								<div class="panel-body">
									<ul>
										<?php foreach ((array) ($req['types'] ?? []) as $type) { ?>
											<li><code><?php echo htmlspecialchars((string) $type) ?></code></li>
										<?php } ?>
									</ul>
									<p><strong><?php echo _("PNG") ?>:</strong> <?php echo htmlspecialchars((string) ($req['png'] ?? '')) ?></p>
									<p><strong><?php echo _("SVG") ?>:</strong> <?php echo htmlspecialchars((string) ($req['svg'] ?? '')) ?></p>
									<p class="text-warning"><?php echo htmlspecialchars((string) ($req['rejected'] ?? '')) ?></p>
									<p class="small text-muted"><?php echo _("DPI is read from the PNG pHYs chunk (pixels per meter). If your exporter omits DPI, the upload is rejected — re-export at 144 DPI or higher, or use SVG.") ?></p>
								</div>
							</div>

							<form method="post" enctype="multipart/form-data" action="?display=xrflowsoftphone&amp;view=branding">
								<input type="hidden" name="xrflow_hub_action" value="save_logo">
								<div class="form-group">
									<label for="company_name"><?php echo _("Company name (optional, used as the logo alt text)") ?></label>
									<input class="form-control" id="company_name" name="company_name" maxlength="80" value="<?php echo htmlspecialchars((string) ($logoMeta['company_name'] ?? '')) ?>">
								</div>
								<div class="form-group">
									<label for="logo"><?php echo _("Logo file") ?></label>
									<input class="form-control" id="logo" name="logo" type="file" accept="image/png,image/svg+xml,.png,.svg" required>
								</div>
								<button type="submit" class="btn btn-primary"><?php echo $enabled ? _("Replace logo") : _("Save logo") ?></button>
							</form>
							<?php if ($enabled) { ?>
								<form method="post" action="?display=xrflowsoftphone&amp;view=branding" style="margin-top:0.75rem;">
									<input type="hidden" name="xrflow_hub_action" value="clear_logo">
									<button type="submit" class="btn btn-default"><?php echo _("Remove logo") ?></button>
								</form>
							<?php } ?>
						</div>
						<div class="col-md-5">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Current logo") ?></strong></div>
								<div class="panel-body">
									<?php if ($enabled && $logoPreview !== '') { ?>
										<img src="<?php echo htmlspecialchars($logoPreview) ?>" alt="" width="96" height="96" style="width:96px;height:96px;object-fit:contain;background:#fff;border:1px solid #ddd;border-radius:8px;">
										<p style="margin-top:0.75rem;">
											<?php echo htmlspecialchars((string) ($logoMeta['content_type'] ?? '')) ?>
											· <?php echo (int) ($logoMeta['width'] ?? 0) ?>×<?php echo (int) ($logoMeta['height'] ?? 0) ?>
											<?php if (!empty($logoMeta['dpi'])) { ?>
												· <?php echo (int) $logoMeta['dpi'] ?> DPI
											<?php } else { ?>
												· <?php echo _("SVG (no DPI)") ?>
											<?php } ?>
											· <?php echo (int) round(((int) ($logoMeta['bytes'] ?? 0)) / 1024) ?> KB
										</p>
									<?php } else { ?>
										<p class="text-muted"><?php echo _("No corporate logo. Enrolled Enterprise seats show the XRFlow mark.") ?></p>
									<?php } ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
