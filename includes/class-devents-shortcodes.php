<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 * KLASA 1: DEvents_Twig_Helper — v13.0 (FIXED)
 * Poprawki: usunięta podwójna inicjalizacja, $twig → $this->twig,
 * dodane brakujące helpery build_query_with/without/without_value
 * ============================================================================
 */
class DEvents_Twig_Helper {
    private static $instance = null;
    public $twig;

    private function __construct() {
        // 1. Autoload NAJPIERW — przed jakimkolwiek użyciem klas Twig
        $autoload_path = DEW_PLUGIN_PATH . 'vendor/autoload.php';
        if ( file_exists( $autoload_path ) ) {
            require_once $autoload_path;
        }

        if ( ! class_exists( '\Twig\Loader\FilesystemLoader' ) ) {
            error_log( 'DEvents Fatal Error: Twig library not found. Please run "composer install".' );
            return;
        }

        // 2. JEDNA inicjalizacja Twig (nie dwie)
        $loader     = new \Twig\Loader\FilesystemLoader( DEW_PLUGIN_PATH . 'templates' );
        $this->twig = new \Twig\Environment( $loader, [
            'cache'       => false,
            'debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'auto_reload' => defined( 'WP_DEBUG' ) && WP_DEBUG,
        ] );

        // 3. Timezone
        if ( class_exists( '\Twig\Extension\CoreExtension' ) ) {
            $this->twig->getExtension( \Twig\Extension\CoreExtension::class )
                       ->setTimezone( get_option( 'timezone_string' ) ?: 'Europe/Warsaw' );
        }

        // 4. i18n
        if ( class_exists( 'DEvents_I18n' ) ) {
            DEvents_I18n::register_twig_functions( $this->twig );
        }

        // 5. WordPress functions
        $this->register_wp_functions();

        // 6. v13.1: Globalne zmienne Twiga
        // OPÓŹNIAMY wykonanie wp_create_nonce do hooka 'init', 
        // ponieważ w momencie konstruowania klasy ta funkcja WP jeszcze nie istnieje.
        add_action( 'init', function() {
            if ( $this->twig ) {
                $this->twig->addGlobal( 'contact_nonce', wp_create_nonce( 'devents_contact_form_action' ) );
            }
        });
    }

    private function register_wp_functions() {
        // Zabezpieczenie — jeśli Twig nie został zainicjalizowany
        if ( ! $this->twig ) {
            return;
        }

        // --- Standardowe funkcje WordPress ---
        $this->twig->addFunction( new \Twig\TwigFunction( 'home_url', 'home_url' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'do_shortcode', 'do_shortcode' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'wp_logout_url', 'wp_logout_url' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'wp_create_nonce', 'wp_create_nonce' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'wp_nonce_url', 'wp_nonce_url' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'admin_url', 'admin_url' ) );

        $this->twig->addFunction( new \Twig\TwigFunction( 'wp_nonce_field', function( $action = -1, $name = '_wpnonce', $referer = true, $echo = false ) {
            return wp_nonce_field( $action, $name, $referer, $echo );
        }, [ 'is_safe' => [ 'html' ] ] ) );

        $this->twig->addFunction( new \Twig\TwigFunction( 'get_userdata', 'get_userdata' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_status_info', 'get_status_info_helper' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_user_badge', 'devents_get_user_badge_info' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_pagenum_link', 'get_pagenum_link' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'paginate_links', 'paginate_links' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_query_var', 'get_query_var' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_edit_user_link', 'get_edit_user_link' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'is_user_logged_in', 'is_user_logged_in' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'current_user_can', 'current_user_can' ) );

        // --- Filtry ---
        $this->twig->addFilter( new \Twig\TwigFilter( 'get_user_meta', 'get_user_meta' ) );
        $this->twig->addFunction( new \Twig\TwigFunction( 'get_user_meta', 'get_user_meta' ) );
        $this->twig->addFilter( new \Twig\TwigFilter( 'wpautop', 'wpautop' ) );

        $this->twig->addFilter( new \Twig\TwigFilter( 'json_decode', function( $data ) {
            if ( is_array( $data ) || is_object( $data ) ) {
                return $data;
            }
            if ( empty( $data ) ) {
                return [];
            }
            return json_decode( $data, true );
        } ) );

        // --- Helpery URL dla chipów filtrów (search page) ---
        $this->register_query_helpers();
    }

    /**
     * Rejestruje funkcje Twig do manipulacji query stringiem.
     * Używane w event-search.twig do budowania linków chipów filtrów.
     */
    private function register_query_helpers() {

        /**
         * build_query_without('category')
         * Zwraca query string BEZ podanego parametru.
         * Użycie w Twig: {{ current_base_url ~ build_query_without('category') }}
         */
        $this->twig->addFunction( new \Twig\TwigFunction( 'build_query_without', function ( $key_to_remove ) {
            $params = $_GET;

            if ( is_array( $key_to_remove ) ) {
                foreach ( $key_to_remove as $key ) {
                    unset( $params[ $key ] );
                }
            } else {
                unset( $params[ $key_to_remove ] );
            }

            // Usuń też paginację przy zmianie filtrów
            unset( $params['page'] );

            return empty( $params ) ? '' : '?' . http_build_query( $params );
        } ) );

        /**
         * build_query_without_value('accessibility', 5)
         * Usuwa JEDNĄ wartość z parametru tablicowego (accessibility[]=5).
         * Użycie w Twig: {{ current_base_url ~ build_query_without_value('accessibility', acc_id) }}
         */
        $this->twig->addFunction( new \Twig\TwigFunction( 'build_query_without_value', function ( $key, $value_to_remove ) {
            $params = $_GET;

            if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
                $params[ $key ] = array_values( array_filter(
                    $params[ $key ],
                    function ( $v ) use ( $value_to_remove ) {
                        return (string) $v !== (string) $value_to_remove;
                    }
                ) );

                // Jeśli tablica pusta po usunięciu — usuń cały klucz
                if ( empty( $params[ $key ] ) ) {
                    unset( $params[ $key ] );
                }
            }

            // Usuń paginację
            unset( $params['page'] );

            return empty( $params ) ? '' : '?' . http_build_query( $params );
        } ) );

        /**
         * build_query_with('page', 3)
         * Dodaje/nadpisuje parametr w query stringu.
         * Użycie w Twig: {{ current_base_url ~ build_query_with('page', page) }}
         */
        $this->twig->addFunction( new \Twig\TwigFunction( 'build_query_with', function ( $key, $value ) {
            $params = $_GET;

            if ( $value === null || $value === '' ) {
                unset( $params[ $key ] );
            } else {
                $params[ $key ] = $value;
            }

            return empty( $params ) ? '' : '?' . http_build_query( $params );
        } ) );
    }

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render( $template_name, $data = [] ) {
        if ( ! $this->twig ) {
            return 'Błąd: Środowisko Twig nie jest dostępne.';
        }
        try {
            $data['user'] = wp_get_current_user();
            return $this->twig->render( $template_name . '.twig', $data );
        } catch ( \Twig\Error\Error $e ) {
            error_log( "Twig Template Error in '" . $template_name . "': " . $e->getMessage() );
            return ( defined( 'WP_DEBUG' ) && WP_DEBUG )
                ? 'Błąd szablonu: ' . esc_html( $e->getMessage() )
                : '';
        }
    }
}

/**
 * ============================================================================
 * KLASA: DEvents_Shortcodes — v13.0 (FIXED)
 * Poprawki: current_base_url, brakujące filtry (sort, delivery_mode),
 * paginacja w wyszukiwarce, selected_acc_languages, author_role,
 * XSS w lokalizacji, inline HTML → Twig
 * ============================================================================
 */
class DEvents_Shortcodes {
    private $twig_helper;

    public function __construct() {
        $this->twig_helper = DEvents_Twig_Helper::get_instance();
        $this->register_shortcodes();
        $this->handle_forms_post_requests();

        add_action( 'wp_ajax_nopriv_devents_process_invite_creation', [ $this, 'process_invite_submission' ] );
        add_action( 'wp_ajax_devents_process_invite_creation', [ $this, 'process_invite_submission' ] );

        // --- WYSZUKIWARKA AJAX ---
        add_action( 'wp_ajax_nopriv_devents_ajax_search', [ $this, 'devents_ajax_search_callback' ] );
        add_action( 'wp_ajax_devents_ajax_search', [ $this, 'devents_ajax_search_callback' ] );
    }

    private function register_shortcodes() {
        $shortcodes = [
            'register_form'          => 'render_form',
            'login_form'             => 'render_form',
            'devents_password_reset' => 'render_password_reset_form',
            'my_account'             => 'render_my_account',
            'publish_event'          => 'render_publish_event_form',
            'wydarzenie'             => 'render_single_event',
            'homepage'               => 'render_homepage',
            'my_events_list'         => 'render_my_events_list',
            'event_search'           => 'render_event_search',
            'organizer_search'       => 'render_organizer_search',
            'devents_user_panel'     => 'render_user_panel',
            'devents_admin_panel'    => 'render_admin_panel',
            'lista_aktualnosci'      => 'render_news_list_shortcode',
            'my_favorites_list'      => 'render_my_favorites_list',
            'my_reviews_list'        => 'render_my_reviews_list',
            'my_subscriptions_list'  => 'render_my_subscriptions_list',
            'devents_contact_form'   => 'render_contact_form',
            'devents_accept_invite'  => 'render_accept_invite_form',
            'devents_lang_switcher'  => 'render_lang_switcher',
            'devents_cennik'         => 'render_cennik',
            'devents_ankieta'        => 'render_satisfaction_survey',
        ];

        foreach ( $shortcodes as $tag => $method ) {
            add_shortcode( $tag, [ $this, $method ] );
        }
    }

    public function handle_forms_post_requests() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( isset( $_POST['devents_action'] ) ) {
            $auth_handler = DEW_PLUGIN_PATH . 'includes/handlers/auth-handler.php';

            if ( file_exists( $auth_handler ) ) {
                require_once $auth_handler;

                if ( function_exists( 'devents_handle_auth_actions' ) ) {
                    devents_handle_auth_actions();
                }
            }
        }
    }


    /* =====================================================================
       HELPERY DANYCH
       ===================================================================== */

