<?php
/**
 * Desktop seat pool. Org token only — no xrflows.com user password.
 *
 * Copyright (C) 2026 XRFlow
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$view = $view ?? 'seats';
$flash = $flash ?? null;
$seatPool = is_array($seatPool ?? null) ? $seatPool : [];
$grants = is_array($seatPool['grants'] ?? null) ? $seatPool['grants'] : [];
$term = static function ($row) {
	if (!empty($row['perpetual'])) {
		return _('Perpetual');
	}
	$until = (string) ($row['valid_until'] ?? '');
	if ($until === '') {
		return (string) ($row['tier'] ?? '');
	}
	$stamp = strtotime($until);
	$when = $stamp ? date('Y-m-d', $stamp) : $until;
	return sprintf(_('Until %s'), $when);
};
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display no-border">
					<h1><?php echo _("XRFlow Softphone Hub") ?></h1>
					<p class="text-muted"><?php echo _("Pull this company's Softphone seats and subscription status from xrflows.com. Paste the org token once. Hub does not store an xrflows.com password and does not create licenses.") ?></p>
					<?php include __DIR__ . '/nav.php'; ?>

					<?php if (is_array($flash) && !empty($flash['message'])) { ?>
						<div class="alert alert-<?php echo !empty($flash['ok']) ? 'success' : 'danger' ?>" style="margin-top:1rem;">
							<?php echo htmlspecialchars((string) $flash['message']) ?>
							<?php if (!empty($flash['license_key'])) { ?>
								<div class="form-group" style="margin-top:0.75rem;margin-bottom:0;">
									<label for="xrflow-revealed-key"><?php echo _("License key") ?></label>
									<input id="xrflow-revealed-key" class="form-control" readonly autocomplete="off" spellcheck="false" value="<?php echo htmlspecialchars((string) $flash['license_key']) ?>">
								</div>
							<?php } ?>
						</div>
					<?php } ?>

					<?php if (!empty($seatPool['message']) && (empty($flash['message']) || ($flash['message'] ?? '') !== $seatPool['message'])) { ?>
						<div class="alert alert-warning" style="margin-top:1rem;">
							<?php echo htmlspecialchars((string) $seatPool['message']) ?>
							<?php if (!empty($seatPool['stale'])) { ?>
								<?php echo _(" Showing the last successful sync.") ?>
							<?php } ?>
						</div>
					<?php } ?>

					<div class="row" style="margin-top:1rem;">
						<div class="col-md-6">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Org token") ?></strong></div>
								<div class="panel-body">
									<p class="small text-muted"><?php echo _("On xrflows.com open License Authority → Org Tokens, generate a token for this customer, and paste it here. The token can list, assign, reveal once, and deactivate seats. It cannot issue new keys.") ?></p>
									<form method="post" action="?display=xrflowsoftphone&amp;view=seats" autocomplete="off">
										<input type="hidden" name="xrflow_hub_action" value="save_org">
										<div class="form-group">
											<label for="org_api_base"><?php echo _("License service") ?></label>
											<input class="form-control" id="org_api_base" name="org_api_base" value="<?php echo htmlspecialchars((string) ($seatPool['base'] ?? 'https://xrflows.com')) ?>">
										</div>
										<div class="form-group">
											<label for="org_token"><?php echo _("Org token") ?></label>
											<input class="form-control" id="org_token" name="org_token" type="password" autocomplete="new-password" placeholder="<?php echo !empty($seatPool['token_saved']) ? htmlspecialchars(sprintf(_('Saved · ends in %s. Leave blank to keep it.'), (string) ($seatPool['token_hint'] ?? ''))) : '' ?>">
										</div>
										<button type="submit" class="btn btn-primary"><?php echo _("Save") ?></button>
									</form>
									<?php if (!empty($seatPool['token_saved'])) { ?>
										<form method="post" action="?display=xrflowsoftphone&amp;view=seats" style="margin-top:0.75rem;">
											<input type="hidden" name="xrflow_hub_action" value="clear_org">
											<button type="submit" class="btn btn-default"><?php echo _("Remove token") ?></button>
										</form>
									<?php } ?>
								</div>
							</div>
						</div>
						<div class="col-md-6">
							<div class="panel panel-default">
								<div class="panel-heading"><strong><?php echo _("Subscription") ?></strong></div>
								<div class="panel-body">
									<?php if (empty($seatPool['token_saved'])) { ?>
										<p class="text-muted"><?php echo _("Save an org token to sync seats.") ?></p>
									<?php } else { ?>
										<p><?php echo _("Unused seats") ?>: <strong><?php echo htmlspecialchars((string) ($seatPool['unused_capacity'] ?? 0)) ?></strong></p>
										<p><?php echo _("Grants") ?>: <strong><?php echo (int) ($seatPool['count'] ?? count($grants)) ?></strong></p>
										<?php if (!empty($seatPool['fetched_at'])) { ?>
											<p class="small text-muted"><?php echo sprintf(_("Last sync %s"), date('Y-m-d H:i', (int) $seatPool['fetched_at'])) ?></p>
										<?php } ?>
										<form method="post" action="?display=xrflowsoftphone&amp;view=seats">
											<input type="hidden" name="xrflow_hub_action" value="org_refresh">
											<button type="submit" class="btn btn-default"><?php echo _("Sync now") ?></button>
										</form>
									<?php } ?>
								</div>
							</div>
						</div>
					</div>

					<?php if ($grants) { ?>
						<div class="panel panel-default">
							<div class="panel-heading"><strong><?php echo _("Seats") ?></strong></div>
							<div class="panel-body table-responsive">
								<table class="table table-striped table-condensed">
									<thead>
										<tr>
											<th><?php echo _("Grant") ?></th>
											<th><?php echo _("Status") ?></th>
											<th><?php echo _("Term") ?></th>
											<th><?php echo _("Seats") ?></th>
											<th><?php echo _("Extension") ?></th>
											<th><?php echo _("Key") ?></th>
											<th></th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($grants as $row) {
										$gid = (int) ($row['grant_id'] ?? 0);
										$used = (int) ($row['used_activations'] ?? 0);
										$max = (int) ($row['max_activations'] ?? 0);
										$seatLabel = $max > 0 ? ($used . ' / ' . $max) : (string) $used;
										$unused = $row['unused_capacity'] ?? null;
										if ($unused !== null && $unused !== '') {
											$seatLabel .= ' · ' . sprintf(_('%s free'), (string) $unused);
										}
									?>
										<tr>
											<td><code><?php echo $gid ?></code><br><span class="small text-muted"><?php echo htmlspecialchars((string) ($row['tier'] ?? '')) ?></span></td>
											<td><?php echo htmlspecialchars((string) ($row['state'] ?? '')) ?></td>
											<td><?php echo htmlspecialchars($term($row)) ?></td>
											<td><?php echo htmlspecialchars($seatLabel) ?></td>
											<td>
												<form method="post" action="?display=xrflowsoftphone&amp;view=seats" class="form-inline">
													<input type="hidden" name="xrflow_hub_action" value="org_assign">
													<input type="hidden" name="grant_id" value="<?php echo $gid ?>">
													<input class="form-control input-sm" name="assign_extension" value="<?php echo htmlspecialchars((string) ($row['assigned_extension'] ?? '')) ?>" maxlength="20" style="width:7rem;">
													<button type="submit" class="btn btn-default btn-sm"><?php echo _("Save") ?></button>
												</form>
											</td>
											<td>
												<?php if (!empty($row['has_pending_key'])) { ?>
													<form method="post" action="?display=xrflowsoftphone&amp;view=seats">
														<input type="hidden" name="xrflow_hub_action" value="org_reveal">
														<input type="hidden" name="grant_id" value="<?php echo $gid ?>">
														<button type="submit" class="btn btn-warning btn-sm"><?php echo _("Reveal once") ?></button>
													</form>
												<?php } else { ?>
													<code><?php echo htmlspecialchars((string) ($row['key_prefix'] ?? '')) ?></code>
												<?php } ?>
											</td>
											<td>
												<form method="post" action="?display=xrflowsoftphone&amp;view=seats" style="display:inline;">
													<input type="hidden" name="xrflow_hub_action" value="org_deactivate">
													<input type="hidden" name="grant_id" value="<?php echo $gid ?>">
													<button type="submit" class="btn btn-default btn-sm" onclick="return confirm('Deactivate every active seat on this grant?');"><?php echo _("Deactivate") ?></button>
												</form>
												<form method="post" action="?display=xrflowsoftphone&amp;view=seats" style="display:inline;">
													<input type="hidden" name="xrflow_hub_action" value="org_reactivate">
													<input type="hidden" name="grant_id" value="<?php echo $gid ?>">
													<button type="submit" class="btn btn-default btn-sm"><?php echo _("Reactivate") ?></button>
												</form>
											</td>
										</tr>
									<?php } ?>
									</tbody>
								</table>
								<p class="small text-muted"><?php echo _("Assign records which extension should use the grant. Reveal shows a pending key one time and does not store it here. Deactivate frees seats on that grant; it does not delete the subscription.") ?></p>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
	</div>
</div>
