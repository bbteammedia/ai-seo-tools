<?php
/**
 * Minimal fallback index template for the Blackbird SEO Tool theme.
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
  <main class="container py-5">
    <h1 class="mb-3"><?php bloginfo('name'); ?></h1>
    <p>This theme focuses on REST endpoints and the /templates/report.php view. Add content here or create custom templates as needed.</p>
  </main>
  <?php wp_footer(); ?>
</body>
</html>