    private function get_settings_safe( $type ) {
        global $wpdb;
        $table_settings = $wpdb->prefix . 'events_settings';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_settings'" ) != $table_settings ) {
            return $this->get_fallback_values( $type );
        }

        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT setting_value FROM {$table_settings} WHERE setting_type = %s ORDER BY setting_value ASC",
            $type
        ) );

        return ! empty( $results ) ? $results : $this->get_fallback_values( $type );
    }

    private function get_fallback_values( $type ) {
        switch ( $type ) {
            case 'event_category':
                return [ 'Film', 'Inne', 'Koncert', 'Konferencja', 'Performens',
                    'Spacer', 'Spektakl', 'Spotkanie', 'Warsztaty', 'Wykład',
                    'Wystawa', 'Zwiedzanie', 'Sport', 'Wernisaż/Finisaż' ];
            case 'event_method':
                return [ 'Na żywo', 'Hybrydowo', 'Online' ];
            case 'video_category':
                return [ 'Film', 'Inne', 'Konferencja', 'Podcast', 'Spacer',
                    'Spektakl', 'Spotkanie autorskie', 'Warsztaty', 'Wykład', 'Zaproszenie' ];
            default:
                return [];
        }
    }

    public function get_sign_languages(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'devents_sign_languages';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
        return $wpdb->get_results(
            "SELECT country_code, country_name, sl_code, sl_name, spoken_code, spoken_name
             FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC"
        ) ?: [];
    }

    public function get_unique_sign_languages(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'devents_sign_languages';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
        return $wpdb->get_results(
            "SELECT sl_code, MIN(sl_name) as sl_name, MIN(country_name) as primary_country
             FROM {$table} WHERE is_active = 1
             GROUP BY sl_code ORDER BY MIN(sort_order) ASC"
        ) ?: [];
    }

    public function get_unique_spoken_languages(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'devents_sign_languages';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [
                (object) [ 'spoken_code' => 'pl', 'spoken_name' => 'polski' ],
                (object) [ 'spoken_code' => 'en', 'spoken_name' => 'angielski' ],
                (object) [ 'spoken_code' => 'de', 'spoken_name' => 'niemiecki' ],
                (object) [ 'spoken_code' => 'fr', 'spoken_name' => 'francuski' ],
                (object) [ 'spoken_code' => 'uk', 'spoken_name' => 'ukraiński' ],
                (object) [ 'spoken_code' => 'ru', 'spoken_name' => 'rosyjski' ],
            ];
        }
        $rows = $wpdb->get_results(
            "SELECT spoken_code, MIN(spoken_name) as spoken_name, MIN(spoken_code) as spoken_abbr
             FROM {$wpdb->prefix}devents_sign_languages WHERE is_active = 1
             GROUP BY spoken_code ORDER BY MIN(sort_order) ASC"
        ) ?: [];

        // Polski jest językiem podstawowym serwisu — zawsze dostępny w napisach
        // i tłumaczeniu fonicznym, nawet jeśli brak go w tabeli języków migowych.
        $has_pl = false;
        foreach ( $rows as $r ) {
            if ( isset( $r->spoken_code ) && $r->spoken_code === 'pl' ) { $has_pl = true; break; }
        }
        if ( ! $has_pl ) {
            array_unshift( $rows, (object) [ 'spoken_code' => 'pl', 'spoken_name' => 'polski', 'spoken_abbr' => 'PL' ] );
        }
        return $rows;
    }

    public function get_accessibility_types(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'devents_accessibility_types';

        // 1. Sprawdź czy tabela istnieje
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [];
        }

        // 2. Obsługa starszych wersji bazy (brak kolumn flag)
        $cols      = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
        $has_flags = in_array( 'requires_sl_lang', $cols );
        
        $select = $has_flags
            ? "id, slug, name, icon, requires_sl_lang, requires_spoken_lang"
            : "id, slug, name, icon, 0 as requires_sl_lang, 0 as requires_spoken_lang";

        $results = $wpdb->get_results(
            "SELECT {$select} FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC"
        );

        if ( empty( $results ) ) {
            return [];
        }

        // 3. MAPOWANIE TŁUMACZEŃ (Kluczowy moment)
        foreach ( $results as $row ) {
            // Używamy helpera, podając slug i obecną nazwę z bazy jako fallback
            $row->name = devents_get_acc_label( $row->slug, $row->name );
        }

        return $results;
    }

    public function get_event_accessibility_ids( int $event_id ): array {
        global $wpdb;
        $rel_table = $wpdb->prefix . 'devents_event_accessibility';
        $ids       = $wpdb->get_col( $wpdb->prepare(
            "SELECT accessibility_id FROM {$rel_table} WHERE event_id = %d",
            $event_id
        ) );
        return array_map( 'intval', $ids );
    }

    public function get_event_accessibility_type_languages( int $event_id, int $accessibility_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'devents_event_accessibility_languages';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [ 'sl' => [], 'spoken' => [] ];
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT lang_type, lang_code, lang_name FROM {$table}
             WHERE event_id = %d AND accessibility_id = %d",
            $event_id, $accessibility_id
        ) );
        $result = [ 'sl' => [], 'spoken' => [] ];
        foreach ( $rows as $r ) {
            $result[ $r->lang_type ][] = [ 'code' => $r->lang_code, 'name' => $r->lang_name ];
        }
        return $result;
    }

    public function get_event_accessibility_objects( int $event_id ): array {
        global $wpdb;
        $rel_table   = $wpdb->prefix . 'devents_event_accessibility';
        $types_table = $wpdb->prefix . 'devents_accessibility_types';
        $lang_table  = $wpdb->prefix . 'devents_event_accessibility_languages';

        $cols      = $wpdb->get_col( "SHOW COLUMNS FROM {$types_table}" );
        $has_flags = in_array( 'requires_sl_lang', $cols );
        $select    = $has_flags
            ? "t.id, t.slug, t.name, t.icon, t.requires_sl_lang, t.requires_spoken_lang"
            : "t.id, t.slug, t.name, t.icon, 0 as requires_sl_lang, 0 as requires_spoken_lang";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$select}
             FROM {$rel_table} r
             JOIN {$types_table} t ON r.accessibility_id = t.id
             WHERE r.event_id = %d
             ORDER BY t.sort_order ASC",
            $event_id
        ) ) ?: [];

        $has_lang_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$lang_table}'" ) === $lang_table );
        if ( $has_lang_table && ! empty( $rows ) ) {
            $all_langs = $wpdb->get_results( $wpdb->prepare(
                "SELECT accessibility_id, lang_type, lang_code, lang_name
                 FROM {$lang_table} WHERE event_id = %d",
                $event_id
            ) );
            $langs_by_acc = [];
            foreach ( $all_langs as $l ) {
                $langs_by_acc[ $l->accessibility_id ][ $l->lang_type ][] = [
                    'code' => $l->lang_code,
                    'name' => $l->lang_name,
                ];
            }
            foreach ( $rows as $row ) {
                $row->languages = $langs_by_acc[ $row->id ] ?? [ 'sl' => [], 'spoken' => [] ];
                $lang_parts     = [];
                foreach ( $row->languages['sl'] ?? [] as $l ) $lang_parts[] = $l['name'];
                foreach ( $row->languages['spoken'] ?? [] as $l ) $lang_parts[] = $l['name'];
                $row->display_name = $row->name . ( ! empty( $lang_parts ) ? ' (' . implode( ', ', $lang_parts ) . ')' : '' );
            }
        } else {
            foreach ( $rows as $row ) {
                $row->languages    = [ 'sl' => [], 'spoken' => [] ];
                $row->display_name = $row->name;
            }
        }

        return $rows;
    }

    private function get_active_occasions() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}devents_special_occasions WHERE end_date >= CURDATE()" );
    }

    /**
     * FIX: Helper — buduje selected_acc_languages z obiektów dostępności.
     * Zwraca: [ acc_id => ['pl', 'en', 'pjm'], ... ]
     */
    private function build_selected_acc_languages( array $accessibility_items ): array {
        $result = [];
        foreach ( $accessibility_items as $item ) {
            $langs = [];
            foreach ( $item->languages['sl'] ?? [] as $l ) $langs[] = $l['code'];
            foreach ( $item->languages['spoken'] ?? [] as $l ) $langs[] = $l['code'];
            if ( ! empty( $langs ) ) {
                $result[ $item->id ] = $langs;
            }
        }
        return $result;
    }


    /* =====================================================================
       RENDERERY: Auth / Formularze
       ===================================================================== */

    public function render_form( $atts, $content = null, $tag = '' ) {
        if ( is_user_logged_in() ) {
            // Wszyscy zalogowani (również administrator) trafiają do panelu front-end —
            // spójnie z auth-handler. Wcześniej admin był wyrzucany do surowego /wp-admin/
            // (admin_url()), co przy logowaniu sprawiało wrażenie „zawieszenia"/błędnego skoku.
            wp_safe_redirect( home_url( '/panel-uzytkownika/' ) );
            exit;
        }

        global $devents_form_errors;
        $template_map = [
            'login_form'    => 'forms/login-form',
            'register_form' => 'forms/register-form',
        ];
        if ( ! isset( $template_map[ $tag ] ) ) {
            return '';
        }

        $form_type      = ( $tag === 'login_form' ? 'login' : 'register' );
        $target_redirect = home_url( '/panel-uzytkownika/' );

        $data = [
            'nonce_field'   => wp_nonce_field( 'devents-' . $form_type, '_wpnonce', true, false ),
            'error_message' => $devents_form_errors[ $form_type ] ?? '',
            'flash_message' => null,
            'redirect_to'   => $target_redirect,
        ];

        if ( isset( $_SESSION['devents_flash_message'] ) ) {
            $data['flash_message'] = $_SESSION['devents_flash_message'];
            unset( $_SESSION['devents_flash_message'] );
        }

        return $this->twig_helper->render( $template_map[ $tag ], $data );
    }

    public function render_php_template( $path_segment ) {
        $path = DEW_PLUGIN_PATH . 'includes/' . $path_segment . '.php';
        ob_start();
        if ( file_exists( $path ) ) {
            include $path;
        }
        return ob_get_clean();
    }

    public function render_password_reset_form() {
        return $this->twig_helper->render( 'forms/password-reset' );
    }

    public function render_contact_form() {
        // v13.1: contact_nonce jest też globalny w Twigu (patrz Twig_Helper::__construct),
        // ale przekazujemy go też lokalnie jako defensive measure i dla czytelności.
        return $this->twig_helper->render( 'forms/contact-form', [
            'contact_nonce' => wp_create_nonce( 'devents_contact_form_action' ),
        ] );
    }

    public function render_admin_panel() {
        $admin_dashboard = new DEvents_Admin_Dashboard();
        return $admin_dashboard->render();
    }


    /* =====================================================================
       PANEL UŻYTKOWNIKA
       ===================================================================== */

    public function render_user_panel() {
        if ( ! is_user_logged_in() ) {
            return $this->render_form( [], null, 'login_form' );
        }

        global $wpdb;
        $current_user_id = get_current_user_id();
        $user_data       = get_userdata( $current_user_id );
        $user_roles      = (array) $user_data->roles;
        $current_view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'pulpit';

        if ( $current_view === 'pulpit' ) {
            $wpdb->update(
                "{$wpdb->prefix}events_notifications",
                [ 'is_read' => 1 ],
                [ 'user_id' => $current_user_id, 'is_read' => 0 ],
                [ '%d' ], [ '%d', '%d' ]
            );
        }

        $unread_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}events_notifications WHERE user_id = %d AND is_read = 0",
            $current_user_id
        ) );

        $is_organizer = in_array( 'organizer', $user_roles );
        $is_master    = devents_user_is_master( $current_user_id );
        $is_any_org   = $is_organizer || $is_master;
        $is_any_mod   = devents_user_is_moderator( $current_user_id );
        $is_staff     = $is_any_org || $is_any_mod;

        $menu_items = devents_get_panel_menu_items();

        if ( ! $is_any_org && ! current_user_can( 'manage_options' ) ) {
            unset( $menu_items['zamowienia'] );
        }

        $menu_items = array_filter( $menu_items, function ( $item ) {
            return isset( $item['visible'] ) && $item['visible'] === true;
        } );

        // Pogrupowanie widocznych pozycji w sekcje (z nagłówkami). Puste grupy
        // — których dana rola nie widzi — są pomijane.
        $menu_group_labels = function_exists( 'devents_get_panel_menu_groups' ) ? devents_get_panel_menu_groups() : [];
        $menu_groups       = [];
        foreach ( $menu_group_labels as $g_slug => $g_label ) {
            $menu_groups[ $g_slug ] = [ 'label' => $g_label, 'items' => [] ];
        }
        foreach ( $menu_items as $slug => $item ) {
            $g = ( isset( $item['group'] ) && isset( $menu_groups[ $item['group'] ] ) ) ? $item['group'] : '_other';
            if ( ! isset( $menu_groups[ $g ] ) ) {
                $menu_groups[ $g ] = [ 'label' => '', 'items' => [] ];
            }
            $menu_groups[ $g ]['items'][ $slug ] = $item;
        }
        $menu_groups = array_filter( $menu_groups, function ( $grp ) {
            return ! empty( $grp['items'] );
        } );

        $allowed_hidden_views = [ 'edytuj-wydarzenie', 'edytuj-profil' ];
        if ( ! array_key_exists( $current_view, $menu_items ) && ! in_array( $current_view, $allowed_hidden_views ) ) {
            $current_view = 'pulpit';
        }

        $profile_card_data = [
            'email'          => $user_data->user_email,
            'logo_url'       => '',
            'is_institution' => $is_any_org,
            'display_name'   => $user_data->display_name,
            'icon_type'      => $is_any_org ? 'domain' : 'account_circle',
        ];

        $data_packet                   = get_user_meta( $current_user_id, '_devents_organizer_data', true ) ?: [];
        $profile_card_data['logo_url'] = $data_packet['logo_url'] ?? '';

        if ( $is_staff ) {
            $target_user_id = $current_user_id;
            if ( $is_any_mod ) {
                $boss_id = get_user_meta( $current_user_id, 'devents_employer_id', true );
                if ( $boss_id ) $target_user_id = $boss_id;
            }

            $inst_id = function_exists( 'devents_get_user_institution_id' ) ? devents_get_user_institution_id( $target_user_id ) : 0;
            if ( $inst_id && function_exists( 'devents_get_institution' ) ) {
                $inst_data                         = devents_get_institution( $inst_id );
                $profile_card_data['org_name']     = $inst_data['name'];
                if ( ! empty( $inst_data['logo_url'] ) ) $profile_card_data['logo_url'] = $inst_data['logo_url'];
            } else {
                if ( $is_any_mod ) {
                    $boss_packet                   = get_user_meta( $target_user_id, '_devents_organizer_data', true );
                    $profile_card_data['org_name'] = $boss_packet['org_name'] ?? '';
                    if ( ! empty( $boss_packet['logo_url'] ) ) $profile_card_data['logo_url'] = $boss_packet['logo_url'];
                } else {
                    $profile_card_data['org_name'] = $data_packet['org_name'] ?? '';
                }
            }
            if ( ! empty( $profile_card_data['org_name'] ) ) {
                $profile_card_data['display_name'] = $profile_card_data['org_name'];
            }
        }

        $show_profile_nag = ( $is_any_org && empty( $profile_card_data['logo_url'] ) && ! isset( $_COOKIE['devents_profile_nag_seen'] ) );

        $data_for_twig = [
            'user'                    => $user_data,
            'menu_items'              => $menu_items,
            'menu_groups'             => $menu_groups,
            'current_view'            => $current_view,
            'unread_notifications'    => $unread_count,
            'panel_url'               => home_url( '/panel-uzytkownika/' ),
            'page_content'            => $this->get_panel_page_content( $current_view, $current_user_id, $profile_card_data ),
            'profile_card'            => $profile_card_data,
            'role_label'              => function_exists( 'devents_user_role_label' ) ? devents_user_role_label( $current_user_id ) : '',
            'is_business_user'        => function_exists( 'devents_user_is_business' ) ? devents_user_is_business( $current_user_id ) : $is_any_org,
            'show_profile_nag'        => $show_profile_nag,
            'js_config'               => [
                'ajax_url'             => admin_url( 'admin-ajax.php' ),
                'org_action_nonce'     => wp_create_nonce( 'devents_org_action_nonce' ),
                'team_action_nonce'    => wp_create_nonce( 'devents_team_action_nonce' ),
                'create_unit_nonce'    => wp_create_nonce( 'devents_unit_action_nonce' ),
                'update_account_nonce' => wp_create_nonce( 'devents-update-account-' . $current_user_id ),
            ],
            'is_registration_success' => isset( $_GET['rejestracja'] ) && $_GET['rejestracja'] === 'sukces',
        ];

        return $this->twig_helper->render( 'user/panel', $data_for_twig );
    }

    private function get_panel_page_content( $view, $user_id, $profile_card_data = [] ) {
        global $wpdb;

        switch ( $view ) {
            case 'pulpit':
                return $this->render_dashboard_overview( $user_id, $profile_card_data );

            case 'zamowienia':
                if ( ! user_can( $user_id, 'organizer' ) && ! user_can( $user_id, 'master_organizer' ) && ! current_user_can( 'manage_options' ) ) {
                    return '<div class="message-box message-box--error">' . __( 'Ta sekcja jest dostępna tylko dla Administratorów instytucji.', 'devents' ) . '</div>';
                }
                return $this->render_my_orders( $user_id );

            case 'moje-wydarzenia':
                return $this->render_my_events_list_logic();

            case 'dodaj-wydarzenie':
            case 'edytuj-wydarzenie':
                return $this->render_publish_event_form();

            case 'moje-jednostki':
                $user_data = get_userdata( $user_id );
                if ( ! devents_user_can_manage_units( $user_id ) ) {
                    return '<div class="message-box message-box--error">' . __( 'Brak uprawnień do zarządzania siecią jednostek.', 'devents' ) . '</div>';
                }

                $pending_units = get_users( [
                    'meta_key'   => 'devents_pending_master_id',
                    'meta_value' => $user_id,
                ] );

                $my_units   = [];
                $my_inst_id = get_user_meta( $user_id, 'devents_institution_id', true );

                if ( $my_inst_id ) {
                    $units_rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, name FROM {$wpdb->prefix}devents_institutions WHERE parent_id = %d",
                        $my_inst_id
                    ) );

                    foreach ( $units_rows as $row ) {
                        $owner = get_users( [
                            'meta_key'   => 'devents_institution_id',
                            'meta_value' => $row->id,
                            'number'     => 1,
                        ] );
                        if ( $owner ) {
                            $u          = $owner[0];
                            $u->inst_id = $row->id;
                            $my_units[] = $u;
                        }
                    }
                }

                $user_code = get_user_meta( $user_id, 'devents_org_unique_code', true );

                return $this->twig_helper->render( 'user/my-units', [
                    'my_units'          => $my_units,
                    'pending_units'     => $pending_units,
                    'user_code'         => $user_code,
                    'org_action_nonce'  => wp_create_nonce( 'devents_org_action_nonce' ),
                    'create_unit_nonce' => wp_create_nonce( 'devents_unit_action_nonce' ),
                    'team_nonce'        => wp_create_nonce( 'devents_team_action_nonce' ),
                    // POPRAWKA: wcześniej js_config był pusty ([]), więc my-units.js miał
                    // niezdefiniowane nonce (team_action_nonce) → podgląd/zapraszanie/usuwanie
                    // kadry kończyło się błędem bezpieczeństwa. Przekazujemy realną konfigurację.
                    'js_config'         => [
                        'ajax_url'          => admin_url( 'admin-ajax.php' ),
                        'org_action_nonce'  => wp_create_nonce( 'devents_org_action_nonce' ),
                        'team_action_nonce' => wp_create_nonce( 'devents_team_action_nonce' ),
                        'create_unit_nonce' => wp_create_nonce( 'devents_unit_action_nonce' ),
                    ],
                ] );

            case 'moja-instytucja':
                return $this->render_institution_view();

            case 'raporty':
                return $this->render_reports( $user_id );

            case 'ustawienia':
                return $this->render_my_account();

            case 'moje-subskrypcje':
                return $this->render_my_subscriptions_list();

            case 'moje-ulubione':
                return $this->render_my_favorites_list();

            case 'moje-potrzeby':
                // Przeniesione do „Ustawienia konta" → zakładka „Moje potrzeby".
                // Stare linki kierujemy na ustawienia konta (zakładka aktywuje się z #hash).
                return $this->render_my_account();

            case 'zapisane-wyszukiwania':
                return $this->render_saved_searches( $user_id );

            case 'moje-opinie':
                return $this->render_my_reviews_list();

            case 'moje-pytania':
                return $this->render_my_questions_list();

            case 'zglos-problem':
                return $this->render_report_problem_form();

            case 'moje-nagrody':
                return $this->render_my_rewards( $user_id );

            case 'panel-admina':
                if ( current_user_can( 'manage_options' ) ) {
                    $dashboard = new DEvents_Admin_Dashboard();
                    return $dashboard->render();
                }
                return '<div class="message-box message-box--error">' . __( 'Brak uprawnień.', 'devents' ) . '</div>';

            default:
                return $this->render_dashboard_overview( $user_id, $profile_card_data );
        }
    }


    /* =====================================================================
       PANEL: Ustawienia konta
       ===================================================================== */

    public function render_my_account() {
        if ( ! is_user_logged_in() ) return '';

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        // Dodajemy brakujące metadane profilu do obiektu użytkownika dla Twiga
        $user->default_country     = get_user_meta( $user_id, 'default_country', true );
        $user->default_spoken_lang = get_user_meta( $user_id, 'default_spoken_lang', true );
        $user->default_sign_lang   = get_user_meta( $user_id, 'default_sign_lang', true );

        // Pobieramy słowniki z globalnych funkcji pomocniczych
        $countries        = function_exists('devents_get_countries') ? devents_get_countries() : [];
        $spoken_languages = function_exists('devents_get_spoken_languages') ? devents_get_spoken_languages() : [];
        $sign_languages   = function_exists('devents_get_sign_languages') ? devents_get_sign_languages() : [];

        // „Moje potrzeby" (profil dostępności) — przeniesione do Ustawień konta jako zakładka.
        $a11y_types = method_exists( $this, 'get_accessibility_types' ) ? $this->get_accessibility_types() : [];
        $a11y_prefs = array_values( array_filter( array_map( 'intval', (array) get_user_meta( $user_id, 'devents_accessibility_prefs', true ) ) ) );

        return $this->twig_helper->render( 'user/my-account', [
            'user'              => $user,
            'details_nonce'     => wp_create_nonce( 'devents-update-account-' . $user_id ),
            'password_nonce'    => wp_create_nonce( 'devents-change-password-' . $user_id ),
            'delete_nonce'      => wp_create_nonce( 'devents-delete-account-' . $user_id ),
            'marketing_consent'      => get_user_meta( $user_id, 'devents_marketing_consent', true ),
            // Domyślnie WŁĄCZONE (brak meta = '' → traktujemy jako zgodę), wyłączamy gdy '0'.
            'notify_followed_events' => get_user_meta( $user_id, 'devents_notify_followed_events', true ),
            'countries'         => $countries,
            'spoken_languages'  => $spoken_languages,
            'sign_languages'    => $sign_languages,
            'a11y_types'        => $a11y_types,
            'a11y_prefs'        => $a11y_prefs,
            'a11y_nonce'        => wp_create_nonce( 'devents_a11y_prefs_' . $user_id ),
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
        ] );
    }


    /* =====================================================================
       PANEL: Zamówienia
       ===================================================================== */

    private function render_my_orders( $user_id ) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'devents_orders';
        $events_table = $wpdb->prefix . 'events_list';

        $orders = $wpdb->get_results( $wpdb->prepare( "
            SELECT o.*, e.title as event_title 
            FROM {$orders_table} o
            LEFT JOIN {$events_table} e ON o.event_id = e.ID
            WHERE o.user_id = %d 
            ORDER BY o.created_at DESC
        ", $user_id ) );

        foreach ( $orders as $order ) {
            $order->billing     = ! empty( $order->billing_details ) ? ( is_array( $order->billing_details ) ? $order->billing_details : json_decode( $order->billing_details, true ) ) : [];
            $order->pjm_details = ! empty( $order->pjm_details ) ? ( is_array( $order->pjm_details ) ? $order->pjm_details : json_decode( $order->pjm_details, true ) ) : [];

            if ( $order->product_type === 'translation_pjm' ) {
                $order->product_label = __( 'Tłumaczenie PJM', 'devents' );
            } elseif ( strpos( $order->product_type, 'featured_' ) !== false ) {
                $order->product_label = __( 'Wyróżnienie', 'devents' );
            } else {
                $order->product_label = $order->product_type;
            }
        }

        return $this->twig_helper->render( 'user/my-orders', [
            'orders' => $orders,
        ] );
    }


    /* =====================================================================
       PANEL: Nagrody
       ===================================================================== */

    public function render_my_rewards( $user_id ) {
        global $wpdb;
        $events_table = $wpdb->prefix . 'events_list';
        $inst_table   = $wpdb->prefix . 'devents_institutions';

        $inst_id         = get_user_meta( $user_id, 'devents_institution_id', true );
        $count           = 0;
        $claimed_rewards = [];
        $all_codes       = [];
        $entity_name     = '';

        if ( $inst_id ) {
            $count     = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM {$events_table} WHERE institution_id = %d AND verified = 1",
                $inst_id
            ) );
            $inst_data = $wpdb->get_row( $wpdb->prepare( "SELECT name, metadata FROM {$inst_table} WHERE id = %d", $inst_id ) );
            if ( $inst_data ) {
                $entity_name     = $inst_data->name;
                $meta            = ! empty( $inst_data->metadata ) ? json_decode( $inst_data->metadata, true ) : [];
                $claimed_rewards = $meta['rewards_claimed'] ?? [];
                $all_codes       = $meta['rewards_codes'] ?? [];
            }
        } else {
            $sql_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$events_table} WHERE user_id = %d AND verified = 1", $user_id ) );
            $meta_count  = (int) get_user_meta( $user_id, '_devents_event_count', true );
            $count       = max( $sql_count, $meta_count );
            $claimed_raw = get_user_meta( $user_id, '_devents_rewards_claimed', true );
            $claimed_rewards = is_array( $claimed_raw ) ? $claimed_raw : [];
            $current_user = get_userdata( $user_id );
            $entity_name  = $current_user ? $current_user->display_name : __( 'Użytkownik', 'devents' );
        }

        // Kolory dobrane pod kontrast WCAG AA (≥4.5:1) na jasnym tle danej karty (bg).
        // Wcześniej złoto (#d97706) i diament (#0ea5e9) nie spełniały kontrastu na swoich tłach.
        $badges_def = [
            [ 'key' => 'bronze',   'min' => 5,   'label' => __( 'Aktywny', 'devents' ),    'color' => '#b45309', 'icon' => 'workspace_premium', 'bg' => '#fffbeb' ],
            [ 'key' => 'silver',   'min' => 10,  'label' => __( 'Srebrny', 'devents' ),    'color' => '#475569', 'icon' => 'military_tech',     'bg' => '#f8fafc' ],
            [ 'key' => 'gold',     'min' => 25,  'label' => __( 'Złoty', 'devents' ),      'color' => '#a16207', 'icon' => 'verified',          'bg' => '#fef3c7' ],
            [ 'key' => 'platinum', 'min' => 50,  'label' => __( 'Platynowy', 'devents' ),  'color' => '#334155', 'icon' => 'shield',            'bg' => '#f1f5f9' ],
            [ 'key' => 'diamond',  'min' => 100, 'label' => __( 'Diamentowy', 'devents' ), 'color' => '#0369a1', 'icon' => 'diamond',           'bg' => '#e0f2fe' ],
            [ 'key' => 'legend',   'min' => 500, 'label' => __( 'Legenda', 'devents' ),    'color' => '#000000', 'icon' => 'auto_awesome',      'bg' => '#f3f4f6' ],
        ];

        $history = [];
        foreach ( $badges_def as $badge ) {
            $key          = $badge['key'];
            $is_achieved  = ( $count >= $badge['min'] );
            $is_claimed   = in_array( $key, $claimed_rewards );

            if ( ! $is_achieved ) {
                $badge['status'] = 'locked';
            } elseif ( $is_claimed ) {
                $badge['status'] = 'claimed';
            } else {
                $badge['status'] = 'pending';
            }

            $badge['missing'] = max( 0, $badge['min'] - $count );
            $badge['codes']   = [];

            if ( $is_claimed ) {
                if ( $inst_id ) {
                    $badge['codes'] = $all_codes[ $key ] ?? [];
                } else {
                    $codes = get_user_meta( $user_id, '_devents_reward_codes_' . $key, true );
                    if ( is_array( $codes ) ) $badge['codes'] = $codes;
                }
            }

            $history[] = $badge;
        }

        $site_logo = defined( 'DEW_PLUGIN_URL' ) ? DEW_PLUGIN_URL . 'assets/images/logo-DEvents-02.png' : '';

        return $this->twig_helper->render( 'user/my-rewards', [
            'data'          => [
                'count'          => $count,
                'history'        => $history,
                'entity_name'    => $entity_name,
                'is_institution' => (bool) $inst_id,
            ],
            'user'          => wp_get_current_user(),
            'site_logo_url' => $site_logo,
        ] );
    }


	    /* =====================================================================
	       PANEL: Pulpit
	       ===================================================================== */
	    
	    private function render_dashboard_overview( $user_id, $profile_card = [] ) {
	        global $wpdb;
	    
	        $events_table        = $wpdb->prefix . 'events_list';
	        $materials_table     = $wpdb->prefix . 'events_materials';
	        $notifications_table = $wpdb->prefix . 'events_notifications';
	        $institutions_table  = $wpdb->prefix . 'devents_institutions';
	    
	        $user_data = get_userdata( $user_id );
	    
	        if ( ! $user_data ) {
	            return '';
	        }
	    
	        $user_roles = (array) $user_data->roles;
	    
	        $is_admin     = current_user_can( 'manage_options' );
	        $is_master    = devents_user_is_master( $user_data->ID );
	        $is_moderator = devents_user_is_moderator( $user_data->ID );
	        $is_organizer = in_array( 'organizer', $user_roles, true ) || $is_master;
	    
	        $notifications = $wpdb->get_results(
	            $wpdb->prepare(
	                "SELECT * FROM {$notifications_table}
	                 WHERE user_id = %d
	                 ORDER BY created_at DESC
	                 LIMIT 5",
	                $user_id
	            )
	        );
	    
	        $is_verified_org = true;
	        $inst_id         = get_user_meta( $user_id, 'devents_institution_id', true );
	    
	        if ( ( $is_organizer || $is_moderator ) && $inst_id ) {
	            $verification_status = $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT verification_status
	                     FROM {$institutions_table}
	                     WHERE id = %d",
	                    $inst_id
	                )
	            );
	    
	            $is_verified_org = ( intval( $verification_status ) === 1 );
	        }
	    
	        $stats = [];
	    
	        if ( $is_admin ) {
	    
	            $user_counts                    = count_users();
	            $stats['total_users']           = $user_counts['total_users'] ?? 0;
	            $stats['total_institutions']    = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$institutions_table}" );
	            $stats['total_global_events']   = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$events_table}" );
			$stats['total_global_views'] = (int) $wpdb->get_var( "SELECT SUM(views) FROM {$events_table}" );
	            $stats['pending_verifications'] = (int) $wpdb->get_var(
	                "SELECT COUNT(id)
	                 FROM {$institutions_table}
	                 WHERE verification_status = 0"
	            );
	    
	        } elseif ( $is_organizer || $is_moderator ) {
	    
	            $stats['events_count'] = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT COUNT(*)
	                     FROM {$events_table}
	                     WHERE user_id = %d",
	                    $user_id
	                )
	            );
	    
	            $events_views = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT SUM(views)
	                     FROM {$events_table}
	                     WHERE user_id = %d",
	                    $user_id
	                )
	            );
	    
	            $videos_views = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT SUM(views)
	                     FROM {$materials_table}
	                     WHERE user_id = %d",
	                    $user_id
	                )
	            );
	    
	            $stats['total_views'] = $events_views + $videos_views;
	    
	            $avg_rating = $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT AVG(rating)
	                     FROM {$wpdb->prefix}devents_reviews
	                     WHERE item_id = %d
	                     AND item_type = 'organizer'
	                     AND is_approved = 1",
	                    $user_id
	                )
	            ) ?: 0;
	    
	            $stats['average_rating'] = round( $avg_rating, 1 );
	    
	            $stats['subscribers_count'] = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT COUNT(*)
	                     FROM {$wpdb->prefix}devents_subscriptions
	                     WHERE organizer_user_id = %d",
	                    $user_id
	                )
	            );
	    
	            if ( $is_master && $inst_id ) {
	                $stats['units_count'] = (int) $wpdb->get_var(
	                    $wpdb->prepare(
	                        "SELECT COUNT(id)
	                         FROM {$institutions_table}
	                         WHERE parent_id = %d",
	                        $inst_id
	                    )
	                );
	            }
	    
	        } else {
	    
	            $stats['followed_orgs_count'] = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT COUNT(*)
	                     FROM {$wpdb->prefix}devents_subscriptions
	                     WHERE subscriber_user_id = %d",
	                    $user_id
	                )
	            );
	    
	            $stats['reviews_given_count'] = (int) $wpdb->get_var(
	                $wpdb->prepare(
	                    "SELECT COUNT(*)
	                     FROM {$wpdb->prefix}devents_reviews
	                     WHERE user_id = %d",
	                    $user_id
	                )
	            );
	    
	            $favs = get_user_meta( $user_id, 'devents_favorite_events', true );
	    
	            $stats['favorites_count'] = (
	                ! empty( $favs ) && is_array( $favs )
	            ) ? count( $favs ) : 0;
	        }
	    
	        if ( empty( $profile_card ) || empty( $profile_card['logo_url'] ) ) {
	            $personal_logo = get_user_meta( $user_id, '_devents_user_logo', true );
	    
	            if ( $personal_logo ) {
	                $profile_card['logo_url'] = $personal_logo;
	            }
	        }
	    
	        // Najbliższe nadchodzące wydarzenie — karta na pulpicie.
		$now_dt = current_time( 'mysql' );
		if ( $is_admin ) {
			$nearest_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE verified = 1 AND start_datetime > %s ORDER BY start_datetime ASC LIMIT 1",
				$now_dt
			) );
		} else {
			$nearest_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE user_id = %d AND start_datetime > %s ORDER BY start_datetime ASC LIMIT 1",
				$user_id, $now_dt
			) );
		}
		$nearest_prepared = ! empty( $nearest_raw ) ? $this->prepare_events_for_display( $nearest_raw ) : [];
		$nearest_event    = ! empty( $nearest_prepared ) ? $nearest_prepared[0] : null;

		return $this->twig_helper->render( 'user/my-pulpit', [
			'nearest_event'       => $nearest_event,
	            'dashboard_data'      => $stats,
	            'notifications'       => $notifications,
	            'user'                => $user_data,
	            'user_id'             => $user_id,
	            'profile_card'        => $profile_card,
	            'panel_url'           => home_url( '/panel-uzytkownika/' ),
	            'is_admin'            => $is_admin,
	            'is_master'           => $is_master,
	            'is_organizer'        => $is_organizer,
	            'is_moderator'        => $is_moderator,
	            'is_verified_org'     => $is_verified_org,
	    
	            // URL z użyciem admin-ajax.php, aby ominąć blokady admin-post.php
	            'my_poster_url' => add_query_arg(
	                [
	                    'action' => 'devents_print_poster',
	                    'nonce'  => wp_create_nonce( 'devents_org_action_nonce' ),
	                ],
	                admin_url( 'admin-ajax.php' )
	            ),
	            
	            'devents_poster_url' => add_query_arg(
	                [
	                    'action' => 'devents_print_devents_poster',
	                    'nonce'  => wp_create_nonce( 'devents_org_action_nonce' ),
	                ],
	                admin_url( 'admin-ajax.php' )
	            ),
	        ] );
	    }

    /* =====================================================================
       FORMULARZ PUBLIKACJI / EDYCJI WYDARZENIA
       ===================================================================== */

    public function render_publish_event_form() {
        if ( ! is_user_logged_in() ) return __( 'Musisz być zalogowany, aby opublikować wydarzenie.', 'devents' );

        $user       = wp_get_current_user();
        $user_id    = $user->ID;
        $user_roles = (array) $user->roles;
        $allowed_roles = [ 'organizer', 'organizer_mod', 'master_organizer', 'master_organizer_mod' ];
        $has_permission = ! empty( array_intersect( $allowed_roles, $user_roles ) ) || current_user_can( 'manage_options' );

        if ( ! $has_permission ) {
            return $this->twig_helper->render( 'components/permission-gate', [
                'icon'        => 'verified_user',
                'title'       => __( 'Strefa Organizatora', 'devents' ),
                'message'     => __( 'Ta sekcja jest dostępna tylko dla kont typu Organizator, Master oraz ich Moderatorów.', 'devents' ),
                'action_url'  => home_url( '/panel-uzytkownika/?view=ustawienia' ),
                'action_text' => __( 'Zmień typ konta w ustawieniach', 'devents' ),
            ] );
        }

        $is_verified = get_user_meta( $user_id, 'is_verified', true );
        if ( $is_verified !== '1' && ! current_user_can( 'manage_options' ) ) {
            return '<div class="message-box message-box--error">' . __( 'Twoje konto musi zostać zweryfikowane przez administratora.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $table_events = $wpdb->prefix . 'events_list';

        $event_data                 = null;
        $tickets_data               = [];
        $event_logs                 = [];
        $event_id_to_edit           = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $is_admin_editing           = false;
        $selected_accessibility_ids = [];
        $selected_acc_languages     = [];

        if ( $event_id_to_edit > 0 ) {
            $event_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_events} WHERE id = %d", $event_id_to_edit ) );

            if ( $event_data ) {
                $is_owner              = ( $event_data->user_id == $user_id );
                $is_admin              = current_user_can( 'manage_options' );
                $is_master_of_owner    = ( function_exists( 'devents_is_master_of' ) ) ? devents_is_master_of( $user_id, $event_data->user_id ) : false;
                $my_inst_id            = (int) get_user_meta( $user_id, 'devents_institution_id', true );
                $event_inst_id         = (int) $event_data->institution_id;
                $is_same_institution   = ( $my_inst_id > 0 && $my_inst_id === $event_inst_id );

                if ( ! $is_owner && ! $is_admin && ! $is_master_of_owner && ! $is_same_institution ) {
                    return '<div class="message-box message-box--error">' . __( 'Nie masz uprawnień do edycji tego wydarzenia.', 'devents' ) . '</div>';
                }

                if ( ! $is_owner && ( $is_same_institution || $is_admin || $is_master_of_owner ) ) {
                    $is_admin_editing = true;
                }

                /* --- DEKODOWANIE NOWEGO ADRESU (JSON z połączonym Nr/Lokal) --- */
                if ( ! empty( $event_data->address_json ) ) {
                    $parsed_address = json_decode( $event_data->address_json, true );
                    if ( is_array( $parsed_address ) ) {
                        $event_data->address_street   = $parsed_address['street'] ?? '';
                        
                        // Migracja wsteczna: jeśli zapisano osobno 'apartment_no' w starszej wersji JSON, łączymy.
                        $b_no = $parsed_address['building_no'] ?? '';
                        $a_no = $parsed_address['apartment_no'] ?? '';
                        $event_data->address_building = trim( $b_no . ( $a_no ? '/' . $a_no : '' ) );
                        
                        $event_data->address_city      = $parsed_address['city'] ?? '';
                        $event_data->event_country_code = $parsed_address['country'] ?? 'PL';
                        $event_data->location_info     = $parsed_address['info'] ?? '';
                    }
                } else {
                    $event_data->address_street    = $event_data->location ?? '';
                    $event_data->address_building  = '';
                    $event_data->address_city      = '';
                    $event_data->event_country_code = $event_data->event_country_code ?: 'PL';
                    $event_data->location_info     = '';
                }

                // Bilety
                if ( ! empty( $event_data->ticket_types ) ) {
                    $tickets_data = json_decode( $event_data->ticket_types, true );
                } elseif ( ! empty( $event_data->price ) && $event_data->price > 0 ) {
                    $tickets_data[] = [ 'name' => __( 'Wejściówka', 'devents' ), 'price' => $event_data->price ];
                }

                // Tłumaczenia
                if ( ! empty( $event_data->translations ) ) {
                    $event_data->translations = json_decode( $event_data->translations, true ) ?: [];
                } else {
                    $event_data->translations = [];
                }

                // Dostępność (z JSON)
                if ( ! empty( $event_data->accessibility ) ) {
                    $decoded_acc = json_decode( $event_data->accessibility, true );
                    if ( is_array( $decoded_acc ) ) {
                        foreach ( $decoded_acc as $acc_id => $langs ) {
                            $selected_accessibility_ids[] = (int) $acc_id;
                            $selected_acc_languages[ $acc_id ] = is_array($langs) ? array_values($langs) : [];
                        }
                    }
                }

                // Wideo
                if ( ! empty( $event_data->invitation_videos ) ) {
                    $event_data->invitation_videos_list = json_decode( $event_data->invitation_videos, true ) ?: [];
                } else {
                    if ( ! empty( $event_data->invitation_video_url ) ) {
                        $event_data->invitation_videos_list = [
                            [ 'url' => $event_data->invitation_video_url, 'lang' => '', 'type' => '' ],
                        ];
                    } else {
                        $event_data->invitation_videos_list = [];
                    }
                }

                // Logi
                $logs_table  = $wpdb->prefix . 'devents_audit_log';
                $users_table = $wpdb->prefix . 'users';

                $logs_data = $wpdb->get_results( $wpdb->prepare( "
                    SELECT l.*, u.display_name, u.user_email 
                    FROM {$logs_table} l 
                    LEFT JOIN {$users_table} u ON l.user_id = u.ID 
                    WHERE l.object_type = 'event' AND l.object_id = %d
                    ORDER BY l.log_time DESC
                ", $event_id_to_edit ) );

                if ( $logs_data ) {
                    foreach ( $logs_data as $log ) {
                        $action_label = $log->action;
                        $icon         = 'info';

                        switch ( $log->action ) {
                            case 'event_created':    $action_label = __( 'Utworzono wydarzenie', 'devents' );       $icon = 'add_circle'; break;
                            case 'event_updated':    $action_label = __( 'Zaktualizowano wydarzenie', 'devents' );  $icon = 'edit'; break;
                            case 'event_cancelled':  $action_label = __( 'Odwołano wydarzenie', 'devents' );        $icon = 'block'; break;
                            case 'event_restored':   $action_label = __( 'Przywrócono wydarzenie', 'devents' );     $icon = 'restore'; break;
                            case 'event_duplicated': $action_label = __( 'Skopiowano wydarzenie', 'devents' );      $icon = 'content_copy'; break;
                            case 'social_published': $action_label = __( 'Opublikowano w social media', 'devents' ); $icon = 'share'; break;
                        }

                        $details_text = '';
                        if ( ! empty( $log->details ) ) {
                            $decoded = json_decode( $log->details, true );
                            if ( is_array( $decoded ) ) {
                                $parts = [];
                                foreach ( $decoded as $k => $v ) { $parts[] = ucfirst( $k ) . ': ' . $v; }
                                $details_text = implode( ' | ', $parts );
                            } else {
                                $details_text = $log->details;
                            }
                        }

                        $event_logs[] = [
                            'date'         => date( 'd.m.Y H:i', strtotime( $log->log_time ) ),
                            'action_label' => $action_label,
                            'user_name'    => $log->display_name ?: 'System',
                            'user_email'   => $log->user_email ?: '',
                            'details'      => $details_text,
                            'icon'         => $icon,
                        ];
                    }
                }
            } else {
                return '<div class="message-box message-box--error">' . __( 'Nie znaleziono wydarzenia o podanym ID.', 'devents' ) . '</div>';
            }
        } else {
            // KLUCZOWA POPRAWKA: Inicjalizacja zmiennej jako obiektu
            $event_data = new stdClass(); 

            // Teraz można bezpiecznie przypisywać właściwości
            $event_data->address_name = '';
            $event_data->address_street = '';
            $event_data->address_building = '';
            $event_data->address_city = '';
            $event_data->event_country_code = 'PL'; // Domyślna wartość
            $event_data->location_info = '';

            // Pobieramy ID instytucji organizatora
            $inst_id = (int) get_user_meta($user_id, 'devents_institution_id', true);

            if ($inst_id > 0) {
                // Próba pobrania danych przez helper (jeśli zwraca tablicę/obiekt)
                $inst = function_exists('devents_get_institution') ? devents_get_institution($inst_id) : null;
                
                if ($inst) {
                    // Sugerujemy nazwę instytucji jako nazwę miejsca
                    $event_data->address_name     = $inst['name'] ?? ''; 
                    $event_data->address_street   = $inst['street'] ?? '';
                    $event_data->address_building = $inst['building_no'] ?? '';
                    $event_data->address_city     = $inst['city'] ?? '';
                }

                // Dodatkowe sprawdzenie metadata (zgodnie z Twoim kodem)
                global $wpdb;
                $table_inst = $wpdb->prefix . 'devents_institutions';
                
                $metadata_json = $wpdb->get_var($wpdb->prepare(
                    "SELECT metadata FROM {$table_inst} WHERE id = %d", 
                    $inst_id
                ));

                if (!empty($metadata_json)) {
                    $metadata = json_decode($metadata_json, true);
                    
                    if (isset($metadata['address_data'])) {
                        $addr = $metadata['address_data'];
                        
                        // Mapowanie danych z address_data
                        $event_data->address_street = $addr['street'] ?? $event_data->address_street;
                        
                        // Łączymy numer domu i lokalu
                        $house = $addr['house_number'] ?? '';
                        $apartment = $addr['apartment_number'] ?? ''; 
                        if ($house) {
                            $event_data->address_building = trim($house . ($apartment ? '/' . $apartment : ''));
                        }
                        
                        $event_data->address_city = $addr['city'] ?? $event_data->address_city;
                        $event_data->event_country_code = $metadata['country_code'] ?? ($addr['country'] ?? 'PL');
                    }
                }
            }
        }

        if ( empty( $tickets_data ) ) {
            $tickets_data[] = [ 'name' => '', 'price' => '' ];
        }

        $is_master = devents_user_is_master( $user_id );
        $sub_units = [];
        if ( $is_master && function_exists( 'devents_get_units_ids' ) ) {
            $unit_ids = devents_get_units_ids( $user_id );
            if ( ! empty( $unit_ids ) ) {
                $sub_units = get_users( [ 'include' => $unit_ids, 'fields' => [ 'ID', 'display_name' ] ] );
            }
        }

        $all_institutions = [];
        if ( current_user_can( 'manage_options' ) ) {
            $table_inst       = $wpdb->prefix . 'devents_institutions';
            $all_institutions = $wpdb->get_results( "SELECT id, name FROM {$table_inst} ORDER BY name ASC", ARRAY_A );
        }

        $accessibility_types = $this->get_accessibility_types();

        // 1. Pobieramy słownik wszystkich języków z helpera
        $spoken_langs_dict = function_exists( 'devents_get_spoken_languages' ) ? devents_get_spoken_languages() : [];
        
        // 2. Ustalanie nazwy i kodu aktywnego języka domyślnego
        $current_lang_code = function_exists('determine_locale') ? substr(determine_locale(), 0, 2) : 'pl';
        
        // Fallback: Jeśli z jakiegoś powodu locale nie ma w słowniku, ustawiamy PL
        if ( ! isset( $spoken_langs_dict[$current_lang_code] ) ) {
            $current_lang_code = 'pl';
        }
        $current_lang_name = $spoken_langs_dict[$current_lang_code]['name'];

        // 3. Budowanie listy "Zajętych" języków (język domyślny + już dodane tłumaczenia przy edycji)
        $used_languages = [ $current_lang_code ];
        if ( ! empty( $event_data->translations ) && is_array( $event_data->translations ) ) {
            foreach ( array_keys( $event_data->translations ) as $t_code ) {
                $used_languages[] = $t_code;
            }
        }

        // 4. Budowanie listy dostępnych (nieużytych) języków fonicznych dla selecta w Twig
        $available_spoken_languages = [];
        foreach ( $spoken_langs_dict as $code => $data ) {
            if ( ! in_array( $code, $used_languages ) ) {
                $available_spoken_languages[] = [ 
                    'spoken_code' => $code, 
                    'spoken_name' => $data['name'],
                    'spoken_abbr' => $data['abbr'] 
                ];
            }
        }
        
        $unique_sign_languages = [];
        if ( function_exists( 'devents_get_sign_languages' ) ) {
            foreach ( devents_get_sign_languages() as $code => $data ) {
                $unique_sign_languages[] = [ 
                    'sl_code' => $code, 
                    'sl_name' => $data['name'], 
                    'sl_abbr' => $data['abbr'] 
                ];
            }
        }

        $sign_languages = method_exists( $this, 'get_sign_languages' ) ? $this->get_sign_languages() : [];
        $event_categories = function_exists('devents_get_event_categories') ? devents_get_event_categories() : [];
        $event_methods    = function_exists('devents_get_event_methods') ? devents_get_event_methods() : [];
        $countries        = function_exists('devents_get_countries') ? devents_get_countries() : ['PL' => 'Polska'];
        $current_lang     = function_exists( 'determine_locale' ) ? substr( determine_locale(), 0, 2 ) : 'pl';

        return $this->twig_helper->render( 'pages/publish-event', [
            'event'                      => $event_data,
            'event_logs'                 => $event_logs,
            'tickets'                    => $tickets_data,
            'event_categories'           => $event_categories,
            'event_methods'              => $event_methods,
            'countries'                  => $countries,
            'accessibility_types'        => $accessibility_types,
            'selected_accessibility_ids' => $selected_accessibility_ids,
            'selected_acc_languages'     => $selected_acc_languages,
            'sign_languages'             => $sign_languages,
            'unique_sign_languages'      => $unique_sign_languages,
            'unique_spoken_languages'    => $available_spoken_languages,
            'nonce_field'                => wp_nonce_field( 'devents_save_event_action', '_wpnonce', true, false ),
            'active_occasions'           => $this->get_active_occasions(),
            'is_admin_editing'           => $is_admin_editing,
            'is_master'                  => $is_master,
            'sub_units'                  => $sub_units,
            'all_institutions'           => $all_institutions,
            'current_lang_code'          => $current_lang_code,
            'current_lang_name'          => $current_lang_name,
        ] );
    }


    /* =====================================================================
       SINGLE EVENT (v13.6 - LIVE TRANSLATIONS, Split Address, i18n)
       ===================================================================== */
    public function render_single_event( $atts ) {
        $atts     = shortcode_atts( [ 'id' => 0 ], $atts );
        $event_id = intval( $atts['id'] );

        if ( ! $event_id ) {
            return '<div class="message-box message-box--error">' . __( 'Nieprawidłowy ID wydarzenia.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'events_list';

        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, so.name as special_occasion_name, so.slug as special_occasion_slug, so.color as special_occasion_color
            FROM {$table_name} e
            LEFT JOIN {$wpdb->prefix}devents_special_occasions so ON e.special_occasion_id = so.id
            WHERE e.id = %d",
            $event_id
        ) );

        if ( ! $event ) {
            return '<div class="message-box message-box--error">' . __( 'Nie znaleziono wydarzenia.', 'devents' ) . '</div>';
        }

        $event = stripslashes_deep( $event );

        // --- 1. MAPOWANIE SLUGÓW NA ETYKIETY (Kategorie i Metody) ---
        $all_categories     = function_exists('devents_get_event_categories') ? devents_get_event_categories() : [];
        $all_delivery_modes = function_exists('devents_get_event_methods') ? devents_get_event_methods() : [];

        $event->category_label      = $all_categories[$event->category] ?? ucfirst($event->category);
        $event->delivery_mode_label = $all_delivery_modes[$event->delivery_mode] ?? ucfirst($event->delivery_mode);

        // --- 2. OBSŁUGA ADRESU I KRAJU ---
        if ( ! empty( $event->address_json ) ) {
            $parsed_address = json_decode( $event->address_json, true );
            if ( is_array( $parsed_address ) ) {
                $event->address_name       = $parsed_address['name'] ?? '';
                $event->address_street     = $parsed_address['street'] ?? '';
                $event->address_building   = $parsed_address['building_no'] ?? '';
                $event->address_city       = $parsed_address['city'] ?? '';
                $event->event_country_code = $parsed_address['country'] ?? 'PL';
            }
        }

        if ( ! empty( $event->event_country_code ) && function_exists( 'devents_get_countries' ) ) {
            $countries = devents_get_countries();
            $event->country_name = $countries[ $event->event_country_code ] ?? $event->event_country_code;
        }

        // --- 3. WIDEO ZAPROSZENIA (JSON) ---
        if ( ! empty( $event->invitation_videos ) ) {
            $event->invitation_videos = json_decode( $event->invitation_videos, true ) ?: [];
        } else {
            $event->invitation_videos = [];
            if ( ! empty( $event->invitation_video_url ) ) {
                $event->invitation_videos[] = [ 'url' => $event->invitation_video_url, 'lang' => '' ];
            }
        }

        // --- 4. BILETY (JSON - Zbieramy bazowe nazwy) ---
        $default_tickets = [];
        if ( ! empty( $event->ticket_types ) ) {
            $event->ticket_types = json_decode( $event->ticket_types, true ) ?: [];
            foreach ( $event->ticket_types as $t ) {
                $default_tickets[] = $t['name'] ?? '';
            }
        } else {
            $event->ticket_types = [];
        }

        // --- 5. WIELOJĘZYCZNA TREŚĆ (Słownik dla Frontend'u i Live Translations) ---
        $current_lang = function_exists( 'determine_locale' ) ? substr( determine_locale(), 0, 2 ) : 'pl';
        $spoken_langs = function_exists( 'devents_get_spoken_languages' ) ? devents_get_spoken_languages() : [];
        $converter    = new \League\CommonMark\CommonMarkConverter( [ 'html_input' => 'strip', 'allow_unsafe_links' => false ] );

        $content_versions = [];

        // Bazowy język (zazwyczaj PL)
        $content_versions['pl'] = [
            'code'               => 'pl',
            'name'               => $spoken_langs['pl']['name'] ?? 'Polski',
            'title'              => $event->title,
            'description_html'   => ! empty( $event->description ) ? (string) $converter->convert( $event->description ) : '',
            'address_name'       => $event->address_name ?? '',
            'address_city'       => $event->address_city ?? '',
            'location_info_html' => ! empty( $event->location_info ) ? (string) $converter->convert( $event->location_info ) : '',
            'image_alt_text'     => $event->image_alt_text ?? '',
            'action_button_text' => $event->action_button_text ?? '',
            'tickets_names'      => $default_tickets
        ];

        // Dekodujemy nadpisane tłumaczenia z bazy
        if ( ! empty( $event->translations ) ) {
            $trans_data = json_decode( $event->translations, true );
            if ( is_array( $trans_data ) ) {
                foreach ( $trans_data as $lang_code => $data ) {
                    if ( ! empty( $data['title'] ) || ! empty( $data['description'] ) ) {
                        $content_versions[ $lang_code ] = [
                            'code'               => $lang_code,
                            'name'               => $spoken_langs[ $lang_code ]['name'] ?? strtoupper( $lang_code ),
                            'title'              => ! empty( $data['title'] ) ? $data['title'] : $content_versions['pl']['title'],
                            'description_html'   => ! empty( $data['description'] ) ? (string) $converter->convert( $data['description'] ) : $content_versions['pl']['description_html'],
                            'address_name'       => ! empty( $data['address_name'] ) ? $data['address_name'] : $content_versions['pl']['address_name'],
                            'address_city'       => ! empty( $data['address_city'] ) ? $data['address_city'] : $content_versions['pl']['address_city'],
                            'location_info_html' => ! empty( $data['location_info'] ) ? (string) $converter->convert( $data['location_info'] ) : $content_versions['pl']['location_info_html'],
                            'image_alt_text'     => ! empty( $data['image_alt_text'] ) ? $data['image_alt_text'] : $content_versions['pl']['image_alt_text'],
                            'action_button_text' => ! empty( $data['action_button_text'] ) ? $data['action_button_text'] : $content_versions['pl']['action_button_text'],
                            'tickets_names'      => ! empty( $data['tickets_names'] ) ? $data['tickets_names'] : $content_versions['pl']['tickets_names'],
                        ];
                    }
                }
            }
        }

        // Aktywny język startowy (Fallback na pl)
        $active_version_code = isset( $content_versions[ $current_lang ] ) ? $current_lang : 'pl';

        // --- 6. STATUS CZASOWY ---
        $now_ts   = current_time( 'timestamp' );
        $start_ts = strtotime( $event->start_datetime );
        $end_ts   = ! empty( $event->end_datetime ) && $event->end_datetime !== '0000-00-00 00:00:00'
            ? strtotime( $event->end_datetime ) : $start_ts;
        $event->is_current = ( $start_ts <= $now_ts && $end_ts >= $now_ts );

        // Zwiększ licznik wyświetleń
        $wpdb->query( $wpdb->prepare( "UPDATE {$table_name} SET views = views + 1 WHERE id = %d", $event_id ) );

        // --- 7. ORGANIZATOR ---
$organizer_name        = __( 'Nie podano organizatora', 'devents' );
$organizer_is_linkable = false;
$organizer_id          = 0;
$institution_id        = 0;
$author_user           = get_userdata( $event->user_id );
$author_role           = 'unknown';

// KROK 1: Sprawdź institution_id bezpośrednio z events_list
// To jest najbardziej niezawodne źródło — ustawiane przy publikacji.
$direct_institution_id = ! empty( $event->institution_id ) ? intval( $event->institution_id ) : 0;

if ( $direct_institution_id ) {
    $inst_table = $wpdb->prefix . 'devents_institutions';
    $inst_data  = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name FROM {$inst_table} WHERE id = %d",
        $direct_institution_id
    ) );

    if ( $inst_data ) {
        $institution_id        = $inst_data->id;
        $organizer_id          = $inst_data->id;
        $organizer_name        = $inst_data->name;
        $organizer_is_linkable = true;

        // Ustaw rolę autora jeśli możliwe
        if ( $author_user ) {
            $author_role = ( (array) $author_user->roles )[0] ?? 'subscriber';
        }
    }
}

// KROK 2: Fallback — szukaj przez user_id autora (stara logika)
// Używana gdy institution_id nie jest w events_list (starsze wpisy).
if ( ! $institution_id && $author_user ) {
    $user_roles  = (array) $author_user->roles;
    $author_role = $user_roles[0] ?? 'subscriber';
    $is_organizer = in_array( 'organizer', $user_roles )
                 || in_array( 'master_organizer', $user_roles )
                 || in_array( 'administrator', $user_roles );

    if ( $is_organizer ) {
        $inst_id = function_exists( 'devents_get_user_institution_id' )
            ? devents_get_user_institution_id( $author_user->ID )
            : 0;
        $org_name_final = '';

        if ( $inst_id ) {
            $institution_id = $inst_id;
            $inst_table     = $wpdb->prefix . 'devents_institutions';
            $inst_data      = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$inst_table} WHERE id = %d",
                $inst_id
            ) );
            if ( $inst_data ) $org_name_final = $inst_data->name;
            $organizer_id = $inst_id;
        }

        if ( ! $org_name_final ) {
            $data_packet    = get_user_meta( $author_user->ID, '_devents_organizer_data', true );
            $org_name_final = $data_packet['org_name'] ?? '';
            $organizer_id   = $author_user->ID;
        }

        $organizer_name        = $org_name_final ?: $author_user->display_name;
        $organizer_is_linkable = true;

    } else {
        // Subscriber/nieznana rola — użyj other_organizer jeśli podane
        if ( ! empty( $event->other_organizer ) ) {
            $organizer_name = $event->other_organizer;
        }
    }
}

