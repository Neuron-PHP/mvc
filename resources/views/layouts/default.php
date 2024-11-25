<html>
	<head></head>
	<title><?= isset( $Title ) ? $Title : '' ?></title>
	<body>
		<h1>Test Layout</h1>
		<?php
		require_once( $View );
		?>
	</body>
</html>
