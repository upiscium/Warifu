<?php
/** @var array $_ */
$mode = $_['mode'];
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
</div>
