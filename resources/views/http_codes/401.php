<h2>401 - Authentication Required</h2>
<p>
	You must authenticate to access <?= htmlspecialchars( $route ?? 'this resource', ENT_QUOTES, 'UTF-8' ) ?>.
</p>
<?php if( !empty( $realm ) ): ?>
<p>
	Authentication realm: <?= htmlspecialchars( $realm, ENT_QUOTES, 'UTF-8' ) ?>
</p>
<?php endif; ?>