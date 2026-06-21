<?php
/**
 * Pasek ułatwień dostępu (front-end): rozmiar tekstu (A / A+ / A++) i wysoki kontrast.
 * Stan trzymany w localStorage; klasy nakładane na <html>. Skrypt no-FOUC w <head>
 * nakłada zapisane ustawienia zanim strona się wyrenderuje (bez „skoku" tekstu).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* No-FOUC: zastosuj zapisane ustawienia natychmiast (priorytet 1). */
add_action( 'wp_head', function () {
	echo "<script>(function(){try{var d=document.documentElement,f=localStorage.getItem('devents_fs'),h=localStorage.getItem('devents_hc');if(f==='1')d.classList.add('devents-fs-1');if(f==='2')d.classList.add('devents-fs-2');if(h==='1')d.classList.add('devents-high-contrast');}catch(e){}})();</script>\n";
}, 1 );

/* Style + skrypt paska. */
add_action( 'wp_enqueue_scripts', function () {
	$v = '1.0';
	wp_enqueue_style( 'devents-a11y-toolbar', DEW_PLUGIN_URL . 'assets/css/frontend/a11y-toolbar.css', array(), $v );
	wp_enqueue_script( 'devents-a11y-toolbar', DEW_PLUGIN_URL . 'assets/js/frontend/a11y-toolbar.js', array(), $v, true );
} );

/* Widget w stopce. HTML w pojedynczych cudzysłowach (atrybuty „ używają podwójnych). */
add_action( 'wp_footer', function () {
	$svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="4" r="1.6" fill="currentColor" stroke="none"></circle><path d="M5 8h14"></path><path d="M12 8v6"></path><path d="M9 21l3-7 3 7"></path></svg>';

	echo '<div id="devents-a11y" class="devents-a11y">'
		. '<button type="button" class="devents-a11y__toggle" aria-expanded="false" aria-controls="devents-a11y-panel" aria-label="' . esc_attr__( 'Ułatwienia dostępu: rozmiar tekstu i kontrast', 'devents' ) . '" title="' . esc_attr__( 'Ułatwienia dostępu', 'devents' ) . '">' . $svg . '</button>'
		. '<div id="devents-a11y-panel" class="devents-a11y__panel" role="group" aria-label="' . esc_attr__( 'Ustawienia dostępności', 'devents' ) . '" hidden>'
		. '<p class="devents-a11y__title">' . esc_html__( 'Ułatwienia dostępu', 'devents' ) . '</p>'
		. '<div class="devents-a11y__group">'
		. '<p class="devents-a11y__label" id="devents-a11y-fs-label">' . esc_html__( 'Rozmiar tekstu', 'devents' ) . '</p>'
		. '<div class="devents-a11y__row" role="group" aria-labelledby="devents-a11y-fs-label">'
		. '<button type="button" class="devents-a11y__btn" data-fs="0" aria-pressed="true" aria-label="' . esc_attr__( 'Domyślny rozmiar tekstu', 'devents' ) . '">A</button>'
		. '<button type="button" class="devents-a11y__btn" data-fs="1" aria-pressed="false" aria-label="' . esc_attr__( 'Większy tekst', 'devents' ) . '">A+</button>'
		. '<button type="button" class="devents-a11y__btn" data-fs="2" aria-pressed="false" aria-label="' . esc_attr__( 'Największy tekst', 'devents' ) . '">A++</button>'
		. '</div></div>'
		. '<div class="devents-a11y__group">'
		. '<button type="button" class="devents-a11y__btn devents-a11y__btn--block" data-hc aria-pressed="false">' . esc_html__( 'Wysoki kontrast', 'devents' ) . '</button>'
		. '</div>'
		. '<button type="button" class="devents-a11y__btn devents-a11y__btn--block" data-reset>' . esc_html__( 'Resetuj ułatwienia', 'devents' ) . '</button>'
		. '</div></div>';
} );
