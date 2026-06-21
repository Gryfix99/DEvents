<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DEvents_Core {

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }
    
    private function __clone() {}
    public function __wakeup() {}

    private function load_dependencies() {        
        require_once DEW_PLUGIN_PATH . 'includes/class-register-table.php';
        require_once DEW_PLUGIN_PATH . 'includes/class-devents-assets.php';
        require_once DEW_PLUGIN_PATH . 'includes/i18n/class-devents-i18n.php';
        require_once DEW_PLUGIN_PATH . 'includes/i18n/class-devents-region-manager.php';
        require_once DEW_PLUGIN_PATH . 'includes/class-devents-shortcodes.php';
        require_once DEW_PLUGIN_PATH . 'includes/class-devents-admin-dashboard.php';
        require_once DEW_PLUGIN_PATH . 'admin/class-devents-admin-menu.php';
        require_once DEW_PLUGIN_PATH . 'includes/post-types.php';
        require_once DEW_PLUGIN_PATH . 'includes/ajax-handlers.php';
        
        if ( file_exists( DEW_PLUGIN_PATH . 'includes/devents-helpers.php' ) ) {
            require_once DEW_PLUGIN_PATH . 'includes/devents-helpers.php';
        }

        require_once DEW_PLUGIN_PATH . 'emails/email-manager.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/calendar-export-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/news-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/cron-jobs.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/audit-log-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/newsletter-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/coupon-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/stripe-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/order-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/admin-coupon-handler.php';
        require_once DEW_PLUGIN_PATH . 'includes/handlers/admin-sales-handler.php';
        
        if ( file_exists( DEW_PLUGIN_PATH . 'includes/handlers/video-handler.php' ) ) {
            require_once DEW_PLUGIN_PATH . 'includes/handlers/video-handler.php';
        }

        // FIX: Lang handler (zawiera handler ?devents_lang=xx)
        if ( file_exists( DEW_PLUGIN_PATH . 'includes/class-devents-lang-handler.php' ) ) {
            require_once DEW_PLUGIN_PATH . 'includes/class-devents-lang-handler.php';
        }
    }

    private function register_hooks() {
        // 0. i18n
        if ( class_exists( 'DEvents_I18n' ) ) {
            DEvents_I18n::get_instance();
        }
        if ( class_exists( 'DEvents_Region_Manager' ) ) {
            DEvents_Region_Manager::get_instance();
        }

        // 1. Inicjalizacja
        add_action( 'init', [ $this, 'init_session' ] );
        add_action( 'init', 'devents_register_post_types' );
        add_action( 'plugins_loaded', [ $this, 'check_db_version' ] );
        add_action( 'init', [ $this, 'register_user_roles' ] );

        // 2. Zasoby
        $assets = new DEvents_Assets();
        add_action( 'wp_enqueue_scripts', [ $assets, 'enqueue_frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $assets, 'enqueue_admin_assets' ] );

        // 3. Admin Menu
        $admin_menu = new DEvents_Admin_Menu();
        add_action( 'admin_menu', [ $admin_menu, 'register_menu' ] );
        
        // 4. Handlery
        if ( class_exists( 'DEvents_Stripe_Handler' ) )       new DEvents_Stripe_Handler();
        if ( class_exists( 'DEvents_Order_Handler' ) )        new DEvents_Order_Handler();
        if ( class_exists( 'DEvents_Admin_Sales_Handler' ) )  new DEvents_Admin_Sales_Handler();
        if ( class_exists( 'DEvents_Admin_Coupon_Handler' ) ) new DEvents_Admin_Coupon_Handler();

        // 5. AJAX
        devents_register_ajax_handlers();

        // 6. Bezpieczeństwo
        add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_non_admins' ] );
        add_action( 'admin_init', [ $this, 'restrict_admin_access' ] );
        add_action( 'login_form_logout', [ $this, 'bypass_logout_confirmation' ] );
        add_action( 'template_redirect', [ $this, 'handle_maintenance_mode' ] );
        add_filter( 'login_redirect', [ $this, 'custom_login_redirect' ], 10, 3 );

        // 7. Cron
        add_action( 'init', [ $this, 'schedule_cron_jobs' ] );

        // FIX: USUNIĘTO handle_lang_switch z register_hooks —
        // ta logika jest teraz TYLKO w class-devents-lang-handler.php
        // (była zduplikowana: raz tutaj, raz w lang-handler → wyścig)
    }

    /* === Bezpieczeństwo === */

    public function hide_admin_bar_for_non_admins( $show ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) {
            return false;
        }
        return $show;
    }

    public function restrict_admin_access() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( ! current_user_can( 'administrator' ) ) {
            wp_redirect( home_url() );
            exit;
        }
    }

    public function bypass_logout_confirmation() {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            wp_destroy_current_session();
            wp_clear_auth_cookie();
            wp_set_current_user( 0 );
            do_action( 'wp_logout', $user_id );
            wp_redirect( home_url() );
            exit;
        }
    }

    public function custom_login_redirect( $redirect_to, $request, $user ) {
        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            if ( in_array( 'administrator', $user->roles ) ) {
                return home_url( '/panel-uzytkownika/?view=panel-admina' );
            }
            return home_url( '/panel-uzytkownika/' );
        }
        return home_url( '/panel-uzytkownika/' );
    }

    /* === Role === */

    public function register_user_roles() {
        // MODEL RÓL v2 — tylko subscriber / organizer / administrator.
        // Typ instytucji (unit/master) i pozycja (owner/moderator) żyją osobno
        // (institutions.type + meta devents_institution_role). Stare role
        // (master_organizer, *_mod) są usuwane przez migrate_roles_v2().
        $base_cap       = 'organizer';
        $organizer_role = get_role( 'organizer' );
        if ( ! $organizer_role ) {
            add_role( 'organizer', 'Organizator', [ 'read' => true, 'upload_files' => true ] );
            $organizer_role = get_role( 'organizer' );
        }
        if ( $organizer_role && ! $organizer_role->has_cap( $base_cap ) ) {
            $organizer_role->add_cap( $base_cap );
        }

        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) $admin_role->add_cap( $base_cap );

        $this->migrate_roles_v2();
    }

    /**
     * Migracja v2 (jednorazowa): przepina użytkowników ze starych ról
     * (master_organizer / organizer_mod / master_organizer_mod) na pojedynczą
     * rolę 'organizer' + meta pozycji (owner/member) + typ instytucji (master/unit),
     * a następnie usuwa definicje starych ról. Stare role miały te same uprawnienia
     * (cap 'organizer'), więc po migracji wszystkie sprawdzenia uprawnień działają.
     */
    public function migrate_roles_v2() {
        if ( get_option( 'devents_roles_v2_done' ) ) {
            return;
        }
        if ( ! function_exists( 'get_users' ) ) {
            return;
        }
        $legacy = [ 'master_organizer', 'organizer_mod', 'master_organizer_mod' ];
        $users  = get_users( [ 'role__in' => $legacy ] );

        global $wpdb;
        $inst_table = $wpdb->prefix . 'devents_institutions';

        foreach ( $users as $u ) {
            $roles     = (array) $u->roles;
            $is_mod    = in_array( 'organizer_mod', $roles, true ) || in_array( 'master_organizer_mod', $roles, true );
            $is_master = in_array( 'master_organizer', $roles, true ) || in_array( 'master_organizer_mod', $roles, true );

            // Pozycja (zachowujemy istniejącą meta, jeśli już ustawiona).
            $pos = get_user_meta( $u->ID, 'devents_institution_role', true );
            if ( $pos !== 'owner' && $pos !== 'member' ) {
                update_user_meta( $u->ID, 'devents_institution_role', $is_mod ? 'member' : 'owner' );
            }

            // Typ instytucji — uzupełnij, jeśli pusty.
            $inst_id = (int) get_user_meta( $u->ID, 'devents_institution_id', true );
            if ( $inst_id > 0 ) {
                $cur = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$inst_table} WHERE id = %d", $inst_id ) );
                if ( ! $cur ) {
                    $wpdb->update( $inst_table, [ 'type' => $is_master ? 'master' : 'unit' ], [ 'id' => $inst_id ] );
                }
            }

            // Pojedyncza rola (usuwa stare role z konta).
            $u->set_role( 'organizer' );
        }

        // Usuń definicje starych ról.
        foreach ( $legacy as $rname ) {
            if ( get_role( $rname ) ) {
                remove_role( $rname );
            }
        }

        update_option( 'devents_roles_v2_done', 1 );
    }

    /* === Sesja === */

    public function init_session() {
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
            session_start();
        }
    }

    public function check_db_version() {
        if ( class_exists( 'RegisterTable' ) ) {
            ( new RegisterTable() )->check_db_version();
        }
    }

    /* === Maintenance === */

    public function handle_maintenance_mode() {
        $is_on = (bool) get_option( 'devents_maintenance_mode', false );
        if ( ! $is_on || current_user_can( 'manage_options' ) ) return;

        $is_login = isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false;
        if ( $is_login ) return;

        $protocol = wp_get_server_protocol();
        header( "$protocol 503 Service Unavailable", true, 503 );
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Retry-After: 3600' );

        $title   = get_option( 'devents_maintenance_title', 'Przerwa techniczna' );
        $message = get_option( 'devents_maintenance_message', '<h2>Przepraszamy, prowadzimy prace techniczne.</h2>' );

        if ( class_exists( 'DEvents_Twig_Helper' ) ) {
            echo DEvents_Twig_Helper::get_instance()->render( 'pages/maintenance', [
                'title'     => $title,
                'message'   => wpautop( $message ),
                'site_name' => get_bloginfo( 'name' ),
            ] );
        } else {
            wp_die( $message, $title, [ 'response' => 503 ] );
        }
        exit;
    }

    /* === Cron === */

    public function schedule_cron_jobs() {
        if ( ! wp_next_scheduled( 'devents_daily_event_reminders' ) ) {
            wp_schedule_event( time(), 'daily', 'devents_daily_event_reminders' );
        }
        if ( ! wp_next_scheduled( 'devents_hourly_tasks' ) ) {
            wp_schedule_event( time(), 'hourly', 'devents_hourly_tasks' );
        }
        if ( ! wp_next_scheduled( 'devents_weekly_subscriber_email' ) ) {
            wp_schedule_event( strtotime( 'next monday 8:00 AM' ), 'weekly', 'devents_weekly_subscriber_email' );
        }
    }

    /* === Activation — flush rewrite rules === */

    public static function on_activation() {
        // Załaduj profil handler żeby zarejestrować rewrite rules
        if ( class_exists( 'DEvents_Public_Profiles_Handler' ) ) {
            DEvents_Public_Profiles_Handler::flush_rules();
        }
    }
}