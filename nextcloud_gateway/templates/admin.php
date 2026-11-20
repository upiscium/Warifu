<?php
/** @var array $_ */
$mode = $_['mode'];
$limits = $_['limits'];
?>
<div id="nextcloud-gateway-admin" class="section">
	<h2>Warifu</h2>
	<p><?php p($l->t('Effective gateway policy:')); ?> <strong><?php p($mode); ?></strong></p>
	<form method="post" action="<?php p($_['action']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['formToken']); ?>">
		<label for="gateway-mode"><?php p($l->t('Gateway policy')); ?></label>
		<select id="gateway-mode" name="mode">
			<?php foreach (['all' => $l->t('All accounts: entire local home'), 'selected' => $l->t('Registered folders only'), 'off' => $l->t('Off')] as $value => $label): ?>
				<option value="<?php p($value); ?>" <?php if ($mode === $value) { print_unescaped('selected'); } ?>><?php p($label); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="submit" value="<?php p($l->t('Save')); ?>">
	</form>
	<h3><?php p($l->t('File and hashing limits')); ?></h3>
	<p><?php p($l->t('Limits are in whole GiB. The config.php setting and user-specific caps may result in lower effective limits.')); ?></p>
	<form method="post" action="<?php p($_['budgetAction']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['formToken']); ?>">
		<label for="gateway-file-gib"><?php p($l->t('Maximum file size (GiB)')); ?></label>
		<input type="number" id="gateway-file-gib" name="fileGiB" min="1" max="4294967295" step="1" required value="<?php p((string)$limits['fileGiB']); ?>">
		<label for="gateway-hash-gib"><?php p($l->t('Cumulative bytes hashed per request (GiB)')); ?></label>
		<input type="number" id="gateway-hash-gib" name="hashGiB" min="1" max="4294967295" step="1" required value="<?php p((string)$limits['hashGiB']); ?>">
		<input type="submit" value="<?php p($l->t('Save limits')); ?>">
	</form>
</div>
