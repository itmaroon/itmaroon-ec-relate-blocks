<?php

if (!defined('ABSPATH')) exit;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html__('Completing sign-in', 'itmaroon-ec-relate-blocks'); ?></title>
	<style>
		.itmar-shopify-callback {
			display: grid;
			min-height: 100vh;
			margin: 0;
			place-items: center;
			background: #f6f7f7;
			color: #1d2327;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		}
		.itmar-shopify-callback__status {
			max-width: 32rem;
			padding: 2rem;
			text-align: center;
		}
		.itmar-shopify-callback__spinner {
			width: 2.25rem;
			height: 2.25rem;
			margin: 0 auto 1.25rem;
			border: 3px solid #c3c4c7;
			border-top-color: #2271b1;
			border-radius: 50%;
			animation: itmar-shopify-callback-spin 0.8s linear infinite;
		}
		@keyframes itmar-shopify-callback-spin {
			to { transform: rotate(360deg); }
		}
		@media (prefers-reduced-motion: reduce) {
			.itmar-shopify-callback__spinner { animation: none; }
		}
	</style>
	<?php wp_head(); ?>
</head>
<body class="itmar-shopify-callback">
	<main class="itmar-shopify-callback__status" role="status" aria-live="polite">
		<div class="itmar-shopify-callback__spinner" aria-hidden="true"></div>
		<h1><?php echo esc_html__('Completing sign-in', 'itmaroon-ec-relate-blocks'); ?></h1>
		<p><?php echo esc_html__('Please wait while we securely return you to the site.', 'itmaroon-ec-relate-blocks'); ?></p>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
