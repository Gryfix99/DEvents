<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DEvents_Admin_Menu {
    
    private $twig;

    /**
     * Inicjalizuje Twig i rejestruje menu.
     */
    public function __construct() {
        // Inicjalizujemy Twig raz, aby był dostępny dla wszystkich metod tej klasy
        if ( class_exists('\Twig\Loader\FilesystemLoader') ) {
            try {
                $loader = new \Twig\Loader\FilesystemLoader( DEW_PLUGIN_PATH . 'templates' );
                $this->twig = new \Twig\Environment($loader, ['cache' => false]);

                // Dodajemy kluczowe funkcje WordPressa do Twig
                $this->add_wordpress_functions_to_twig();

            } catch(Exception $e) {
                wp_die('Błąd inicjalizacji Twig: ' . esc_html($e->getMessage()));
            }
        } else {
             wp_die('Klasa Twig nie została znaleziona. Uruchom `composer install`.');
        }
    }
    
    /**
     * Dodaje popularne funkcje WordPressa do środowiska Twig.
     */
    private function add_wordpress_functions_to_twig() {
        if ( ! $this->twig ) return;
        
        $this->twig->addFunction( new \Twig\TwigFunction('admin_url', function($path = '') {
            return admin_url($path);
        }) );

        $this->twig->addFunction( new \Twig\TwigFunction('wp_nonce_field', function($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
            return wp_nonce_field($action, $name, $referer, false);
        }, ['is_safe' => ['html']]) );
    }

    /**
     * Rejestruje wszystkie strony w menu administratora.
     */
    public function register_menu() {
        // Jeden, wspólny panel: wp-admin renderuje DOKŁADNIE ten sam dashboard,
        // co front ([devents_admin_panel]). Dzięki temu obie wersje mają te same funkcje.
        // Wszystkie zakładki (Przegląd/Zamówienia/Wydarzenia/Użytkownicy/Instytucje/...)
        // są w nawigacji dashboardu, więc osobne podstrony nie są już potrzebne.
        add_menu_page( 'DEvents Manager', 'DEvents Manager', 'manage_options', 'devents-manager', [$this, 'render_dashboard_page'], 'dashicons-calendar-alt', 6 );
        add_submenu_page( 'devents-manager', 'Panel DEvents', 'Panel', 'manage_options', 'devents-manager', [$this, 'render_dashboard_page'] );
        add_submenu_page( 'devents-manager', 'Narzędzia Naprawcze', 'Narzędzia', 'manage_options', 'devents-tools', [$this, 'render_tools_page']);
    }

    /**
     * Renderuje pełny dashboard DEvents w wp-admin — DOKŁADNIE ten sam, który działa
     * na froncie ([devents_admin_panel]). render(true) ustawia kontekst wp-admin, więc
     * linki zakładek/filtrów/paginacji budują się względem admin.php?page=devents-manager.
     */
    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Brak uprawnień.', 'devents' ) );
        }

        echo '<div class="wrap devents-wpadmin-dashboard">';
        if ( class_exists( 'DEvents_Admin_Dashboard' ) ) {
            $dashboard = new DEvents_Admin_Dashboard();
            echo $dashboard->render( true );
        } else {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Panel DEvents jest niedostępny (brak klasy dashboardu).', 'devents' )
                . '</p></div>';
        }
        echo '</div>';
    }

    /**
     * Strona "Narzędzia Naprawcze" — odświeżanie reguł przepisywania URL
     * oraz ponowna weryfikacja/instalacja tabel bazy danych.
     */
    public function render_tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Brak uprawnień.', 'devents' ) );
        }

        $notice = '';

        if ( isset( $_POST['devents_tool_action'] ) ) {
            check_admin_referer( 'devents_tools_action' );
            $action = sanitize_key( $_POST['devents_tool_action'] );

            if ( $action === 'flush_rewrite' ) {
                flush_rewrite_rules();
                $notice = __( 'Reguły przepisywania adresów zostały odświeżone.', 'devents' );
            } elseif ( $action === 'reinstall_tables' && class_exists( 'RegisterTable' ) ) {
                ( new RegisterTable() )->install();
                $notice = __( 'Tabele bazy danych zostały zweryfikowane/zaktualizowane.', 'devents' );
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Narzędzia Naprawcze', 'devents' ) . '</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'devents_tools_action' );

        echo '<h2>' . esc_html__( 'Adresy URL', 'devents' ) . '</h2>';
        echo '<p>' . esc_html__( 'Użyj, gdy strony wydarzeń lub profili zwracają błąd 404.', 'devents' ) . '</p>';
        echo '<p><button type="submit" name="devents_tool_action" value="flush_rewrite" class="button button-primary">'
            . esc_html__( 'Odśwież reguły przepisywania', 'devents' ) . '</button></p>';

        echo '<h2>' . esc_html__( 'Baza danych', 'devents' ) . '</h2>';
        echo '<p>' . esc_html__( 'Ponownie weryfikuje strukturę tabel wtyczki i dodaje brakujące kolumny.', 'devents' ) . '</p>';
        echo '<p><button type="submit" name="devents_tool_action" value="reinstall_tables" class="button">'
            . esc_html__( 'Zweryfikuj tabele', 'devents' ) . '</button></p>';

        echo '</form>';
        echo '</div>';
    }
}
