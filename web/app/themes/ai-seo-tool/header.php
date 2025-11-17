<?php
/**
 * Theme header template.
 *
 * @package BBSEO
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class('bbseo-body'); ?>>
        <?php wp_body_open(); ?>
        <a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e('Skip to content', 'ai-seo-tool'); ?></a>
        <header class="bbseo-site-header">
            <div class="bbseo-site-header__inner">
                <div class="bbseo-site-branding">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="bbseo-site-logo">
                        <?php
                        if (has_custom_logo()) {
                            the_custom_logo();
                        } else {
                            bloginfo('name');
                        }
?>
                    </a>
                    <?php if (get_bloginfo('description')) : ?>
                        <p class="bbseo-site-tagline"><?php echo esc_html(get_bloginfo('description')); ?></p>
                    <?php endif; ?>
                </div>
                <?php if (has_nav_menu('primary')) : ?>
                    <nav class="bbseo-site-nav" aria-label="<?php esc_attr_e('Primary', 'ai-seo-tool'); ?>">
                        <?php wp_nav_menu([
    'theme_location' => 'primary',
    'container' => false,
    'menu_class' => 'bbseo-site-nav__list',
]); ?>
                    </nav>
                <?php endif; ?>
            </div>
        </header>