// KROK 3: Ostateczny fallback — other_organizer
if ( $organizer_name === __( 'Nie podano organizatora', 'devents' )
     && ! empty( $event->other_organizer ) ) {
    $organizer_name = $event->other_organizer;
}

        // --- 8. RECENZJE ---
        $reviews_table   = $wpdb->prefix . 'devents_reviews';
        $users_table     = $wpdb->prefix . 'users';
        $all_reviews_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.user_id, r.is_anonymous, r.comment, r.parent_id,
                    DATE_FORMAT(r.created_at, '%%d.%%m.%%Y') AS created_at,
                    CASE WHEN r.is_anonymous = 1 OR u.display_name IS NULL THEN 'Anonim' ELSE u.display_name END AS author
            FROM {$reviews_table} r
            LEFT JOIN {$users_table} u ON r.user_id = u.ID
            WHERE r.item_id = %d AND r.item_type = 'event' AND r.is_approved = 1
            ORDER BY r.created_at ASC",
            $event_id
        ) );

        $reviews_by_id = [];
        $reviews       = [];
        foreach ( $all_reviews_raw as $review ) {
            $review->replies              = [];
            $reviews_by_id[ $review->id ] = $review;
        }
        foreach ( $reviews_by_id as $id => $review ) {
            if ( $review->parent_id && isset( $reviews_by_id[ $review->parent_id ] ) ) {
                $reviews_by_id[ $review->parent_id ]->replies[] = $review;
            } else {
                $reviews[] = $review;
            }
        }
        $reviews = array_reverse( $reviews );

        // System ocen (gwiazdki) został zastąpiony Pytaniami do organizatora — bez ratingu.
        $rating_count   = 0;
        $average_rating = 0;

        // --- 9. OBSERWOWANIE (FOLLOW) ---
        $is_followed = false;
        if ( is_user_logged_in() && $author_user ) {
            $is_followed = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}devents_subscriptions WHERE subscriber_user_id = %d AND organizer_user_id = %d",
                get_current_user_id(),
                $author_user->ID
            ) );
        }

        // --- 10. DOSTĘPNOŚĆ (MAPOWANIE Z JSON → ISO) ---
        $accessibility_items    = [];
        $selected_acc_languages = [];

        $acc_json = json_decode( $event->accessibility, true );
        $sign_dict   = function_exists( 'devents_get_sign_languages' )   ? devents_get_sign_languages()   : [];

        if ( is_array( $acc_json ) && ! empty( $acc_json ) ) {
            $acc_ids     = array_map( 'intval', array_keys( $acc_json ) );
            $types_table = $wpdb->prefix . 'devents_accessibility_types';
            $acc_types_raw = $wpdb->get_results(
                "SELECT id, name, icon, sort_order FROM {$types_table} WHERE id IN (" . implode( ',', $acc_ids ) . ") ORDER BY sort_order ASC"
            );

            foreach ( $acc_types_raw as $type ) {
                $type->name = __( $type->name, 'devents' );
                $accessibility_items[] = $type;
                $langs_raw       = isset( $acc_json[ $type->id ] ) && is_array( $acc_json[ $type->id ] ) ? $acc_json[ $type->id ] : [];
                $processed_langs = [];

                foreach ( $langs_raw as $iso_code ) {
                    $lang_data = $sign_dict[ $iso_code ] ?? $spoken_langs[ $iso_code ] ?? null;
                    if ( $lang_data ) {
                        $processed_langs[] = [
                            'code' => $iso_code,
                            'name' => $lang_data['name'],
                            'abbr' => $lang_data['abbr'] ?? strtoupper($iso_code),
                        ];
                    } else {
                        $processed_langs[] = [
                            'code' => $iso_code,
                            'name' => strtoupper( $iso_code ),
                            'abbr' => strtoupper( $iso_code ),
                        ];
                    }
                }
                $selected_acc_languages[ $type->id ] = $processed_langs;
            }
        }

        return $this->twig_helper->render( 'single/event', [
            'event'                   => $event,
            'content_versions'        => $content_versions,
            'active_version_code'     => $active_version_code,
            'author_id'               => $event->user_id,
            'author_role'             => $author_role,
            'institution_id'          => $institution_id,
            'organizer_name'          => $organizer_name,
            'organizer_is_linkable'   => $organizer_is_linkable,
            'organizer_id'            => $organizer_id,
            'home_url'                => home_url(),
            'is_user_logged_in'       => is_user_logged_in(),
            'current_url'             => get_permalink(),
            'reviews'                 => $reviews,
            'average_rating'          => $average_rating,
            'rating_count'            => $rating_count,
            'review_nonce'            => wp_create_nonce( 'devents_review_nonce' ),
            'is_organizer_followed'   => $is_followed,
            'accessibility_items'     => $accessibility_items,
            'selected_acc_languages'  => $selected_acc_languages,
            'unique_sign_languages'   => method_exists($this, 'get_unique_sign_languages') ? $this->get_unique_sign_languages() : [],
        ] );
    }

    /**
     * =====================================================================
     * BLOK 1: UNIWERSALNE PRZETWARZANIE WYDARZEŃ (DRY Principle)
     * v13.6 - i18n, Slugs, Split Address
     * =====================================================================
     */
    private function prepare_events_for_display( $raw_events ) {
        if ( empty( $raw_events ) ) {
            return [ 'events' => [], 'selected_acc_languages' => [] ];
        }

        // --- Inicjalizacja słowników ---
        $current_lang       = function_exists('determine_locale') ? substr(determine_locale(), 0, 2) : 'pl';
        // FIX: Poprawna nazwa helpera dla kategorii
        $all_categories     = function_exists('devents_get_event_categories') ? devents_get_event_categories() : [];
        $all_delivery_modes = function_exists('devents_get_event_methods') ? devents_get_event_methods() : [];
        
        $accessibility_types = $this->get_accessibility_types();
        $all_acc_types       = array_column( $accessibility_types, null, 'id' );
        
        $spoken_langs_dict   = function_exists('devents_get_spoken_languages') ? devents_get_spoken_languages() : [];
        $sign_langs_dict     = function_exists('devents_get_sign_languages') ? devents_get_sign_languages() : [];

        // Dodatkowe dane dla zalogowanych (Ulubione/Obserwowane)
        $user_id = get_current_user_id();
        $favorite_ids = [];
        $followed_ids = [];
        
        if ( $user_id > 0 ) {
            global $wpdb;
            // Cache per-żądanie: na stronie głównej prepare_events_for_display jest wołane
            // kilka razy (wyróżnione/najbliższe/wszystkie/popularne) — odpytujemy bazę raz.
            static $cached_uid = null, $cached_fav = null, $cached_fol = null;
            if ( $cached_uid === $user_id && is_array( $cached_fav ) ) {
                $favorite_ids = $cached_fav;
                $followed_ids = $cached_fol;
            } else {
                $favorite_ids = $wpdb->get_col($wpdb->prepare("SELECT event_id FROM {$wpdb->prefix}devents_user_favorites WHERE user_id = %d", $user_id));
                $followed_ids = $wpdb->get_col($wpdb->prepare("SELECT organizer_user_id FROM {$wpdb->prefix}devents_subscriptions WHERE subscriber_user_id = %d", $user_id));
                $cached_uid = $user_id;
                $cached_fav = $favorite_ids;
                $cached_fol = $followed_ids;
            }
        }

        // Mapy do wyszukiwania O(1) zamiast in_array() O(N) na każdej karcie.
        $favorite_map = array_flip( $favorite_ids );
        $followed_map = array_flip( $followed_ids );

        $processed_events = [];
        $all_selected_acc_languages = [];

        $now_ts = current_time('timestamp');

        foreach ( $raw_events as $event ) {
            $event = stripslashes_deep( $event );

            // 0. Obsługa i formatowanie nowego adresu z JSON (Fallback dla braku pól w DB)
            if ( ! empty( $event->address_json ) ) {
                $parsed_address = json_decode( $event->address_json, true );
                if ( is_array( $parsed_address ) ) {
                    $event->address_name       = $parsed_address['name'] ?? '';
                    $event->address_street     = $parsed_address['street'] ?? '';
                    $event->address_building   = $parsed_address['building_no'] ?? '';
                    $event->address_city       = $parsed_address['city'] ?? '';
                    $event->event_country_code = $parsed_address['country'] ?? 'PL';
                }
            }

            // 1. Tłumaczenia treści — wspólny, globalny helper (te same pola na każdej liście kart)
            $event = devents_apply_event_translation( $event );

            // 2. Mapowanie Slugów (Kategorie i Metody) na przetłumaczone Etykiety
            $event->category_label = $all_categories[$event->category] ?? ucfirst($event->category);
            $event->delivery_mode_label = $all_delivery_modes[$event->delivery_mode] ?? ucfirst($event->delivery_mode);

            // 3. Daty i Permalink
            $start_ts = strtotime( $event->start_datetime );
            $end_ts   = ( ! empty( $event->end_datetime ) && $event->end_datetime !== '0000-00-00 00:00:00' ) ? strtotime( $event->end_datetime ) : $start_ts;
            
            $event->is_current = ( $start_ts <= $now_ts && $end_ts >= $now_ts );
            $event->permalink  = home_url( '/wydarzenia/' . sanitize_title( $event->title ) . '-' . $event->id );

            // 4. Ulubione i Zapisani (wyszukiwanie O(1) na mapach)
            $event->is_favorite = isset( $favorite_map[ $event->id ] );
            $event->is_followed = isset( $followed_map[ $event->user_id ] );

            // 5. Cena (z JSON)
            $event->price = 0;
            if (!empty($event->ticket_types)) {
                $tickets = json_decode( $event->ticket_types, true );
                if ( ! empty( $tickets ) && isset( $tickets[0]['price'] ) ) {
                    $event->price = floatval( str_replace( ',', '.', $tickets[0]['price'] ) );
                }
            }

            // 6. Dostępność
            $event->accessibility_items = [];
            $event_acc_languages        = [];

            if ( ! empty( $event->accessibility ) ) {
                $my_acc = json_decode( $event->accessibility, true );
                if ( is_array( $my_acc ) ) {
                    foreach ( $my_acc as $id => $langs ) {
                        if ( ! isset( $all_acc_types[ $id ] ) ) continue;

                        $type_obj = clone $all_acc_types[ $id ];
                        $type_obj->name = __( $type_obj->name, 'devents' ); 
                        
                        $formatted_langs = [];
                        if ( is_array( $langs ) && ! empty( $langs ) ) {
                            foreach ( $langs as $code ) {
                                $lang_data = ['code' => $code, 'abbr' => strtoupper($code), 'name' => strtoupper($code)];
                                
                                if ( isset( $spoken_langs_dict[ $code ] ) ) {
                                    $lang_data['name'] = $spoken_langs_dict[ $code ]['name'];
                                    $lang_data['abbr'] = $spoken_langs_dict[ $code ]['abbr'] ?? $lang_data['abbr'];
                                } elseif ( isset( $sign_langs_dict[ $code ] ) ) {
                                    $lang_data['name'] = $sign_langs_dict[ $code ]['name'];
                                    $lang_data['abbr'] = $sign_langs_dict[ $code ]['abbr'] ?? $lang_data['abbr'];
                                }
                                $formatted_langs[] = $lang_data;
                            }
                            $event_acc_languages[ $id ] = $formatted_langs;
                        }
                        $event->accessibility_items[] = $type_obj;
                    }
                }
            }

            if ( ! empty( $event_acc_languages ) ) {
                $all_selected_acc_languages[ $event->id ] = $event_acc_languages;
            }

            $processed_events[] = $event;
        }

        return [
            'events'                 => $processed_events,
            'selected_acc_languages' => $all_selected_acc_languages
        ];
    }

    /* =====================================================================
       BLOK 2: WYSZUKIWARKA WYDARZEŃ (Wersja Odchudzona z DRY)
       ===================================================================== */
	public function render_event_search() {
	    global $wpdb;
	    $event_table_name = $wpdb->prefix . 'events_list';
	    $occasions_table  = $wpdb->prefix . 'devents_special_occasions';
	    $inst_table_name  = $wpdb->prefix . 'devents_institutions';
	
	    $all_categories      = function_exists('devents_get_event_categories') ? devents_get_event_categories() : [];
	    $all_delivery_modes  = function_exists('devents_get_event_methods') ? devents_get_event_methods() : [];
	    $accessibility_types = $this->get_accessibility_types();
	
	    $now = current_time('mysql');
	
	    $all_special_occasions = $wpdb->get_results($wpdb->prepare("
	        SELECT DISTINCT so.id, so.name 
	        FROM {$occasions_table} so
	        JOIN {$event_table_name} e ON e.special_occasion_id = so.id
	        WHERE e.verified = 1 
	        AND (CASE WHEN e.end_datetime IS NULL OR e.end_datetime = '0000-00-00 00:00:00' 
	            THEN e.start_datetime ELSE e.end_datetime END) >= %s
	        ORDER BY so.name ASC
	    ", $now));
	
	    // Odczyt globalnych języków z GET
	    $global_spoken_langs = isset($_GET['global_spoken_langs']) ? (array) $_GET['global_spoken_langs'] : [];
	    $global_sign_langs   = isset($_GET['global_sign_langs']) ? (array) $_GET['global_sign_langs'] : [];
	    // Domyślne filtry użytkownika — gdy filtr NIE jest jawnie w URL, użyj zapisanych preferencji.
	    if ( is_user_logged_in() ) {
	        $__uid = get_current_user_id();
	        if ( ! array_key_exists( 'global_spoken_langs', $_GET ) ) { $__d = get_user_meta( $__uid, 'default_spoken_lang', true ); if ( $__d ) $global_spoken_langs = [ $__d ]; }
	        if ( ! array_key_exists( 'global_sign_langs', $_GET ) )   { $__d = get_user_meta( $__uid, 'default_sign_lang', true );   if ( $__d ) $global_sign_langs = [ $__d ]; }
	    }
	    $country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : '';
	    if ( ! array_key_exists( 'country', $_GET ) && is_user_logged_in() ) { $country = get_user_meta( get_current_user_id(), 'default_country', true ) ?: ''; }
	
	    $filters = [
	        'search_query'     => isset($_GET['search_query']) ? sanitize_text_field(wp_unslash($_GET['search_query'])) : '',
	        'location'         => isset($_GET['location']) ? sanitize_text_field(wp_unslash($_GET['location'])) : '',
	        'category'         => isset($_GET['category']) ? sanitize_key($_GET['category']) : '', 
	        'delivery_mode'    => isset($_GET['delivery_mode']) ? sanitize_key($_GET['delivery_mode']) : '', 
	        'accessibility'    => isset($_GET['accessibility']) && is_array($_GET['accessibility']) ? array_map('absint', $_GET['accessibility']) : [],
	        'start_date'       => isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '',
	        'end_date'         => isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '',
	        'special_occasion' => isset($_GET['special_occasion']) ? intval($_GET['special_occasion']) : 0,
	        'age_rating'       => isset($_GET['age_rating']) ? sanitize_text_field($_GET['age_rating']) : '',
	        'only_current'     => isset($_GET['only_current']),
	        'sort'             => isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'date_asc',
	    ];

	    // PROFIL DOSTĘPNOŚCI: gdy zalogowany użytkownik nie wybrał ręcznie filtra dostępności
	    // (i nie poprosił o „pokaż wszystkie"), domyślnie zawężamy listę do jego zapisanych
	    // potrzeb (np. PJM + napisy). Ustawia się w panelu → „Moje potrzeby".
	    $accessibility_prefs_applied = false;
	    if ( empty($filters['accessibility']) && ! isset($_GET['accessibility_all']) && is_user_logged_in() ) {
	        $prefs = get_user_meta( get_current_user_id(), 'devents_accessibility_prefs', true );
	        if ( is_array($prefs) && ! empty($prefs) ) {
	            $filters['accessibility'] = array_filter( array_map('absint', $prefs) );
	            $accessibility_prefs_applied = ! empty($filters['accessibility']);
	        }
	    }

	    $per_page     = 24;
	    $current_page = max(1, isset($_GET['page']) ? intval($_GET['page']) : 1);
	
	    $sql_conditions = ["e.verified = 1"];
	    $params         = [];
	
	    $sql_conditions[] = $wpdb->prepare(
	        "(CASE WHEN e.end_datetime IS NULL OR e.end_datetime = '0000-00-00 00:00:00' THEN e.start_datetime ELSE e.end_datetime END) >= %s",
	        $now
	    );
	
	    if (!empty($filters['search_query'])) {
	        $sql_conditions[] = "(e.title LIKE %s OR e.description LIKE %s)";
	        $params[] = '%' . $wpdb->esc_like($filters['search_query']) . '%';
	        $params[] = '%' . $wpdb->esc_like($filters['search_query']) . '%';
	    }
	
	    if (!empty($filters['location'])) {
	        $sql_conditions[] = "(e.location LIKE %s OR e.address_city LIKE %s)";
	        $params[] = '%' . $wpdb->esc_like($filters['location']) . '%';
	        $params[] = '%' . $wpdb->esc_like($filters['location']) . '%';
	    }
	
	    if (!empty($filters['category'])) {
	        $sql_conditions[] = "e.category = %s";
	        $params[] = $filters['category'];
	    }
	
	    if (!empty($filters['delivery_mode'])) {
	        $sql_conditions[] = "e.delivery_mode = %s";
	        $params[] = $filters['delivery_mode'];
	    }
	    if ( ! empty( $country ) ) {
	        $sql_conditions[] = "e.event_country_code = %s";
	        $params[] = $country;
	    }
	
	    // --- FILTROWANIE DOSTĘPNOŚCI I GLOBALNYCH JĘZYKÓW ---
	    if (!empty($filters['accessibility'])) {
	        $acc_queries = [];
	        
	        // Budowanie mapy typów dostępności dla szybkiego dostępu (foniczny / migowy)
	        $acc_type_map = [];
	        if (!empty($accessibility_types)) {
	            foreach ($accessibility_types as $type) {
	                $acc_type_map[$type->id] = [
	                    'spoken' => $type->requires_spoken_lang,
	                    'sign'   => $type->requires_sl_lang
	                ];
	            }
	        }
	
	        foreach ($filters['accessibility'] as $acc_id) {
	            $is_spoken = isset($acc_type_map[$acc_id]) && $acc_type_map[$acc_id]['spoken'] == 1;
	            $is_sign   = isset($acc_type_map[$acc_id]) && $acc_type_map[$acc_id]['sign'] == 1;
	
	            $langs_to_check = [];
	            if ($is_spoken && !empty($global_spoken_langs)) {
	                $langs_to_check = $global_spoken_langs;
	            } elseif ($is_sign && !empty($global_sign_langs)) {
	                $langs_to_check = $global_sign_langs;
	            }
	
	            if (!empty($langs_to_check)) {
	                $lang_ors = [];
	                foreach ($langs_to_check as $lang) {
	                    $lang_ors[] = $wpdb->prepare("JSON_CONTAINS(e.accessibility, %s, %s)", '"' . $lang . '"', '$."' . $acc_id . '"');
	                }
	                $acc_queries[] = "(" . implode(" OR ", $lang_ors) . ")";
	            } else {
	                $acc_queries[] = $wpdb->prepare("JSON_CONTAINS_PATH(e.accessibility, 'one', %s)", '$."' . $acc_id . '"');
	            }
	        }
	        
	        if (!empty($acc_queries)) {
	            $sql_conditions[] = "(" . implode(" AND ", $acc_queries) . ")";
	        }
	    }
	
	    $where_clause = "WHERE " . implode(" AND ", $sql_conditions);
	    $order_clause = "ORDER BY e.start_datetime ASC";
	    switch ($filters['sort']) {
	        case 'date_desc':  $order_clause = "ORDER BY e.start_datetime DESC"; break;
	        case 'price_asc':  $order_clause = "ORDER BY e.price ASC, e.start_datetime ASC"; break;
	        case 'price_desc': $order_clause = "ORDER BY e.price DESC, e.start_datetime ASC"; break;
	        case 'name_asc':   $order_clause = "ORDER BY e.title ASC"; break;
	    }
	
	    $total_results = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$event_table_name} e {$where_clause}", $params));
	    $total_pages   = max(1, (int) ceil($total_results / $per_page));
	    $offset        = ($current_page - 1) * $per_page;
	
	    $sql = "SELECT e.*, so.slug as special_occasion_slug, so.color as special_occasion_color, inst.name as institution_name
	            FROM {$event_table_name} e
	            LEFT JOIN {$occasions_table} so ON e.special_occasion_id = so.id
	            LEFT JOIN {$inst_table_name} inst ON e.institution_id = inst.id
	            {$where_clause} {$order_clause} LIMIT %d OFFSET %d";
	
	    $final_params = array_merge($params, [$per_page, $offset]);
	    $all_events_raw = $wpdb->get_results($wpdb->prepare($sql, $final_params));
	
	    $processing_result = $this->prepare_events_for_display($all_events_raw);
	    $base_url = strtok(home_url($_SERVER['REQUEST_URI']), '?');
	
	    return $this->twig_helper->render('pages/event-search', [
	        'filters'                 => $filters,
	        'all_events'              => $processing_result['events'],
	        'all_categories'          => $all_categories,
	        'all_delivery_modes'      => $all_delivery_modes,
	        'accessibility_types'     => $accessibility_types,
	        'all_special_occasions'   => $all_special_occasions,
	        'is_user_logged_in'       => is_user_logged_in(),
	        'current_base_url'        => $base_url,
	        'accessibility_prefs_applied' => $accessibility_prefs_applied,
	        'show_all_url'            => add_query_arg( 'accessibility_all', '1', $base_url ),
	        'prefs_url'               => home_url( '/panel-uzytkownika/?view=ustawienia' ) . '#tab-dostepnosc',
	        'per_page'                => $per_page,
	        'current_page'            => $current_page,
	        'total_pages'             => $total_pages,
	        'total_results'           => $total_results, // <--- TEGO BRAKOWAŁO (prawdziwa liczba z bazy)
	        
	        // Języki globalne
	        'all_spoken_langs'        => function_exists('devents_get_spoken_languages') ? devents_get_spoken_languages() : [],
	        'all_sign_langs'          => function_exists('devents_get_sign_languages') ? devents_get_sign_languages() : [],
	        'global_spoken_langs'     => $global_spoken_langs,
	        'global_sign_langs'       => $global_sign_langs,
	    ]);
	}


    /* =====================================================================
       BLOK 3: HOMEPAGE (Wersja Odchudzona z DRY)
       ===================================================================== */
    public function render_homepage() {
        global $wpdb;
        $table           = "{$wpdb->prefix}events_list";
        $occasions_table = "{$wpdb->prefix}devents_special_occasions";
        $now             = current_time( 'mysql' );

        // Helper do wielokrotnego wzywania przygotowania
        $process_func = function($raw_data) {
            $res = $this->prepare_events_for_display($raw_data);
            return $res['events'];
        };

        // Zbiorcza mapa języków dostępności z wszystkich zapytań
        $global_selected_acc_langs = [];
        $merge_langs = function($raw_data) use (&$global_selected_acc_langs) {
            $res = $this->prepare_events_for_display($raw_data);
            foreach($res['selected_acc_languages'] as $eid => $langs) {
                $global_selected_acc_langs[$eid] = $langs;
            }
            return $res['events'];
        };

        // 1. Featured Events
        $featured_events_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, so.slug as special_occasion_slug, so.color as special_occasion_color 
            FROM {$table} e
            LEFT JOIN {$occasions_table} so ON e.special_occasion_id = so.id
            WHERE e.verified = 1 AND e.is_featured = 1 AND e.featured_until >= %s
            ORDER BY e.start_datetime ASC",
            $now
        ) );
        $featured_events = $merge_langs($featured_events_raw);

        // 2. Next Event
        $next_event_raw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE verified = 1 AND start_datetime > %s ORDER BY start_datetime ASC LIMIT 1",
            $now
        ) );
        $next_event = $next_event_raw ? $merge_langs([$next_event_raw])[0] : null;

        // 3. All Active Events
        $all_events_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, so.slug as special_occasion_slug, so.color as special_occasion_color 
            FROM {$table} e
            LEFT JOIN {$occasions_table} so ON e.special_occasion_id = so.id
            WHERE e.verified = 1 AND (
                e.end_datetime >= %s 
                OR ( (e.end_datetime IS NULL OR e.end_datetime = '0000-00-00 00:00:00') AND e.start_datetime >= %s )
            )
            ORDER BY e.start_datetime ASC",
            $now, $now
        ) );

        $special_occasions_with_events = [];
        $event_ids_in_occasions        = [];
        if ( ! empty( $all_events_raw ) ) {
            $occasion_ids = array_unique( array_filter( wp_list_pluck( $all_events_raw, 'special_occasion_id' ) ) );
            if ( ! empty( $occasion_ids ) ) {
                $ids_placeholder       = implode( ',', array_fill( 0, count( $occasion_ids ), '%d' ) );
                $active_occasions_data = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name, slug, color FROM {$occasions_table} WHERE id IN ({$ids_placeholder})",
                    $occasion_ids
                ) );
                
                foreach ( $active_occasions_data as $occasion ) {
                    $occasion_events = [];
                    foreach ( $all_events_raw as $event ) {
                        if ( $event->special_occasion_id == $occasion->id ) {
                            $occasion_events[]        = $event;
                            $event_ids_in_occasions[] = $event->id;
                        }
                    }
                    if ( ! empty( $occasion_events ) ) {
                        $special_occasions_with_events[] = [
                            'details' => $occasion,
                            'events'  => $merge_langs( $occasion_events ),
                        ];
                    }
                }
            }
        }

        $upcoming_and_current_raw = array_filter( $all_events_raw, function ( $event ) use ( $event_ids_in_occasions ) {
            return ! in_array( $event->id, $event_ids_in_occasions );
        } );
        $all_events_processed = $merge_langs($upcoming_and_current_raw);

        // 4. Popular Events
        $popular_events_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} 
            WHERE verified = 1 
            AND (CASE WHEN end_datetime IS NULL OR end_datetime = '0000-00-00 00:00:00' THEN start_datetime ELSE end_datetime END) >= %s 
            ORDER BY views DESC LIMIT 3",
            $now
        ) );
        $popular_events = $merge_langs($popular_events_raw);

        // Statystyki do sekcji „Statystyki" na stronie głównej.
        $inst_table = "{$wpdb->prefix}devents_institutions";
        $stats = [
            'events'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE verified = 1" ),
            'institutions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inst_table} WHERE verification_status = 1" ),
            'views'        => (int) $wpdb->get_var( "SELECT COALESCE(SUM(views),0) FROM {$table} WHERE verified = 1" ),
        ];

        return DEvents_Twig_Helper::get_instance()->render( 'pages/homepage', [
            'featured_events'               => $featured_events,
            'next_event'                    => $next_event,
            'all_events'                    => $all_events_processed,
            'popular_events'                => $popular_events,
            'special_occasions_with_events' => $special_occasions_with_events,
            'is_user_logged_in'             => is_user_logged_in(),
            'active_ui_lang'                => DEvents_I18n::get_instance()->get_ui_language(),
            'ui_languages'                  => DEvents_I18n::$supported_ui_languages,
            'selected_acc_languages'        => $global_selected_acc_langs,
            'stats'                         => $stats,
            'map_html'                      => do_shortcode( '[devents_mapa_wydarzen]' ),
            'urls'                          => [
                'events'     => home_url( '/wydarzenia/' ),
                'register'   => home_url( '/zarejestruj-sie/' ),
                'register_org' => home_url( '/zarejestruj-sie/' ),
                'about'      => home_url( '/o-nas/' ),
            ],
        ] );
    }


    /* =====================================================================
       MOJE WYDARZENIA (Lista w panelu)
       ===================================================================== */

    public function render_my_events_list() {
        return $this->render_my_events_list_logic();
    }

    public function render_my_events_list_logic() {
        if ( ! is_user_logged_in() ) return '';

        $user_id = get_current_user_id();
        global $wpdb;

        $table_events   = $wpdb->prefix . 'events_list';
        $table_inst     = $wpdb->prefix . 'devents_institutions';
        $table_users    = $wpdb->prefix . 'users';
        $table_usermeta = $wpdb->prefix . 'usermeta';
        $now            = current_time( 'mysql' );

        $user_data  = get_userdata( $user_id );
        $user_roles = (array) $user_data->roles;
        $is_master  = devents_user_is_master( $user_id );

        $my_inst_id      = (int) get_user_meta( $user_id, 'devents_institution_id', true );
        $allowed_inst_ids = [ $my_inst_id ];

        if ( $is_master && $my_inst_id > 0 ) {
            $sub_unit_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table_inst} WHERE parent_id = %d",
                $my_inst_id
            ) );
            if ( ! empty( $sub_unit_ids ) ) {
                $allowed_inst_ids = array_merge( $allowed_inst_ids, array_map( 'intval', $sub_unit_ids ) );
            }
        }

        $status_filter   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        $search_query    = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $filter_category = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : '';
        $orderby         = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
        $order           = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $per_page        = isset( $_GET['per_page'] ) ? max( 1, intval( $_GET['per_page'] ) ) : 20;
        $paged           = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset          = ( $paged - 1 ) * $per_page;

        // Filtry jak w wyszukiwarce: dostępność (wiele), zakres dat, tylko wyróżnione.
        $filter_accessibility = isset( $_GET['accessibility'] ) && is_array( $_GET['accessibility'] )
            ? array_filter( array_map( 'absint', $_GET['accessibility'] ) ) : [];
        $filter_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $filter_date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( $_GET['date_to'] )   : '';
        $filter_featured  = ! empty( $_GET['featured'] );

        // Bezpieczne orderby (whitelist)
        $allowed_orderby = [ 'created_at', 'start_datetime', 'title', 'views' ];
        if ( ! in_array( $orderby, $allowed_orderby ) ) {
            $orderby = 'created_at';
        }

        $build_where = function ( $is_archive ) use ( $wpdb, $user_id, $allowed_inst_ids, $my_inst_id, $now, $status_filter, $search_query, $filter_category, $filter_accessibility, $filter_date_from, $filter_date_to, $filter_featured ) {
            $where  = [];
            $params = [];

            if ( ! empty( $allowed_inst_ids ) && $allowed_inst_ids[0] > 0 ) {
                $placeholders = implode( ',', array_fill( 0, count( $allowed_inst_ids ), '%d' ) );
                $where[]      = "e.institution_id IN ($placeholders)";
                foreach ( $allowed_inst_ids as $id ) $params[] = $id;
            } else {
                $where[]  = "e.user_id = %d";
                $params[] = $user_id;
            }

            $end_date_col = "(CASE WHEN e.end_datetime IS NOT NULL AND e.end_datetime != '0000-00-00 00:00:00' THEN e.end_datetime ELSE e.start_datetime END)";

            if ( $is_archive ) {
                if ( $status_filter === 'cancelled' ) {
                    $where[] = "e.verified = 4";
                } else {
                    $where[]  = "e.verified = 1 AND $end_date_col < %s";
                    $params[] = $now;
                }
            } else {
                if ( $status_filter === 'archived' ) return false;
                $where[]  = "(e.verified != 1 OR $end_date_col >= %s)";
                $params[] = $now;

                if ( $status_filter === 'pending' ) $where[] = "e.verified IN (0, 3)";
                elseif ( $status_filter === 'draft' ) $where[] = "e.verified = 2";
                elseif ( $status_filter === 'published' ) $where[] = "e.verified = 1";
            }

            if ( ! empty( $search_query ) ) {
                $where[]  = "e.title LIKE %s";
                $params[] = '%' . $wpdb->esc_like( $search_query ) . '%';
            }
            if ( ! empty( $filter_category ) ) {
                $where[]  = "e.category = %s";
                $params[] = $filter_category;
            }

            // Dostępność (wiele — wydarzenie musi mieć WSZYSTKIE zaznaczone), jak w wyszukiwarce.
            if ( ! empty( $filter_accessibility ) ) {
                $rel_t     = $wpdb->prefix . 'devents_event_accessibility';
                $acc_count = count( $filter_accessibility );
                $acc_ph    = implode( ',', array_fill( 0, $acc_count, '%d' ) );
                $where[]   = "(SELECT COUNT(DISTINCT ea_a.accessibility_id) FROM {$rel_t} ea_a WHERE ea_a.event_id = e.id AND ea_a.accessibility_id IN ({$acc_ph})) = %d";
                foreach ( $filter_accessibility as $aid ) $params[] = $aid;
                $params[]  = $acc_count;
            }

            if ( ! empty( $filter_date_from ) ) {
                $where[]  = "e.start_datetime >= %s";
                $params[] = $filter_date_from . ' 00:00:00';
            }
            if ( ! empty( $filter_date_to ) ) {
                $where[]  = "e.start_datetime <= %s";
                $params[] = $filter_date_to . ' 23:59:59';
            }
            if ( $filter_featured ) {
                $where[] = "e.is_featured = 1";
            }

            return [ 'sql' => implode( ' AND ', $where ), 'params' => $params ];
        };

        $base_select = "SELECT 
                            e.*, 
                            u.display_name, 
                            i.name as institution_name,
                            um1.meta_value as creator_first_name,
                            um2.meta_value as creator_last_name
                        FROM {$table_events} e 
                        LEFT JOIN {$table_users} u ON e.user_id = u.ID 
                        LEFT JOIN {$table_inst} i ON e.institution_id = i.id
                        LEFT JOIN {$table_usermeta} um1 ON e.user_id = um1.user_id AND um1.meta_key = 'first_name'
                        LEFT JOIN {$table_usermeta} um2 ON e.user_id = um2.user_id AND um2.meta_key = 'last_name'";

        $active_events   = [];
        $archived_events = [];
        $pagination      = null;

        if ( $status_filter !== 'archived' && $status_filter !== 'cancelled' ) {
            $q = $build_where( false );
            if ( $q ) {
                $total           = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_events} e WHERE " . $q['sql'], $q['params'] ) );
                $sql             = "{$base_select} WHERE {$q['sql']} ORDER BY e.{$orderby} {$order} LIMIT %d OFFSET %d";
                $active_events   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $q['params'], [ $per_page, $offset ] ) ) );
                $pagination      = [ 'total_pages' => ceil( $total / $per_page ), 'current' => $paged, 'total_items' => $total ];
            }
        }

        if ( $status_filter === 'all' || $status_filter === 'archived' || $status_filter === 'cancelled' ) {
            $q = $build_where( true );
            if ( $q ) {
                $sql             = "{$base_select} WHERE {$q['sql']} ORDER BY e.end_datetime DESC LIMIT 10";
                $archived_events = $wpdb->get_results( $wpdb->prepare( $sql, $q['params'] ) );
            }
        }

        // Globalne tłumaczenie kart (Zarządzaj wydarzeniami) — obiekty mutowane w miejscu.
        if ( is_array( $active_events ) )   { foreach ( $active_events as $e )   { devents_apply_event_translation( $e ); } }
        if ( is_array( $archived_events ) ) { foreach ( $archived_events as $e ) { devents_apply_event_translation( $e ); } }

        return $this->twig_helper->render( 'user/my-events', [
            'active_events'         => $active_events,
            'archived_events'       => $archived_events,
            'org_action_nonce'      => wp_create_nonce( 'devents_org_action_nonce' ),
            'is_master'             => $is_master,
            'my_inst_id'            => $my_inst_id,
            'categories'            => $this->get_settings_safe( 'event_category' ),
            'accessibility_options' => $this->get_settings_safe( 'event_accessibility' ),
            'accessibility_types'   => $wpdb->get_results( "SELECT id, name, icon FROM {$wpdb->prefix}devents_accessibility_types WHERE is_active = 1 ORDER BY sort_order ASC" ) ?: [],
            'current_filters'       => [
                'status'        => $status_filter,
                'search'        => $search_query,
                'category'      => $filter_category,
                'accessibility' => $filter_accessibility,
                'date_from'     => $filter_date_from,
                'date_to'       => $filter_date_to,
                'featured'      => $filter_featured,
                'orderby'       => $orderby,
                'order'         => $order,
                'per_page'      => $per_page,
            ],
            'pagination' => $pagination,
            'panel_url'  => home_url( '/panel-uzytkownika/' ),
        ] );
    }


    /* =====================================================================
       REMAINING RENDERERS (bez zmian funkcjonalnych, tylko i18n fixes)
       ===================================================================== */

    public function prepare_video_card_data( $video_object ) {
        if ( empty( $video_object ) ) return null;
        $video_object->slug      = sanitize_title( $video_object->title ) . '-' . $video_object->id;
        $video_object->permalink = home_url( '/filmy/' . $video_object->slug );
        $video_object->thumbnail = empty( $video_object->thumbnail_url )
            ? $this->get_video_thumbnail_from_url( $video_object->video_url )
            : $video_object->thumbnail_url;
        return $video_object;
    }

    private function get_video_thumbnail_from_url( $url ) {
        $default = DEW_PLUGIN_URL . 'assets/images/default-thumbnail.png';
        if ( empty( $url ) ) return $default;
        $video_id = '';
        if ( preg_match( '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $m ) ) $video_id = $m[1];
        elseif ( preg_match( '/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m ) ) $video_id = $m[1];
        return $video_id ? "https://i.ytimg.com/vi/{$video_id}/mqdefault.jpg" : $default;
    }

    public function render_news_list_shortcode() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'devents_news';
        $news_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE publish_date <= %s ORDER BY is_pinned DESC, publish_date DESC",
            current_time( 'mysql' )
        ) );
        foreach ( $news_items as $item ) {
            $item->permalink = home_url( '/aktualnosci/' . $item->id . '/' . sanitize_title( $item->title ) );
        }
        return $this->twig_helper->render( 'pages/news-list', [ 'news_items' => $news_items ] );
    }

    public function render_my_favorites_list() {
        if ( ! is_user_logged_in() ) return '';
        global $wpdb;
        $user_id         = get_current_user_id();
        $events_table    = $wpdb->prefix . 'events_list';
        $favorites_table = $wpdb->prefix . 'devents_user_favorites';

        $favorite_events_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.* FROM {$events_table} e
            JOIN {$favorites_table} f ON e.id = f.event_id
            WHERE f.user_id = %d ORDER BY f.added_at DESC",
            $user_id
        ) );

        $favorite_events = [];
        if ( ! empty( $favorite_events_raw ) ) {
            $favorite_events = stripslashes_deep( $favorite_events_raw );
            foreach ( $favorite_events as $event ) {
                devents_apply_event_translation( $event ); // globalne tłumaczenie karty
                $event->permalink   = home_url( '/wydarzenia/' . sanitize_title( $event->title ) . '-' . $event->id );
                $event->is_favorite = true;
            }
        }

        return $this->twig_helper->render( 'user/my-favorites', [ 'events' => $favorite_events ] );
    }

    /**
     * Panel: lista zapisanych wyszukiwań użytkownika (z alertami).
     */
    public function render_saved_searches( $user_id ) {
        if ( ! is_user_logged_in() ) return '';
        global $wpdb;
        $table = $wpdb->prefix . 'devents_saved_searches';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ) );

        $categories = function_exists( 'devents_get_event_categories' ) ? devents_get_event_categories() : [];
        $methods    = function_exists( 'devents_get_event_methods' ) ? devents_get_event_methods() : [];
        $acc_names  = [];
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}devents_accessibility_types" ) as $a ) {
            $acc_names[ (int) $a->id ] = $a->name;
        }

        $items = [];
        foreach ( (array) $rows as $r ) {
            $f = json_decode( $r->filters, true );
            if ( ! is_array( $f ) ) $f = [];

            $chips = [];
            if ( ! empty( $f['search_query'] ) )                                  $chips[] = '„' . $f['search_query'] . '”';
            if ( ! empty( $f['category'] ) && isset( $categories[ $f['category'] ] ) ) $chips[] = $categories[ $f['category'] ];
            if ( ! empty( $f['delivery_mode'] ) && isset( $methods[ $f['delivery_mode'] ] ) ) $chips[] = $methods[ $f['delivery_mode'] ];
            if ( ! empty( $f['location'] ) )                                      $chips[] = $f['location'];
            if ( ! empty( $f['country'] ) )                                       $chips[] = $f['country'];
            foreach ( (array) ( $f['accessibility'] ?? [] ) as $aid ) {
                if ( isset( $acc_names[ (int) $aid ] ) ) $chips[] = $acc_names[ (int) $aid ];
            }

            $items[] = [
                'id'     => (int) $r->id,
                'label'  => $r->label,
                'chips'  => $chips,
                'url'    => function_exists( 'devents_saved_search_results_url' ) ? devents_saved_search_results_url( $f ) : home_url( '/' ),
                'alerts' => ( (int) $r->alerts_enabled === 1 ),
            ];
        }

        return $this->twig_helper->render( 'user/my-saved-searches', [
            'items'      => $items,
            'search_url' => home_url( '/wydarzenia/' ),
        ] );
    }

    public function render_my_reviews_list() {
        if ( ! is_user_logged_in() || ! in_array( 'organizer', (array) wp_get_current_user()->roles ) ) {
            return '<div class="message-box message-box--error">' . __( 'Ta sekcja jest dostępna tylko dla organizatorów.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $organizer_id  = get_current_user_id();
        $reviews_table = $wpdb->prefix . 'devents_reviews';
        $events_table  = $wpdb->prefix . 'events_list';
        $videos_table  = $wpdb->prefix . 'events_materials';
        $users_table   = $wpdb->prefix . 'users';

        $event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$events_table} WHERE user_id = %d", $organizer_id ) );
        $video_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$videos_table} WHERE user_id = %d", $organizer_id ) );

        $where_conditions = [ $wpdb->prepare( "(r.item_type = 'organizer' AND r.item_id = %d)", $organizer_id ) ];
        if ( ! empty( $event_ids ) ) {
            $where_conditions[] = "(r.item_type = 'event' AND r.item_id IN (" . implode( ',', $event_ids ) . "))";
        }
        if ( ! empty( $video_ids ) ) {
            $where_conditions[] = "(r.item_type = 'video' AND r.item_id IN (" . implode( ',', $video_ids ) . "))";
        }

        $all_reviews_raw = $wpdb->get_results(
            "SELECT r.id, r.user_id, r.is_anonymous, r.comment, r.parent_id, r.item_id, r.item_type,
                    DATE_FORMAT(r.created_at, '%d.%m.%Y') AS created_at,
                    CASE WHEN r.is_anonymous = 1 OR u.display_name IS NULL THEN 'Anonim' ELSE u.display_name END AS author
            FROM {$reviews_table} r LEFT JOIN {$users_table} u ON r.user_id = u.ID
            WHERE (" . implode( ' OR ', $where_conditions ) . ") AND r.is_approved = 1
            ORDER BY r.created_at ASC"
        );

        foreach ( $all_reviews_raw as $review ) {
            $review->item_title = __( 'Profilu organizatora', 'devents' );
            $review->item_link  = home_url( '/organizator/' . $organizer_id );
            if ( $review->item_type === 'event' ) {
                $t = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$events_table} WHERE id = %d", $review->item_id ) );
                if ( $t ) {
                    $review->item_title = __( 'Wydarzenia:', 'devents' ) . ' ' . $t;
                    $review->item_link  = home_url( '/wydarzenia/' . sanitize_title( $t ) . '-' . $review->item_id );
                }
            } elseif ( $review->item_type === 'video' ) {
                $t = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$videos_table} WHERE id = %d", $review->item_id ) );
                if ( $t ) {
                    $review->item_title = __( 'Filmu:', 'devents' ) . ' ' . $t;
                    $review->item_link  = home_url( '/filmy/' . sanitize_title( $t ) . '-' . $review->item_id );
                }
            }
        }

        $reviews_by_id     = [];
        $organizer_reviews = [];
        foreach ( $all_reviews_raw as $review ) {
            $review->replies              = [];
            $reviews_by_id[ $review->id ] = $review;
        }
        foreach ( $reviews_by_id as $id => $review ) {
            if ( $review->parent_id && isset( $reviews_by_id[ $review->parent_id ] ) ) {
                $reviews_by_id[ $review->parent_id ]->replies[] = $review;
            } else {
                $organizer_reviews[] = $review;
            }
        }
        $organizer_reviews = array_reverse( $organizer_reviews );

        // Bez ocen gwiazdkowych — system Pytań do organizatora.
        $rating_count   = 0;
        $average_rating = 0;

        return $this->twig_helper->render( 'user/my-reviews', [
            'reviews'        => $organizer_reviews,
            'average_rating' => $average_rating,
            'rating_count'   => $rating_count,
            'review_nonce'   => wp_create_nonce( 'devents_review_nonce' ),
            'item'           => [ 'id' => $organizer_id, 'user_id' => $organizer_id ],
            'item_type'      => 'organizer',
        ] );
    }

    /**
     * „Moje pytania" — pytania zadane przez bieżącego użytkownika + odpowiedzi organizatorów.
     * Dostępne dla każdego zalogowanego użytkownika (nie tylko organizatorów).
     */
    public function render_my_questions_list() {
        if ( ! is_user_logged_in() ) {
            return '<div class="message-box message-box--error">' . __( 'Zaloguj się, aby zobaczyć swoje pytania.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $user_id       = get_current_user_id();
        $reviews_table = $wpdb->prefix . 'devents_reviews';
        $events_table  = $wpdb->prefix . 'events_list';
        $videos_table  = $wpdb->prefix . 'events_materials';
        $users_table   = $wpdb->prefix . 'users';

        // Pytania zadane przez tego użytkownika (parent_id = 0). user_id jest zapisywany
        // nawet przy is_anonymous=1, więc filtrujemy po user_id.
        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.user_id, r.comment, r.parent_id, r.item_id, r.item_type,
                    DATE_FORMAT(r.created_at, '%%d.%%m.%%Y') AS created_at
             FROM {$reviews_table} r
             WHERE r.user_id = %d AND r.parent_id = 0 AND r.is_approved = 1
             ORDER BY r.created_at DESC",
            $user_id
        ) );

        foreach ( $questions as $q ) {
            $q->item_title = __( 'Profil organizatora', 'devents' );
            $q->item_link  = '';
            if ( $q->item_type === 'event' ) {
                $t = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$events_table} WHERE id = %d", $q->item_id ) );
                if ( $t ) { $q->item_title = __( 'Wydarzenie:', 'devents' ) . ' ' . $t; $q->item_link = home_url( '/wydarzenia/' . sanitize_title( $t ) . '-' . $q->item_id ); }
            } elseif ( $q->item_type === 'organizer' ) {
                $q->item_link = home_url( '/organizator/' . $q->item_id );
            } elseif ( $q->item_type === 'video' ) {
                $t = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$videos_table} WHERE id = %d", $q->item_id ) );
                if ( $t ) { $q->item_title = __( 'Film:', 'devents' ) . ' ' . $t; $q->item_link = home_url( '/filmy/' . sanitize_title( $t ) . '-' . $q->item_id ); }
            }

            // Odpowiedzi organizatora (dzieci pytania).
            $q->replies = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.id, r.user_id, r.comment, r.parent_id,
                        DATE_FORMAT(r.created_at, '%%d.%%m.%%Y') AS created_at,
                        COALESCE(u.display_name, 'Organizator') AS author
                 FROM {$reviews_table} r LEFT JOIN {$users_table} u ON r.user_id = u.ID
                 WHERE r.parent_id = %d AND r.is_approved = 1
                 ORDER BY r.created_at ASC",
                $q->id
            ) ) ?: [];
        }

        return $this->twig_helper->render( 'user/my-questions', [
            'questions'    => $questions,
            'review_nonce' => wp_create_nonce( 'devents_review_nonce' ),
        ] );
    }

    /**
     * „Zgłoś problem" — formularz dla zalogowanych użytkowników (docelowo FAQ).
     */
    public function render_report_problem_form() {
        if ( ! is_user_logged_in() ) {
            return '<div class="message-box message-box--error">' . __( 'Zaloguj się, aby zgłosić problem.', 'devents' ) . '</div>';
        }
        return $this->twig_helper->render( 'user/report-problem', [
            'submit_nonce' => wp_create_nonce( 'devents_problem_nonce' ),
        ] );
    }

    public function render_organizer_search() {
        global $wpdb;

        $search_name     = isset( $_GET['search_name'] ) ? sanitize_text_field( wp_unslash( $_GET['search_name'] ) ) : '';
        $search_location = isset( $_GET['search_location'] ) ? sanitize_text_field( wp_unslash( $_GET['search_location'] ) ) : '';

        $now             = current_time( 'mysql' );
        $current_user_id = get_current_user_id();

        $table_inst    = $wpdb->prefix . 'devents_institutions';
        $events_table  = $wpdb->prefix . 'events_list';
        $subs_table    = $wpdb->prefix . 'devents_subscriptions';
        $reviews_table = $wpdb->prefix . 'devents_reviews';
        $users_table   = $wpdb->prefix . 'usermeta';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_inst}'" ) != $table_inst ) {
            return '<div class="message-box message-box--error">' . __( 'Błąd systemu: Tabela instytucji nie istnieje.', 'devents' ) . '</div>';
        }

        $sql    = "SELECT * FROM {$table_inst} WHERE verification_status = 1";
        $params = [];

        if ( ! empty( $search_name ) ) {
            $sql      .= " AND name LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $search_name ) . '%';
        }
        if ( ! empty( $search_location ) ) {
            $sql      .= " AND metadata LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $search_location ) . '%';
        }

        $sql .= " ORDER BY type ASC, name ASC";

        $institutions_raw = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        $followed_ids = [];
        if ( $current_user_id > 0 && $wpdb->get_var( "SHOW TABLES LIKE '{$subs_table}'" ) == $subs_table ) {
            $followed_ids = $wpdb->get_col( $wpdb->prepare( "SELECT organizer_user_id FROM {$subs_table} WHERE subscriber_user_id = %d", $current_user_id ) );
        }

        $has_reviews_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$reviews_table}'" ) == $reviews_table );

        $organizers_data = [];
        foreach ( $institutions_raw as $inst ) {
            $metadata   = json_decode( $inst->metadata, true ) ?: [];
            $inst_users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$users_table} WHERE meta_key = 'devents_institution_id' AND meta_value = %d", $inst->id ) );

            $primary_user_id       = ! empty( $inst_users ) ? (int) $inst_users[0] : 0;
            $upcoming_events_count = 0;
            $total_events_count    = 0;
            $followers_count       = 0;
            $reviews_data          = null;

            if ( ! empty( $inst_users ) ) {
                $users_in              = implode( ',', array_map( 'intval', $inst_users ) );
                $upcoming_events_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$events_table} WHERE user_id IN ($users_in) AND verified = 1 AND (start_datetime >= '$now' OR (end_datetime IS NOT NULL AND end_datetime != '0000-00-00 00:00:00' AND end_datetime >= '$now'))"
                );
                $total_events_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$events_table} WHERE user_id IN ($users_in) AND verified = 1"
                );
            }

            if ( $primary_user_id ) {
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$subs_table}'" ) == $subs_table ) {
                    $followers_count = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$subs_table} WHERE organizer_user_id = %d",
                        $primary_user_id
                    ) );
                }
                if ( $has_reviews_table ) {
                    $reviews_data = $wpdb->get_row( $wpdb->prepare(
                        "SELECT COUNT(*) as count, AVG(rating) as avg_rating FROM {$reviews_table} WHERE item_id = %d AND item_type = 'organizer' AND rating > 0 AND is_approved = 1",
                        $primary_user_id
                    ) );
                }
            }

            $city = $metadata['address_data']['city'] ?? '';
            if ( empty( $city ) && ! empty( $metadata['address'] ) ) {
                $parts = explode( ',', $metadata['address'] );
                $city  = trim( end( $parts ) );
            }

            $badge = null;
            if ( function_exists( 'devents_get_institution_badge' ) ) {
                $badge = devents_get_institution_badge( $inst->id );
            } elseif ( $primary_user_id && function_exists( 'devents_get_user_badge_info' ) ) {
                $badge = devents_get_user_badge_info( $primary_user_id );
            }

            $organizers_data[] = [
                'id'                    => $inst->id,
                'user_id'               => $primary_user_id,
                'type'                  => $inst->type,
                'name'                  => $inst->name,
                'logo_url'              => $metadata['logo_url'] ?? '',
                'city'                  => $city,
                'upcoming_events_count' => $upcoming_events_count,
                'total_events_count'    => $total_events_count,
                'followers_count'       => $followers_count,
                'average_rating'        => isset( $reviews_data->avg_rating ) ? round( $reviews_data->avg_rating, 1 ) : 0,
                'rating_count'          => isset( $reviews_data->count ) ? (int) $reviews_data->count : 0,
                'profile_url'           => home_url( '/organizator/' . $inst->id . '/' ),
                'activity_badge'        => $badge,
                'is_followed'           => in_array( $primary_user_id, $followed_ids ),
            ];
        }

        try {
            return $this->twig_helper->render( 'pages/organizer-search', [
                'organizers'      => $organizers_data,
                'search_name'     => $search_name,
                'search_location' => $search_location,
            ] );
        } catch ( \Exception $e ) {
            return '<div class="message-box message-box--error">' . __( 'Błąd renderowania widoku:', 'devents' ) . ' ' . esc_html( $e->getMessage() ) . '</div>';
        }
    }

    public function render_my_subscriptions_list() {
        if ( ! is_user_logged_in() ) {
            return '<div class="message-box message-box--error">' . __( 'Musisz być zalogowany, aby zobaczyć tę sekcję.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table   = "{$wpdb->prefix}events_list";
        $now     = current_time( 'mysql' );

        $followed_organizer_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT organizer_user_id FROM {$wpdb->prefix}devents_subscriptions WHERE subscriber_user_id = %d",
            $user_id
        ) );

        $followed_events_raw = [];
        if ( ! empty( $followed_organizer_ids ) ) {
            $ids_placeholder     = implode( ',', array_fill( 0, count( $followed_organizer_ids ), '%d' ) );
            $followed_events_raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE user_id IN ({$ids_placeholder}) AND verified = 1
                AND (start_datetime >= %s OR (end_datetime IS NOT NULL AND end_datetime != '0000-00-00 00:00:00' AND end_datetime >= %s))
                ORDER BY start_datetime ASC",
                array_merge( $followed_organizer_ids, [ $now, $now ] )
            ) );
        }

        $process_events = function ( $events ) {
            if ( empty( $events ) ) return [];
            $user_id      = get_current_user_id();
            $is_logged_in = $user_id > 0;
            $favorite_ids = $is_logged_in ? $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT event_id FROM {$GLOBALS['wpdb']->prefix}devents_user_favorites WHERE user_id = %d", $user_id ) ) : [];
            $followed_ids = $is_logged_in ? $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT organizer_user_id FROM {$GLOBALS['wpdb']->prefix}devents_subscriptions WHERE subscriber_user_id = %d", $user_id ) ) : [];
            $now_ts       = current_time( 'timestamp' );
            // Etykiety PL kategorii i trybu — bez nich event-card.twig pokazywał surowe
            // wartości po angielsku (np. „WORKSHOP", „live") tylko w tej zakładce.
            $all_categories     = function_exists( 'devents_get_event_categories' ) ? devents_get_event_categories() : [];
            $all_delivery_modes = function_exists( 'devents_get_event_methods' ) ? devents_get_event_methods() : [];

            foreach ( $events as $event ) {
                devents_apply_event_translation( $event ); // globalne tłumaczenie karty (subskrypcje)
                $start_ts           = strtotime( $event->start_datetime );
                $event->is_current  = ( $start_ts <= $now_ts && ! empty( $event->end_datetime ) && $event->end_datetime !== '0000-00-00 00:00:00' && strtotime( $event->end_datetime ) >= $now_ts );
                $event->permalink   = home_url( '/wydarzenia/' . sanitize_title( $event->title ) . '-' . $event->id );
                $event->is_favorite = in_array( $event->id, $favorite_ids );
                $event->is_followed = in_array( $event->user_id, $followed_ids );

                $organizer_user = get_userdata( $event->user_id );
                $organizer_name = __( 'Nie podano', 'devents' );
                if ( $organizer_user && in_array( 'organizer', (array) $organizer_user->roles ) ) {
                    $org_data       = get_user_meta( $event->user_id, '_devents_organizer_data', true ) ?: [];
                    $organizer_name = $org_data['org_name'] ?? $organizer_user->display_name;
                } elseif ( ! empty( $event->other_organizer ) ) {
                    $organizer_name = $event->other_organizer;
                }
                $event->organizer_name = $organizer_name;

                $event->category_label      = $all_categories[ $event->category ] ?? ucfirst( (string) $event->category );
                $event->delivery_mode_label = $all_delivery_modes[ $event->delivery_mode ] ?? ucfirst( (string) $event->delivery_mode );
            }
            return stripslashes_deep( $events );
        };

        return $this->twig_helper->render( 'user/my-subscriptions', [
            'followed_events' => $process_events( $followed_events_raw ),
        ] );
    }

    public function render_accept_invite_form() {
        if ( is_user_logged_in() ) {
            return '<div class="message-box message-box--info">' . __( 'Jesteś już zalogowany w systemie.', 'devents' ) . ' <a href="' . home_url( '/panel-uzytkownika/' ) . '">' . __( 'Przejdź do panelu', 'devents' ) . '</a>.</div>';
        }

        global $wpdb;
        $token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        if ( ! $token ) {
            return '<div class="message-box message-box--error">' . __( 'Brak tokena w linku.', 'devents' ) . '</div>';
        }

        $table_name = $wpdb->prefix . 'devents_team_invitations';
        $invite     = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE token = %s AND status = 'pending'",
            $token
        ) );

        if ( ! $invite ) {
            return '<div class="message-box message-box--error">' . __( 'Nieprawidłowy lub zużyty link zapraszający.', 'devents' ) . '</div>';
        }

        if ( ! empty( $invite->expires_at ) && strtotime( $invite->expires_at ) < current_time( 'timestamp' ) ) {
            return '<div class="message-box message-box--error">' . __( 'Link wygasł. Poproś administratora instytucji o nowe zaproszenie.', 'devents' ) . '</div>';
        }

        return $this->twig_helper->render( 'forms/accept-invite-form', [
            'token' => $token,
            'email' => $invite->email,
        ] );
    }

    public function process_invite_submission() {
        global $wpdb;

        if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'devents_process_invite_creation' ) return;
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'devents_create_user_action' ) ) {
            wp_send_json_error( [ 'message' => __( 'Błąd bezpieczeństwa.', 'devents' ) ] );
        }

        $token = sanitize_text_field( $_POST['token'] );
        $pass1 = $_POST['pass1'];
        $pass2 = $_POST['pass2'];

        $table_invites = $wpdb->prefix . 'devents_team_invitations';
        $invite        = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_invites WHERE token = %s AND status = 'pending'",
            $token
        ) );

        if ( ! $invite ) {
            wp_send_json_error( [ 'message' => __( 'Zaproszenie nie istnieje lub zostało już wykorzystane.', 'devents' ) ] );
        }

        if ( empty( $pass1 ) || $pass1 !== $pass2 ) {
            wp_send_json_error( [ 'message' => __( 'Hasła nie są identyczne.', 'devents' ) ] );
        }

        if ( email_exists( $invite->email ) ) {
            wp_send_json_error( [ 'message' => __( 'Użytkownik o tym adresie e-mail już istnieje.', 'devents' ) ] );
        }

        $role     = ! empty( $invite->role ) ? $invite->role : 'subscriber';
        $userdata = [
            'user_login' => $invite->email,
            'user_email' => $invite->email,
            'user_pass'  => $pass1,
            'role'       => $role,
            'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '',
            'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '',
        ];

        $user_id = wp_insert_user( $userdata );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Błąd tworzenia konta:', 'devents' ) . ' ' . $user_id->get_error_message() ] );
        }

        update_user_meta( $user_id, 'is_verified', 1 );
        if ( ! empty( $invite->institution_id ) ) {
            update_user_meta( $user_id, 'devents_institution_id', $invite->institution_id );
        }
        if ( ! empty( $invite->inviter_id ) ) {
            update_user_meta( $user_id, 'devents_employer_id', $invite->inviter_id );
        }
        update_user_meta( $user_id, 'invited_via_token', $token );

        $wpdb->update(
            $table_invites,
            [
                'status'          => 'used',
                'user_created_id' => $user_id,
                'used_at'         => current_time( 'mysql' ),
            ],
            [ 'token' => $token ],
            [ '%s', '%d', '%s' ],
            [ '%s' ]
        );

        wp_set_current_user( $user_id, $invite->email );
        wp_set_auth_cookie( $user_id );

        wp_send_json_success( [
            'message'  => __( 'Konto zostało utworzone!', 'devents' ),
            'redirect' => home_url( '/panel-uzytkownika/' ),
        ] );
    }

    public function render_institution_view() {
        if ( ! is_user_logged_in() ) return '';

        $user_id    = get_current_user_id();
        $user       = get_userdata( $user_id );
        $user_roles = (array) $user->roles;

        $is_owner_pos      = devents_user_is_owner( $user_id );
        $is_mod_pos        = devents_user_is_moderator( $user_id );
        $is_master_inst    = devents_user_is_master( $user_id );
        $is_organizer_role = $is_owner_pos && ! $is_master_inst;
        $is_master_role    = $is_owner_pos && $is_master_inst;
        $is_org_mod        = $is_mod_pos && ! $is_master_inst;
        $is_master_mod     = $is_mod_pos && $is_master_inst;
        $is_any_moderator  = $is_mod_pos;

        $target_user_id = $user_id;
        if ( $is_any_moderator ) {
            $employer_id = get_user_meta( $user_id, 'devents_employer_id', true );
            if ( $employer_id ) $target_user_id = $employer_id;
        }

        $org_data = [];
        $inst_id  = function_exists( 'devents_get_user_institution_id' ) ? devents_get_user_institution_id( $target_user_id ) : 0;

        if ( $inst_id ) {
            global $wpdb;
            $table_inst = $wpdb->prefix . 'devents_institutions';
            $inst_row   = $wpdb->get_row( $wpdb->prepare( "SELECT name, type, metadata FROM {$table_inst} WHERE id = %d", $inst_id ) );

            if ( $inst_row ) {
                $metadata = json_decode( $inst_row->metadata, true );
                if ( ! is_array( $metadata ) ) $metadata = [];

                $org_data = [
                    'org_name'       => $inst_row->name,
                    'type'           => $inst_row->type ?: 'unit',
                    'nip'            => $metadata['nip'] ?? '',
                    'address'        => $metadata['address'] ?? '',
                    'phone'          => $metadata['phone'] ?? '',
                    'email'          => $metadata['email'] ?? '',
                    'website'        => $metadata['website'] ?? '',
                    'logo_url'       => $metadata['logo_url'] ?? '',
                    'description'    => $metadata['description'] ?? '',
                    'org_code'       => $metadata['org_code'] ?? '',
                    'pjm_video_link' => $metadata['pjm_video_link'] ?? '',
                    'address_data'   => $metadata['address_data'] ?? [ 'street' => '', 'house_number' => '', 'zip_code' => '', 'city' => '' ],
                    'coordinator'    => [
                        'first_name' => $metadata['coordinator']['first_name'] ?? '',
                        'last_name'  => $metadata['coordinator']['last_name'] ?? '',
                        'email'      => $metadata['coordinator']['email'] ?? '',
                        'phone'      => $metadata['coordinator']['phone'] ?? '',
                    ],
                ];
            }
        }

        if ( empty( $org_data ) ) {
            $old_data = get_user_meta( $target_user_id, '_devents_organizer_data', true );
            if ( is_array( $old_data ) ) $org_data = $old_data;
        }

        $org_data = wp_parse_args( $org_data, [
            'org_name' => '', 'nip' => '', 'address' => '', 'phone' => '', 'website' => '', 'type' => 'unit',
            'email' => '', 'logo_url' => '', 'description' => '', 'org_code' => '', 'pjm_video_link' => '',
            'address_data' => [ 'street' => '', 'house_number' => '', 'zip_code' => '', 'city' => '' ],
            'coordinator'  => [ 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '' ],
        ] );

        $master_id         = get_user_meta( $target_user_id, 'devents_master_id', true );
        $pending_master_id = get_user_meta( $target_user_id, 'devents_pending_master_id', true );
        $master_org        = $master_id ? get_userdata( $master_id ) : null;
        $pending_master_org = $pending_master_id ? get_userdata( $pending_master_id ) : null;

        $team_members        = [];
        $pending_invitations = [];
        if ( ! $is_any_moderator ) {
            if ( $inst_id ) {
                $team_members = get_users( [
                    'meta_query' => [ [ 'key' => 'devents_institution_id', 'value' => $inst_id, 'compare' => '=' ] ],
                    'exclude'    => [ $user_id ],
                ] );
            }
            global $wpdb;
            $table_invites = $wpdb->prefix . 'devents_team_invitations';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_invites}'" ) == $table_invites ) {
                $pending_invitations = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$table_invites} WHERE (institution_id = %d OR inviter_id = %d) AND status = 'pending' ORDER BY created_at DESC",
                    $inst_id, $user_id
                ), ARRAY_A );
            }
        }

        return $this->twig_helper->render( 'user/my-institution', [
            'user'                => $user,
            'is_master'           => ( $is_master_role || $is_master_mod ),
            'is_moderator'        => $is_any_moderator,
            'org_data'            => $org_data,
            // Widget / publiczne API wydarzeń instytucji (do osadzenia na własnej stronie).
            'institution_id'      => (int) $inst_id,
            'widget_src'          => DEW_PLUGIN_URL . 'assets/js/frontend/devents-widget.js',
            'api_url'             => home_url( '/wp-json/devents/v1/events' ),
            'master_org'          => $master_org,
            'pending_master_org'  => $pending_master_org,
            'team_members'        => $team_members,
            'pending_invitations' => $pending_invitations ?: [],
            'details_nonce'       => wp_create_nonce( 'devents-update-account-' . $user_id ),
            'org_action_nonce'    => wp_create_nonce( 'devents_org_action_nonce' ),
            'team_action_nonce'   => wp_create_nonce( 'devents_team_action_nonce' ),
        ] );
    }

    public function render_lang_switcher(): string {
        $current_lang = substr( determine_locale(), 0, 2 );
        $supported    = DEvents_I18n::$supported_ui_languages;

        return $this->twig_helper->render( 'components/lang-switcher', [
            'active_ui_lang' => $current_lang,
            'ui_languages'   => $supported,
            'switch_url'     => home_url( '/?devents_lang=' ),
        ] );
    }

    /**
     * Cennik usług płatnych jako karty produktowe ([devents_cennik]).
     * Ceny pobierane z opcji devents_prices (te same, których używa kasa).
     */
    public function render_cennik(): string {
        $defaults = [
            'featured_3_days'  => 15.00,
            'featured_7_days'  => 29.00,
            'featured_14_days' => 49.00,
            'translation_pjm'  => 149.00,
            'subtitle_addon'   => 50.00,
        ];
        $prices = wp_parse_args( get_option( 'devents_prices', [] ), $defaults );
        $fmt    = static function ( $v ) { return number_format( (float) $v, 0, ',', ' ' ); };

        $products = [
            [
                'icon'     => 'star',
                'name'     => __( 'Wyróżnienie — 3 dni', 'devents' ),
                'price'    => $fmt( $prices['featured_3_days'] ),
                'unit'     => __( 'jednorazowo', 'devents' ),
                'desc'     => __( 'Twoje wydarzenie wyżej na listach i na stronie głównej przez 3 dni.', 'devents' ),
                'features' => [ __( 'Wyższa pozycja na listach', 'devents' ), __( 'Ekspozycja na stronie głównej', 'devents' ) ],
                'featured' => false,
            ],
            [
                'icon'     => 'star',
                'name'     => __( 'Wyróżnienie — 7 dni', 'devents' ),
                'price'    => $fmt( $prices['featured_7_days'] ),
                'unit'     => __( 'jednorazowo', 'devents' ),
                'desc'     => __( 'Tydzień zwiększonej widoczności Twojego wydarzenia.', 'devents' ),
                'features' => [ __( 'Wyższa pozycja na listach', 'devents' ), __( 'Ekspozycja na stronie głównej', 'devents' ) ],
                'featured' => false,
            ],
            [
                'icon'     => 'workspace_premium',
                'name'     => __( 'Wyróżnienie — 14 dni', 'devents' ),
                'price'    => $fmt( $prices['featured_14_days'] ),
                'unit'     => __( 'jednorazowo', 'devents' ),
                'desc'     => __( 'Najlepsza widoczność na dwa tygodnie — polecane przy ważnych wydarzeniach.', 'devents' ),
                'features' => [ __( 'Wyższa pozycja na listach', 'devents' ), __( 'Ekspozycja na stronie głównej', 'devents' ), __( 'Najlepszy stosunek ceny do zasięgu', 'devents' ) ],
                'featured' => true,
            ],
            [
                'icon'     => 'sign_language',
                'name'     => __( 'Tłumaczenie na PJM', 'devents' ),
                'price'    => $fmt( $prices['translation_pjm'] ),
                'unit'     => __( 'za wydarzenie', 'devents' ),
                'desc'     => __( 'Profesjonalny tłumacz PJM nagra zaproszenie na Twoje wydarzenie na wskazanym tle.', 'devents' ),
                'features' => [ __( 'Nagranie z tłumaczem PJM', 'devents' ), __( 'Twoja grafika i kolory', 'devents' ), __( 'Format poziomy lub pionowy', 'devents' ) ],
                'featured' => false,
            ],
            [
                'icon'     => 'closed_caption',
                'name'     => __( 'Napisy .SRT', 'devents' ),
                'price'    => $fmt( $prices['subtitle_addon'] ),
                'unit'     => __( 'dodatek do PJM', 'devents' ),
                'desc'     => __( 'Plik napisów .SRT do nagrania z tłumaczeniem — dla osób korzystających z napisów.', 'devents' ),
                'features' => [ __( 'Gotowy plik .SRT', 'devents' ), __( 'Dodatek do tłumaczenia PJM', 'devents' ) ],
                'featured' => false,
            ],
        ];

        return $this->twig_helper->render( 'pages/cennik', [
            'products'          => $products,
            'is_user_logged_in' => is_user_logged_in(),
            'panel_url'         => home_url( '/panel-uzytkownika/?view=moje-wydarzenia' ),
            'register_url'      => home_url( '/rejestracja/' ),
        ] );
    }

    /**
     * Ankieta satysfakcji ([devents_ankieta]) — formularz oceny platformy.
     * Zapis przez AJAX devents_submit_survey (survey-handler.php).
     */
    public function render_satisfaction_survey(): string {
        return $this->twig_helper->render( 'pages/satisfaction-survey', [
            'nonce'             => wp_create_nonce( 'devents_survey_nonce' ),
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'is_user_logged_in' => is_user_logged_in(),
        ] );
    }

    /**
     * „Moje potrzeby" — profil dostępności użytkownika. Wybrane cechy dostępności
     * zapisywane są w meta `devents_accessibility_prefs`; wyszukiwarka wydarzeń
     * domyślnie zawęża listę do tych potrzeb (patrz render_event_search).
     */
    public function render_accessibility_prefs( $user_id ) {
        $types = method_exists( $this, 'get_accessibility_types' ) ? $this->get_accessibility_types() : [];
        $prefs = (array) get_user_meta( $user_id, 'devents_accessibility_prefs', true );
        $prefs = array_values( array_filter( array_map( 'intval', $prefs ) ) );
        return $this->twig_helper->render( 'user/my-accessibility-prefs', [
            'types'      => $types,
            'prefs'      => $prefs,
            'nonce'      => wp_create_nonce( 'devents_a11y_prefs_' . $user_id ),
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'events_url' => home_url( '/wydarzenia/' ),
        ] );
    }

    /* =====================================================================
       PANEL: Raporty (płatne zestawienia wydarzeń dla instytucji)
       ===================================================================== */
    public function render_reports( $user_id ) {
        if ( ! function_exists( 'devents_user_owned_institution' ) ) {
            return '<div class="message-box message-box--error">' . __( 'Moduł raportów jest niedostępny.', 'devents' ) . '</div>';
        }
        $inst = devents_user_owned_institution( $user_id );
        if ( ! $inst ) {
            return '<div class="message-box message-box--error">' . __( 'Raporty są dostępne dla właścicieli instytucji.', 'devents' ) . '</div>';
        }

        global $wpdb;
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_number, amount, status, billing_details, created_at
             FROM {$wpdb->prefix}devents_orders
             WHERE user_id = %d AND product_type = 'report'
             ORDER BY id DESC",
            $user_id
        ) );

        // Miesiące do listy rozwijanej (po polsku).
        $months = [
            1 => __( 'styczeń', 'devents' ),   2 => __( 'luty', 'devents' ),     3 => __( 'marzec', 'devents' ),
            4 => __( 'kwiecień', 'devents' ),  5 => __( 'maj', 'devents' ),      6 => __( 'czerwiec', 'devents' ),
            7 => __( 'lipiec', 'devents' ),    8 => __( 'sierpień', 'devents' ), 9 => __( 'wrzesień', 'devents' ),
            10 => __( 'październik', 'devents' ), 11 => __( 'listopad', 'devents' ), 12 => __( 'grudzień', 'devents' ),
        ];

        $report_orders = [];
        foreach ( (array) $orders as $o ) {
            $b      = ! empty( $o->billing_details ) ? json_decode( $o->billing_details, true ) : [];
            $yr     = (int) ( $b['year'] ?? 0 );
            $mo     = (int) ( $b['month'] ?? 0 );
            $period = ( $mo >= 1 && $mo <= 12 ) ? ( ( $months[ $mo ] ?? $mo ) . ' ' . $yr ) : ( __( 'cały rok', 'devents' ) . ' ' . $yr );
            $report_orders[] = [
                'id'           => (int) $o->id,
                'order_number' => $o->order_number,
                'amount'       => (float) $o->amount,
                'status'       => $o->status,
                'period'       => $period,
                'ready'        => in_array( $o->status, [ 'processing', 'completed' ], true ),
                'created'      => $o->created_at ? date_i18n( 'd.m.Y', strtotime( $o->created_at ) ) : '',
                'report_url'   => home_url( '/?devents_report=' . (int) $o->id ),
                'proforma_url' => home_url( '/?devents_proforma=' . (int) $o->id ),
            ];
        }

        $current_year = (int) date_i18n( 'Y' );
        $years        = [ $current_year, $current_year - 1, $current_year - 2 ];

        return $this->twig_helper->render( 'user/my-reports', [
            'institution'  => $inst,
            'price'        => function_exists( 'devents_report_price' ) ? devents_report_price() : 20.0,
            'months'       => $months,
            'years'        => $years,
            'current_year' => $current_year,
            'orders'       => $report_orders,
            'nonce'        => wp_create_nonce( 'devents_report_nonce' ),
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /* =====================================================================
       FUNKCJA WYSZUKIWARKI AJAX (devents_ajax_search)
       ===================================================================== */
    public function devents_ajax_search_callback() {
        global $wpdb;
        $event_table_name = $wpdb->prefix . 'events_list';
        $occasions_table  = $wpdb->prefix . 'devents_special_occasions';
        $inst_table_name  = $wpdb->prefix . 'devents_institutions';
        
        $now = current_time('mysql');
        
        $global_spoken_langs = isset($_GET['global_spoken_langs']) ? (array) $_GET['global_spoken_langs'] : [];
        $global_sign_langs   = isset($_GET['global_sign_langs']) ? (array) $_GET['global_sign_langs'] : [];
        // Domyślne filtry użytkownika — gdy filtr NIE jest jawnie w URL, użyj zapisanych preferencji.
        if ( is_user_logged_in() ) {
            $__uid = get_current_user_id();
            if ( ! array_key_exists( 'global_spoken_langs', $_GET ) ) { $__d = get_user_meta( $__uid, 'default_spoken_lang', true ); if ( $__d ) $global_spoken_langs = [ $__d ]; }
            if ( ! array_key_exists( 'global_sign_langs', $_GET ) )   { $__d = get_user_meta( $__uid, 'default_sign_lang', true );   if ( $__d ) $global_sign_langs = [ $__d ]; }
        }
        $country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : '';
        if ( ! array_key_exists( 'country', $_GET ) && is_user_logged_in() ) { $country = get_user_meta( get_current_user_id(), 'default_country', true ) ?: ''; }
        
        $filters = [
            'search_query'     => isset($_GET['search_query']) ? sanitize_text_field(wp_unslash($_GET['search_query'])) : '',
            'location'         => isset($_GET['location']) ? sanitize_text_field(wp_unslash($_GET['location'])) : '',
            'category'         => isset($_GET['category']) ? sanitize_key($_GET['category']) : '', 
            'delivery_mode'    => isset($_GET['delivery_mode']) ? sanitize_key($_GET['delivery_mode']) : '', 
            'accessibility'    => isset($_GET['accessibility']) && is_array($_GET['accessibility']) ? array_map('absint', $_GET['accessibility']) : [],
            'sort'             => isset($_GET['sort']) ? sanitize_key($_GET['sort']) : 'date_asc',
        ];
        
        $sql_conditions = ["e.verified = 1"];
        $params         = [];
        
        $sql_conditions[] = $wpdb->prepare(
            "(CASE WHEN e.end_datetime IS NULL OR e.end_datetime = '0000-00-00 00:00:00' THEN e.start_datetime ELSE e.end_datetime END) >= %s",
            $now
        );
        
        if (!empty($filters['search_query'])) {
            $sql_conditions[] = "(e.title LIKE %s OR e.description LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($filters['search_query']) . '%';
            $params[] = '%' . $wpdb->esc_like($filters['search_query']) . '%';
        }
        if (!empty($filters['location'])) {
            $sql_conditions[] = "(e.location LIKE %s OR e.address_city LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($filters['location']) . '%';
            $params[] = '%' . $wpdb->esc_like($filters['location']) . '%';
        }
        if (!empty($filters['category'])) {
            $sql_conditions[] = "e.category = %s";
            $params[] = $filters['category'];
        }
        if (!empty($filters['delivery_mode'])) {
            $sql_conditions[] = "e.delivery_mode = %s";
            $params[] = $filters['delivery_mode'];
        }
        if ( ! empty( $country ) ) {
            $sql_conditions[] = "e.event_country_code = %s";
            $params[] = $country;
        }
        
        // --- FILTROWANIE DOSTĘPNOŚCI Z JĘZYKAMI ---
        if (!empty($filters['accessibility'])) {
            $acc_queries = [];
            $accessibility_types = $this->get_accessibility_types();
            
            $acc_type_map = [];
            if (!empty($accessibility_types)) {
                foreach ($accessibility_types as $type) {
                    $acc_type_map[$type->id] = [
                        'spoken' => $type->requires_spoken_lang,
                        'sign'   => $type->requires_sl_lang
                    ];
                }
            }
        
            foreach ($filters['accessibility'] as $acc_id) {
                $is_spoken = isset($acc_type_map[$acc_id]) && $acc_type_map[$acc_id]['spoken'] == 1;
                $is_sign   = isset($acc_type_map[$acc_id]) && $acc_type_map[$acc_id]['sign'] == 1;
        
                $langs_to_check = [];
                if ($is_spoken && !empty($global_spoken_langs)) {
                    $langs_to_check = $global_spoken_langs;
                } elseif ($is_sign && !empty($global_sign_langs)) {
                    $langs_to_check = $global_sign_langs;
                }
        
                if (!empty($langs_to_check)) {
                    $lang_ors = [];
                    foreach ($langs_to_check as $lang) {
                        $lang_ors[] = $wpdb->prepare("JSON_CONTAINS(e.accessibility, %s, %s)", '"' . $lang . '"', '$."' . $acc_id . '"');
                    }
                    $acc_queries[] = "(" . implode(" OR ", $lang_ors) . ")";
                } else {
                    $acc_queries[] = $wpdb->prepare("JSON_CONTAINS_PATH(e.accessibility, 'one', %s)", '$."' . $acc_id . '"');
                }
            }
            if (!empty($acc_queries)) {
                $sql_conditions[] = "(" . implode(" AND ", $acc_queries) . ")";
            }
        }
        
        $where_clause = "WHERE " . implode(" AND ", $sql_conditions);
        $order_clause = "ORDER BY e.start_datetime ASC";
        switch ($filters['sort']) {
            case 'date_desc':  $order_clause = "ORDER BY e.start_datetime DESC"; break;
            case 'price_asc':  $order_clause = "ORDER BY e.price ASC, e.start_datetime ASC"; break;
            case 'price_desc': $order_clause = "ORDER BY e.price DESC, e.start_datetime ASC"; break;
            case 'name_asc':   $order_clause = "ORDER BY e.title ASC"; break;
        }
        
        $total_results = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$event_table_name} e {$where_clause}", $params));
        
        $per_page = 24;
        $current_page = max(1, isset($_GET['page']) ? intval($_GET['page']) : 1);
        $total_pages = max(1, (int) ceil($total_results / $per_page));
        $offset = ($current_page - 1) * $per_page;
        
        $sql = "SELECT e.*, so.slug as special_occasion_slug, so.color as special_occasion_color, inst.name as institution_name
                FROM {$event_table_name} e
                LEFT JOIN {$occasions_table} so ON e.special_occasion_id = so.id
                LEFT JOIN {$inst_table_name} inst ON e.institution_id = inst.id
                {$where_clause} {$order_clause} LIMIT %d OFFSET %d";
        
        $final_params = array_merge($params, [$per_page, $offset]);
        $all_events_raw = $wpdb->get_results($wpdb->prepare($sql, $final_params));
        
        $processing_result = $this->prepare_events_for_display($all_events_raw);
        
        $html = '';
        if (!empty($processing_result['events'])) {
            foreach ($processing_result['events'] as $event) {
                $html .= $this->twig_helper->render('components/event-card', ['event' => $event]);
            }
        }
        
        $pagination_html = '';
        if ($total_pages > 1) {
            $base_url = strtok(home_url($_SERVER['REQUEST_URI']), '?');
            $pagination_html = $this->twig_helper->render('components/pagination', [
                'current_base_url' => $base_url,
                'current_page' => $current_page,
                'total_pages' => $total_pages
            ]);
        }
        
        wp_send_json_success([
            'html' => $html,
            'total' => $total_results,
            'pagination_html' => $pagination_html
        ]);
    }

} // <--- ZAMKNIĘCIE KLASY

new DEvents_Shortcodes();