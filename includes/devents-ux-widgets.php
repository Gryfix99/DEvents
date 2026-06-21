<?php
/**
 * Widżety UX front-end: baner cookies + zachęta do instalacji aplikacji (PWA) po ~5 min.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'devents-ux-widgets', DEW_PLUGIN_URL . 'assets/css/frontend/ux-widgets.css', array(), '1.0' );
	wp_enqueue_script( 'devents-ux-widgets', DEW_PLUGIN_URL . 'assets/js/frontend/ux-widgets.js', array(), '1.0', true );
	wp_localize_script( 'devents-ux-widgets', 'devents_ux', array(
		'i18n' => array(
			'install'      => __( 'Zainstaluj', 'devents' ),
			'later'        => __( 'Nie teraz', 'devents' ),
			'installTitle' => __( 'Dodaj DEvents do ekranu głównego', 'devents' ),
			'installText'  => __( 'Dodaj DEvents do ekranu głównego — szybki dostęp do dostępnych wydarzeń, także offline.', 'devents' ),
			'installIOS'   => __( 'Aby dodać DEvents do ekranu głównego: dotknij ikony Udostępnij, a następnie „Dodaj do ekranu głównego".', 'devents' ),
		),
	) );
} );

add_action( 'wp_footer', function () {
	$privacy = esc_url( home_url( '/polityka-prywatnosci/' ) );
	echo '<div id="devents-cookie" class="devents-cookie" role="region" aria-label="' . esc_attr__( 'Informacja o plikach cookie', 'devents' ) . '">';
	echo '<p class="devents-cookie__text">'
		. esc_html__( 'Używamy plików cookie i pamięci przeglądarki, aby serwis działał poprawnie (m.in. logowanie i Twoje preferencje dostępności).', 'devents' )
		. ' <a href="' . $privacy . '">' . esc_html__( 'Polityka prywatności', 'devents' ) . '</a>.</p>';
	echo '<button type="button" class="devents-cookie__accept">' . esc_html__( 'Akceptuję', 'devents' ) . '</button>';
	echo '</div>';
} );
