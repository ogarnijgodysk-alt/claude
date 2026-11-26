<?php
/**
 * Pusta strona Lejka: tylko treść strony, bez nagłówka i stopki motywu.
 */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php if ( ! wp_is_block_theme() ) : // Motywy blokowe dodają viewport i tytuł same. ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
<?php endif; ?>
<?php wp_head(); ?>
<style>body.lejek-blank{margin:0}</style>
</head>
<body <?php body_class( 'lejek-blank' ); ?>>
<?php
wp_body_open();
while ( have_posts() ) {
	the_post();
	the_content();
}
wp_footer();
?>
</body>
</html>
