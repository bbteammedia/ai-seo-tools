<?php
/**
 * Theme footer template.
 *
 * @package BBSEO
 */
?>
        <footer class="bbseo-site-footer">
            <div class="bbseo-site-footer__inner">
                <p>&copy; <?php echo esc_html(date_i18n('Y')); ?> <?php bloginfo('name'); ?></p>
            </div>
        </footer>
        <?php wp_footer(); ?>
    </body>
</html>
