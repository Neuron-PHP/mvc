<h2>500</h2>
<p>
	Internal Server Error
</p>
<?php if( isset( $error ) && $error ): ?>
<p>
	<?= htmlspecialchars( $error ) ?>
</p>
<?php endif; ?>
