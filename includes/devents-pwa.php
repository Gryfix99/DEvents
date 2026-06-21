<?php
/**
 * PWA (Progressive Web App).
 *
 * Faza A — instalowalność („Dodaj do ekranu głównego"): manifest + ikony + rejestracja SW.
 * Faza B — tryb offline: service worker z bezpieczną strategią cache:
 *   - nawigacje (HTML) → „network-first" z fallbackiem na stronę offline (świeże dane),
 *   - statyki (CSS/JS/fonty/obrazy) → „cache-first" z doładowaniem w tle (szybkie wejścia),
 *   - dane dynamiczne nie są cache'owane na sztywno.
 *
 * Manifest (/devents-manifest.json), SW (/devents-sw.js) i strona offline (/devents-offline)
 * są serwowane z KORZENIA witryny (rewrite + nagłówek Service-Worker-Allowed: /), więc
 * service worker obejmuje całą stronę (scope „/").
 *
 * Uwaga: wymaga „ładnych" bezpośrednich odnośników. Po wgraniu warto raz zapisać
 * Ustawienia → Bezpośrednie odnośniki.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DEVENTS_PWA_VERSION' ) ) {
	define( 'DEVENTS_PWA_VERSION', '1.1' );
}

/* ------------------------------------------------------------------ */
/* 1. Endpointy w korzeniu: SW, manifest, strona offline               */
/* ------------------------------------------------------------------ */
add_action( 'init', function () {
	add_rewrite_rule( '^devents-sw\.js$',         'index.php?devents_pwa=sw',       'top' );
	add_rewrite_rule( '^devents-manifest\.json$', 'index.php?devents_pwa=manifest', 'top' );
	add_rewrite_rule( '^devents-offline$',        'index.php?devents_pwa=offline',  'top' );

	// Jednorazowy flush po wgraniu/aktualizacji wersji PWA.
	if ( get_option( 'devents_pwa_rewrites' ) !== DEVENTS_PWA_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'devents_pwa_rewrites', DEVENTS_PWA_VERSION );
	}
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'devents_pwa';
	return $vars;
} );

/* ------------------------------------------------------------------ */
/* 2. Serwowanie manifestu, service workera i strony offline           */
/* ------------------------------------------------------------------ */
add_action( 'template_redirect', function () {
	$what = get_query_var( 'devents_pwa' );

	// Fallback bez zależności od reguł rewrite (działa, nawet gdy nie odświeżono
	// „Bezpośrednich odnośników"). template_redirect uruchamia się też dla 404.
	if ( ! $what && isset( $_SERVER['REQUEST_URI'] ) ) {
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		$path = untrailingslashit( $path );
		if ( substr( $path, -14 ) === '/devents-sw.js' ) {
			$what = 'sw';
		} elseif ( substr( $path, -22 ) === '/devents-manifest.json' ) {
			$what = 'manifest';
		} elseif ( substr( $path, -16 ) === '/devents-offline' ) {
			$what = 'offline';
		}
	}

	if ( ! $what ) {
		return;
	}

	if ( 'manifest' === $what ) {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( devents_pwa_manifest() );
		exit;
	}

	if ( 'sw' === $what ) {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo devents_pwa_service_worker();
		exit;
	}

	if ( 'offline' === $what ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo devents_pwa_offline_page();
		exit;
	}

	if ( 'events' === $what ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( devents_pwa_events_json() );
		exit;
	}
} );

/**
 * Lista przyszłych, opublikowanych wydarzeń do trybu offline (JSON).
 *
 * @return array
 */
function devents_pwa_events_json() {
	global $wpdb;
	$table = $wpdb->prefix . 'events_list';
	$now   = current_time( 'mysql' );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, title, start_datetime, address_city, image_url
		 FROM {$table}
		 WHERE verified = 1 AND start_datetime >= %s
		 ORDER BY start_datetime ASC
		 LIMIT 200",
		$now
	) );

	$events = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$events[] = array(
				'id'    => (int) $r->id,
				'title' => $r->title,
				'url'   => home_url( '/wydarzenia/' . sanitize_title( $r->title ) . '-' . (int) $r->id ),
				'date'  => $r->start_datetime,
				'city'  => $r->address_city,
				'image' => $r->image_url,
			);
		}
	}

	return array(
		'generated' => $now,
		'count'     => count( $events ),
		'events'    => $events,
	);
}

