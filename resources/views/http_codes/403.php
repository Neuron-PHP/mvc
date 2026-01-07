<h2>403 - Access Forbidden</h2>
<p>
	You do not have permission to access <?= htmlspecialchars( $route ?? 'this resource', ENT_QUOTES, 'UTF-8' ) ?>.
</p>
<?php if( !empty( $resource ) ): ?>
<p>
	Resource: <?= htmlspecialchars( $resource, ENT_QUOTES, 'UTF-8' ) ?>
</p>
<?php endif; ?>
<?php if( !empty( $permission ) ): ?>
<p>
	Required permission: <?= htmlspecialchars( $permission, ENT_QUOTES, 'UTF-8' ) ?>
</p>
<?php endif; ?>