/**
 * Shortcode [devents_offline_events] — strona „pobierz przyszłe wydarzenia na urządzenie".
 */
add_shortcode( 'devents_offline_events', function () {
	wp_enqueue_script( 'devents-offline-events', DEW_PLUGIN_URL . 'assets/js/frontend/offline-events.js', array(), '1.0', true );
	wp_localize_script( 'devents-offline-events', 'devents_offline', array(
		'endpoint' => esc_url( add_query_arg( 'devents_pwa', 'events', home_url( '/' ) ) ),
	) );

	ob_start();
	?>
	<section class="devents-offline" data-devents-offline id="devents-main-content">
		<div class="devents-offline__head">
			<div>
				<h1 class="devents-offline__title-h">
					<span class="material-symbols-outlined" aria-hidden="true">cloud_download</span>
					<?php echo esc_html__( 'Wydarzenia offline', 'devents' ); ?>
				</h1>
				<p class="devents-offline__lead"><?php echo esc_html__( 'Zapisz przyszłe wydarzenia na urządzeniu i przeglądaj je bez internetu.', 'devents' ); ?></p>
			</div>
			<button type="button" class="devents-offline__save btn btn--primary" data-offline-save>
				<span class="material-symbols-outlined" aria-hidden="true">download</span>
				<?php echo esc_html__( 'Pobierz na urządzenie', 'devents' ); ?>
			</button>
		</div>
		<p class="devents-offline__status" data-offline-status role="status" aria-live="polite"></p>
		<div class="devents-offline__grid" data-offline-grid></div>
	</section>
	<?php
	return ob_get_clean();
} );

/**
 * Treść manifestu aplikacji.
 *
 * @return array
 */
function devents_pwa_manifest() {
	$icons = DEW_PLUGIN_URL . 'assets/images/pwa/';

	return array(
		'name'             => __( 'DEvents.pl — Dostępne wydarzenia', 'devents' ),
		'short_name'       => 'DEvents',
		'description'      => __( 'Dostępne wydarzenia dla osób Głuchych i słabosłyszących, w spektrum autyzmu oraz niewidomych i słabowidzących.', 'devents' ),
		'lang'             => 'pl',
		'dir'              => 'ltr',
		'start_url'        => home_url( '/' ),
		'scope'            => home_url( '/' ),
		'display'          => 'standalone',
		'orientation'      => 'portrait-primary',
		'background_color' => '#ffffff',
		'theme_color'      => '#00857c',
		'categories'       => array( 'events', 'lifestyle', 'social' ),
		'icons'            => array(
			array(
				'src'     => $icons . 'icon-192.png',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => $icons . 'icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
		),
	);
}

/**
 * Treść service workera (Faza B — bezpieczne cache'owanie).
 *
 * @return string
 */
function devents_pwa_service_worker() {
	$cache   = 'devents-cache-v3';
	$offline = esc_js( add_query_arg( 'devents_pwa', 'offline', home_url( '/' ) ) );
	$icon192 = esc_js( DEW_PLUGIN_URL . 'assets/images/pwa/icon-192.png' );

	$js  = "var CACHE='" . esc_js( $cache ) . "';\n";
	$js .= "var OFFLINE='" . $offline . "';\n";
	$js .= "var PRECACHE=[OFFLINE,'" . $icon192 . "'];\n";
	$js .= "self.addEventListener('install',function(e){e.waitUntil(caches.open(CACHE).then(function(c){return c.addAll(PRECACHE);}).then(function(){return self.skipWaiting();}).catch(function(){return self.skipWaiting();}));});\n";
	$js .= "self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(keys){return Promise.all(keys.filter(function(k){return k!==CACHE;}).map(function(k){return caches.delete(k);}));}).then(function(){return self.clients.claim();}));});\n";
	$js .= "self.addEventListener('fetch',function(e){var req=e.request;if(req.method!=='GET'){return;}var u;try{u=new URL(req.url);}catch(err){return;}if(u.origin!==self.location.origin){return;}";
	$js .= "if(req.mode==='navigate'){e.respondWith(fetch(req).catch(function(){return caches.match(OFFLINE);}));return;}";
	$js .= "if(/\\.(css|js|woff2?|ttf|otf|png|jpe?g|webp|svg|gif|ico)$/i.test(u.pathname)){e.respondWith(caches.match(req).then(function(hit){var net=fetch(req).then(function(res){if(res&&res.status===200){var copy=res.clone();caches.open(CACHE).then(function(c){c.put(req,copy);});}return res;}).catch(function(){return hit;});return hit||net;}));}";
	$js .= "});\n";

	// Web push: pokaż powiadomienie i otwórz link po kliknięciu.
	$js .= "self.addEventListener('push',function(e){var d={};try{d=e.data?e.data.json():{};}catch(err){d={};}var t=d.title||'DEvents';var o={body:d.body||'',icon:'" . $icon192 . "',badge:'" . $icon192 . "',tag:d.tag||'devents',data:{url:d.url||'/'}};e.waitUntil(self.registration.showNotification(t,o));});\n";
	$js .= "self.addEventListener('notificationclick',function(e){e.notification.close();var url=(e.notification.data&&e.notification.data.url)||'/';e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(cl){for(var i=0;i<cl.length;i++){if(cl[i].url===url&&'focus' in cl[i]){return cl[i].focus();}}if(clients.openWindow){return clients.openWindow(url);}}));});\n";

	return $js;
}

/**
 * Minimalna strona offline (fallback nawigacji bez sieci).
 *
 * @return string
 */
function devents_pwa_offline_page() {
	$site = esc_html( get_bloginfo( 'name' ) );

	return '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>Offline — ' . $site . '</title>'
		. '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f7f7f7;color:#1f2937;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}.b{max-width:340px;padding:24px}h1{color:#00857c;font-size:1.3rem;margin:0 0 10px}p{line-height:1.6;margin:0}button{margin-top:18px;background:#00857c;color:#fff;border:none;padding:11px 20px;border-radius:8px;font-size:1rem;cursor:pointer}</style>'
		. '</head><body><div class="b"><h1>Brak połączenia</h1><p>Wygląda na to, że jesteś offline. Sprawdź połączenie z internetem i spróbuj ponownie.</p><button type="button" onclick="location.reload()">Odśwież stronę</button></div></body></html>';
}

/* ------------------------------------------------------------------ */
/* 3. Tagi w <head> + rejestracja service workera                      */
/* ------------------------------------------------------------------ */
add_action( 'wp_head', function () {
	// Adresy w formie query (/?devents_pwa=…) — ZAWSZE trafiają do WordPressa,
	// niezależnie od „ładnych odnośników" i od tego, czy serwer kieruje pliki .js do PHP.
	$manifest = esc_url( add_query_arg( 'devents_pwa', 'manifest', home_url( '/' ) ) );
	$sw       = esc_url( add_query_arg( 'devents_pwa', 'sw', home_url( '/' ) ) );
	$apple    = esc_url( DEW_PLUGIN_URL . 'assets/images/pwa/apple-touch-180.png' );

	echo "\n<!-- DEvents PWA -->\n";
	echo '<link rel="manifest" href="' . $manifest . '">' . "\n";
	echo '<meta name="theme-color" content="#00857c">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . $apple . '">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="DEvents">' . "\n";
	echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register(' . wp_json_encode( $sw ) . ',{scope:"/"})["catch"](function(){});});}</script>' . "\n";
}, 5 );
