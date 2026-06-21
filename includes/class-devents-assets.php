<?php
/**
 * Klasa DEvents_Assets
 * Kompleksowe zarządzanie skryptami JS i stylami DEvents.
 * Wersja: 9.3.1 (Dodano cropper.css, flatpickr-pl.js oraz poprawiono nonces)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DEvents_Assets {

    private $version = '9.5.1';

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * ZAPLECZE (Natywny wp-admin WordPressa)
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos($hook, 'devents') === false && strpos($hook, 'wydarzenia') === false ) return;

        // --- GŁÓWNY PANEL (dashboard) w wp-admin: ładujemy DOKŁADNIE ten sam zestaw,
        //     co na froncie, aby obie wersje panelu miały te same funkcje i wygląd. ---
        if ( $hook === 'toplevel_page_devents-manager' ) {
            $this->register_external_libraries();

            wp_enqueue_style('devents-google-fonts', 'https://fonts.googleapis.com/css2?family=Dosis:wght@300;400;700&display=swap');
            wp_enqueue_style('material-symbols-outlined', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
            // UWAGA: NIE ładujemy frontend.css w wp-admin — ma globalny reset (* {margin:0})
            // i reguły body/a/h*, które zniszczyłyby chrome wp-admin. Używamy dedykowanego,
            // bezpiecznego arkusza (tokeny + komponenty klasowe, box-sizing tylko w panelu).
            wp_enqueue_style('devents-wpadmin-dashboard', DEW_PLUGIN_URL . 'assets/css/admin/wpadmin-dashboard.css', [], $this->version);

            // wcag-core (focus trap modali) + devents-core (dark-mode + devents_config)
            wp_enqueue_script('devents-wcag-core', DEW_PLUGIN_URL . 'assets/js/frontend/wcag-core.js', [], $this->version, true);
            wp_enqueue_script('devents-core', DEW_PLUGIN_URL . 'assets/js/frontend/dark-mode.js', ['jquery'], $this->version, true);
            wp_localize_script('devents-core', 'devents_config', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'home_url' => home_url(),
                'nonce'    => wp_create_nonce('devents_payment_nonce'),
            ]);

            // Pełny bundle panelu admina (CSS dashboardu + wszystkie moduły JS + biblioteki).
            $this->enqueue_admin_panel_frontend_full();
            return;
        }

        // --- Pozostałe strony DEvents w wp-admin (np. Narzędzia) — minimalny zestaw ---
        wp_enqueue_style('devents-admin-style', DEW_PLUGIN_URL . 'assets/css/admin/admin.css', [], $this->version);
        wp_enqueue_style('material-symbols-outlined', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined');

        wp_enqueue_script('jquery');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);

        wp_enqueue_script('devents-admin-core', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-core.js', ['jquery'], $this->version, true);
        wp_localize_script('devents-admin-core', 'devents_admin_config', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('devents_admin_general_nonce')
        ]);
    }

    /**
     * FRONTEND (Portal dla Użytkowników i Customowy Panel)
     */
    public function enqueue_frontend_assets() {
        global $post;

        // --- 1. REJESTRACJA ZEWNĘTRZNYCH BIBLIOTEK ---
        $this->register_external_libraries();

        // --- 2. GŁÓWNA KONFIGURACJA (devents-core) ---
        wp_enqueue_script('devents-core', DEW_PLUGIN_URL . 'assets/js/frontend/dark-mode.js', ['jquery'], $this->version, true);
        wp_localize_script('devents-core', 'devents_config', [
            'ajax_url'              => admin_url('admin-ajax.php'),
            'home_url'              => home_url(),
            'nonce'                 => wp_create_nonce('devents_payment_nonce'), 
            'save_event_nonce'      => wp_create_nonce('devents_save_event_action'),
            'duplicate_event_nonce' => wp_create_nonce('devents_duplicate_event_action'), 
            'delete_event_nonce'    => wp_create_nonce('devents_delete_event_nonce'),
            'org_action_nonce'      => wp_create_nonce('devents_org_action_nonce'),
            'register_nonce'        => wp_create_nonce('devents_register_nonce'),
            'user_action_nonce'     => wp_create_nonce('devents_user_action_nonce'),
            'admin_nonce'           => wp_create_nonce('devents_admin_general_nonce'),
            'contact_nonce'         => wp_create_nonce('devents_contact_form_action'),
            'location_nonce'        => wp_create_nonce('devents_location_search_action'),
            'i18n_required'         => __( 'jest wymagane.', 'devents' ),
            'logo_light'            => 'https://devents.pl/wp-content/uploads/LOGO_DEvents/logo-DEvents-01.png',
            'logo_dark'             => 'https://devents.pl/wp-content/uploads/LOGO_DEvents/logo-DEvents-03.png',
            // Dane do autouzupełnienia modalu zamówienia (zamawiający + instytucja).
            'order_prefill'         => ( is_user_logged_in() && function_exists('devents_get_order_prefill') )
                                        ? devents_get_order_prefill( get_current_user_id() ) : null,
        ]);

        wp_enqueue_style('devents-google-fonts', 'https://fonts.googleapis.com/css2?family=Dosis:wght@300;400;700&display=swap');
        wp_enqueue_style('material-symbols-outlined', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
        wp_enqueue_style('devents-frontend-style', DEW_PLUGIN_URL . 'assets/css/frontend/frontend.css', [], $this->version);

        // --- GLOBALNIE: Ulubione i Obserwowanie ---
        // Karty wydarzeń (.btn-favorite) i organizatorów (.btn-follow) pojawiają się na
        // stronie głównej, w wyszukiwarce, na profilach i w panelu — dlatego oba skrypty
        // (małe, delegowane na body, z wewnętrznymi guardami) ładujemy wszędzie na froncie.
        // subscription-system.js ustępuje, jeśli stronę obsługuje organizer-profile/search
        // (flaga window.deventsFollowHandled), więc nie ma podwójnego bindowania.
        wp_enqueue_script('devents-favorites', DEW_PLUGIN_URL . 'assets/js/frontend/favorites.js', ['devents-core'], $this->version, true);
        wp_enqueue_script('devents-subscription-system', DEW_PLUGIN_URL . 'assets/js/frontend/subscription-system.js', ['devents-core'], $this->version, true);

        // --- WCAG 2.2 AA Core (skip link, aria-live, aria-pressed, focus) ---
        wp_enqueue_script(
            'devents-wcag-core',
            DEW_PLUGIN_URL . 'assets/js/frontend/wcag-core.js',
            [],
            $this->version,
            true
        );

        // --- Region Switcher (v8.1 — geofiltry, język migowy) ---
        wp_enqueue_script(
            'devents-region-switcher',
            DEW_PLUGIN_URL . 'assets/js/frontend/region-switcher.js',
            ['devents-wcag-core'],
            $this->version,
            true
        );

        // --- 3. DETEKCJA WIDOKU (Strony Główne i Panel Użytkownika) ---
        $content = is_a($post, 'WP_Post') ? $post->post_content : '';
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';

        // --- 4. ŁADOWANIE MODUŁÓW UŻYTKOWNIKA ---
        if ( has_shortcode($content, 'devents_user_panel') || !empty($view) ) {
            wp_enqueue_style('devents-panel-style', DEW_PLUGIN_URL . 'assets/css/frontend/users/my-panel.css', [], $this->version);
            wp_enqueue_script('devents-my-panel', DEW_PLUGIN_URL . 'assets/js/frontend/my-panel.js', ['devents-core'], $this->version, true);
            // Autouzupełnianie modalu zamówienia (wspólne dla wyróżnienia/PJM/raportu).
            wp_enqueue_script('devents-order-prefill', DEW_PLUGIN_URL . 'assets/js/frontend/order-prefill.js', ['devents-core'], $this->version, true);
            // Adaptacyjne tło pod logo instytucji (kontrast jasne/ciemne logo).
            wp_enqueue_script('devents-logo-contrast', DEW_PLUGIN_URL . 'assets/js/frontend/logo-contrast.js', [], $this->version, true);

            switch ($view) {
                case 'moje-wydarzenia':
                    wp_enqueue_style('cropper-css'); // Wymagane dla siatki kadrowania
                    wp_enqueue_script('devents-my-events', DEW_PLUGIN_URL . 'assets/js/frontend/my-events.js', ['devents-core', 'cropper-js'], $this->version, true);
                    break;
                case 'zamowienia':
                    // POPRAWKA: realny slug widoku to 'zamowienia' (wcześniej był błędny
                    // 'moje-zamowienia', przez co my-orders.js nigdy się nie ładował i
                    // przycisk „Dokończ płatność" nie działał).
                    wp_enqueue_script('devents-my-orders', DEW_PLUGIN_URL . 'assets/js/frontend/my-orders.js', ['devents-core'], $this->version, true);
                    break;
                case 'moje-ulubione':
                case 'moje-subskrypcje':
                    // Widoki listują karty wydarzeń z przyciskiem .btn-favorite obsługiwanym
                    // przez favorites.js (wcześniej ładowany tylko na stronach single).
                    wp_enqueue_script('devents-favorites', DEW_PLUGIN_URL . 'assets/js/frontend/favorites.js', ['devents-core'], $this->version, true);
                    break;
                case 'moje-opinie':
                case 'moje-pytania':
                    // Widoki „Pytania i odpowiedzi" / „Moje pytania" używają review-system.js
                    // (usuwanie pytań przez .btn-delete-review).
                    wp_enqueue_script('devents-review-system', DEW_PLUGIN_URL . 'assets/js/frontend/review-system.js', ['devents-core'], $this->version, true);
                    break;

                case 'zglos-problem':
                    wp_enqueue_script('devents-report-problem', DEW_PLUGIN_URL . 'assets/js/frontend/report-problem.js', ['devents-core'], $this->version, true);
                    wp_localize_script('devents-report-problem', 'devents_problem_cfg', [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'nonce'    => wp_create_nonce('devents_problem_nonce'),
                        'i18n'     => [
                            'sending' => __('Wysyłanie...', 'devents'),
                            'sent'    => __('Wysłano.', 'devents'),
                            'error'   => __('Wystąpił błąd.', 'devents'),
                            'too_big' => __('Plik jest za duży (maksymalnie 15 MB).', 'devents'),
                        ],
                    ]);
                    break;
                case 'ustawienia':
                    wp_enqueue_style('devents-my-account-css', DEW_PLUGIN_URL . 'assets/css/frontend/users/my-account.css', ['devents-panel-style'], $this->version);
                    wp_enqueue_script('devents-my-account', DEW_PLUGIN_URL . 'assets/js/frontend/my-account.js', ['devents-core'], $this->version, true);
                    
                    wp_localize_script('devents-my-account', 'devents_account_i18n', [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'i18n'     => [
                            'saving'                 => __( 'Zapisywanie...', 'devents' ),
                            'profile_updated'        => __( 'Dane profilowe zostały zaktualizowane.', 'devents' ),
                            'save_error'             => __( 'Wystąpił błąd podczas zapisu.', 'devents' ),
                            'connection_error'       => __( 'Wystąpił błąd połączenia z serwerem.', 'devents' ),
                            'passwords_not_match'    => __( 'Nowe hasła nie są identyczne.', 'devents' ),
                            'changing'               => __( 'Zmienianie...', 'devents' ),
                            'password_changed'       => __( 'Hasło zostało zmienione.', 'devents' ),
                            'password_change_error'  => __( 'Błąd zmiany hasła.', 'devents' ),
                            'server_error'           => __( 'Błąd serwera.', 'devents' ),
                            'change_password_btn'    => __( 'Zmień hasło', 'devents' ),
                            'enter_password_confirm' => __( 'Wpisz hasło, aby potwierdzić.', 'devents' ),
                            'delete_account_confirm' => __( 'UWAGA! Czy na pewno chcesz TRWALE USUNĄĆ swoje konto? Tej operacji nie można cofnąć.', 'devents' ),
                            'deleting'               => __( 'Usuwanie...', 'devents' ),
                            'account_deleted'        => __( 'Konto zostało usunięte. Żegnaj!', 'devents' ),
                            'wrong_password'         => __( 'Błędne hasło.', 'devents' ),
                            'delete_account_btn'     => __( 'Usuń konto na zawsze', 'devents' )
                        ]
                    ]);
                    break;
                case 'moje-jednostki':
                    wp_enqueue_script('devents-my-units', DEW_PLUGIN_URL . 'assets/js/frontend/my-units.js', ['devents-core'], $this->version, true);
                    // Plakaty instytucji (master) — html2canvas + generator
                    wp_enqueue_script('html2canvas');
                    wp_enqueue_script('devents-institution-poster', DEW_PLUGIN_URL . 'assets/js/frontend/institution-poster.js', ['jquery', 'html2canvas'], $this->version, true);
                    wp_localize_script('devents-institution-poster', 'devents_instPosterCfg', [
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'nonce'   => wp_create_nonce('devents_inst_poster'),
                    ]);
                    break;
                case 'moja-instytucja':
                    wp_enqueue_script('devents-my-institution', DEW_PLUGIN_URL . 'assets/js/frontend/my-institution.js', ['devents-core'], $this->version, true);
                    break;
                case 'moje-nagrody':
                    wp_enqueue_style('devents-my-rewards-css', DEW_PLUGIN_URL . 'assets/css/frontend/users/my-rewards.css', [], $this->version);
                    wp_enqueue_script('devents-my-rewards', DEW_PLUGIN_URL . 'assets/js/frontend/my-rewards.js', ['devents-core', 'html2canvas', 'jspdf'], $this->version, true);
                    break;
                case 'dodaj-wydarzenie':
                case 'edytuj-wydarzenie':
                    $this->enqueue_form_libraries();
                    // Grafiki marketingowe generowane po zapisie — html2canvas + moduł marketing
                    $this->enqueue_marketing_graphics();
                    wp_enqueue_script('devents-publish-event', DEW_PLUGIN_URL . 'assets/js/frontend/publish-event.js', ['devents-core', 'choices-js', 'flatpickr-js', 'flatpickr-pl-js', 'easymde-js', 'devents-marketing-graphics'], $this->version, true);
                    break;
                case 'panel-admina':
                    if (current_user_can('edit_posts')) {
                        $this->enqueue_admin_panel_frontend_full();
                    }
                    break;
            }
        }

        // Globalne skrypty frontendowe
        if ( is_front_page() || has_shortcode($content, 'homepage') ) {
            wp_enqueue_script('devents-homepage', DEW_PLUGIN_URL . 'assets/js/frontend/homepage.js', ['jquery'], $this->version, true);
        }
        if ( has_shortcode($content, 'register_form') ) {
            wp_enqueue_script('devents-register-form', DEW_PLUGIN_URL . 'assets/js/frontend/register-form.js', ['devents-core'], $this->version, true);
        }
        if ( has_shortcode($content, 'devents_password_reset') ) {
            wp_enqueue_script('devents-password-reset', DEW_PLUGIN_URL . 'assets/js/frontend/password-reset.js', ['jquery'], $this->version, true);
            // Bez tej lokalizacji password-reset.js przerywał działanie na starcie
            // (typeof devents_reset_object === 'undefined') i cały reset hasła był martwy.
            wp_localize_script('devents-password-reset', 'devents_reset_object', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('devents_reset_nonce'),
            ]);
        }
        if ( has_shortcode($content, 'event_search') || has_shortcode($content, 'video_search') ) {
            $this->enqueue_form_libraries();
            wp_enqueue_script('devents-search-forms', DEW_PLUGIN_URL . 'assets/js/frontend/search-forms.js', ['devents-core', 'choices-js', 'flatpickr-js', 'flatpickr-pl-js'], $this->version, true);
        }
        // Wyszukiwarka organizatorów (lista kart) — sortowanie + follow.
        if ( has_shortcode($content, 'organizer_search') ) {
            wp_enqueue_script('devents-organizer-search', DEW_PLUGIN_URL . 'assets/js/frontend/organizer-search.js', ['devents-core'], $this->version, true);
        }
        // Profil organizatora: obsługa follow, modala wizytówki PJM oraz sekcji „Pytania".
        // UWAGA: ładny URL /organizator/123/ ustawia query var 'institution_id' (a nie
        // 'organizer_id') — patrz reguły rewrite w public-profiles.php. Bez sprawdzenia
        // obu, organizer-profile.js NIE ładował się na profilu i modal PJM się nie otwierał.
        if ( get_query_var('organizer_id') || get_query_var('institution_id') ) {
            wp_enqueue_script('devents-organizer-profile', DEW_PLUGIN_URL . 'assets/js/frontend/organizer-profile.js', ['devents-core'], $this->version, true);
            wp_enqueue_script('devents-review-system', DEW_PLUGIN_URL . 'assets/js/frontend/review-system.js', ['devents-core'], $this->version, true);
        }
        if ( has_shortcode($content, 'devents_contact_form') ) {
            wp_enqueue_script('devents-contact-form', DEW_PLUGIN_URL . 'assets/js/frontend/contact-form.js', ['devents-core'], $this->version, true);
        }
        if ( has_shortcode($content, 'devents_newsletter_form') ) {
            wp_enqueue_script('devents-newsletter-form', DEW_PLUGIN_URL . 'assets/js/frontend/newsletter-form.js', ['devents-core'], $this->version, true);
        }

        // Systemy Interakcji (Widoki Single)
        if ( is_singular(['wydarzenia', 'materials']) ) {
            wp_enqueue_script('devents-report-system', DEW_PLUGIN_URL . 'assets/js/frontend/report-system.js', ['devents-core'], $this->version, true);
            wp_enqueue_script('devents-review-system', DEW_PLUGIN_URL . 'assets/js/frontend/review-system.js', ['devents-core'], $this->version, true);
            wp_enqueue_script('devents-favorites', DEW_PLUGIN_URL . 'assets/js/frontend/favorites.js', ['devents-core'], $this->version, true);
            wp_enqueue_script('devents-subscription-system', DEW_PLUGIN_URL . 'assets/js/frontend/subscription-system.js', ['devents-core'], $this->version, true);

            // Statystyka czasu spędzonego — tylko na stronach wydarzeń powiązanych z events_list.
            if ( is_singular('wydarzenia') ) {
                $tracked_event_id = (int) get_post_meta(get_the_ID(), '_event_id', true);
                if ( $tracked_event_id > 0 ) {
                    wp_enqueue_script('devents-time-tracker', DEW_PLUGIN_URL . 'assets/js/frontend/time-tracker.js', [], $this->version, true);
                    wp_localize_script('devents-time-tracker', 'devents_time_tracker', [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'event_id' => $tracked_event_id,
                        'nonce'    => wp_create_nonce('devents_track_time'),
                    ]);
                }
            }
        }
    }

    /**
     * 5. ŁADOWANIE PLIKÓW: PANEL ADMINA
     */
    private function enqueue_admin_panel_frontend_full() {
        wp_enqueue_style('devents-admin-dashboard-style', DEW_PLUGIN_URL . 'assets/css/frontend/admin/admin-dashboard.css', [], $this->version);
        // Style zakładek admina, które wcześniej nie były ładowane (zakładki wyświetlały
        // się bez stylów): Sprzedaż/Cennik/Kupony, Instytucje, Aktualności.
        wp_enqueue_style('devents-admin-sales-style', DEW_PLUGIN_URL . 'assets/css/frontend/admin/admin-sales.css', ['devents-admin-dashboard-style'], $this->version);
        wp_enqueue_style('devents-admin-institutions-style', DEW_PLUGIN_URL . 'assets/css/frontend/admin/admin-institutions.css', ['devents-admin-dashboard-style'], $this->version);
        wp_enqueue_style('devents-admin-news-style', DEW_PLUGIN_URL . 'assets/css/frontend/admin/admin-news.css', ['devents-admin-dashboard-style'], $this->version);

        $this->enqueue_form_libraries();
        
        wp_enqueue_script('chart-js'); 
        wp_enqueue_script('html2canvas');
        wp_enqueue_script('jspdf');

        wp_enqueue_script('devents-admin-core', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-core.js', ['jquery', 'devents-core'], $this->version, true);
        wp_localize_script('devents-admin-core', 'devents_admin_config', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('devents_admin_general_nonce')
        ]);

        wp_enqueue_script('devents-admin-init', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-init.js', ['devents-admin-core'], $this->version, true);
        
        wp_enqueue_script('devents-admin-actions-single', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-actions-single.js', ['devents-admin-core'], $this->version, true);
        wp_enqueue_script('devents-admin-bulk-actions', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-bulk-actions.js', ['devents-admin-core'], $this->version, true);
        wp_enqueue_script('devents-admin-import-csv', DEW_PLUGIN_URL . 'assets/js/frontend/admin/admin-import-csv.js', ['devents-admin-core'], $this->version, true);

        $modules = [
            'dashboard'    => ['devents-admin-core', 'chart-js'],
            'orders'       => ['devents-admin-core'],
            'users'        => ['devents-admin-core'],
            'events'       => ['devents-admin-core', 'choices-js', 'flatpickr-js', 'flatpickr-pl-js'], // Dodano locale
            'reports'      => ['devents-admin-core'],
            'settings'     => ['devents-admin-core'],
            'sales'        => ['devents-admin-core', 'chart-js'], 
            'coupons'      => ['devents-admin-core'],
            'news'         => ['devents-admin-core'],
            'institutions' => ['devents-admin-core', 'html2canvas', 'jspdf']
        ];

        foreach ($modules as $mod => $deps) {
            wp_enqueue_script('devents-admin-' . $mod, DEW_PLUGIN_URL . "assets/js/frontend/admin/admin-{$mod}.js", $deps, $this->version, true);
        }

        wp_enqueue_style('devents-generator-graphic-style', DEW_PLUGIN_URL . 'assets/css/frontend/generate-graphic.css', [], $this->version);
        wp_enqueue_script('devents-generate-graphic', DEW_PLUGIN_URL . 'assets/js/frontend/generate-graphic.js', ['devents-core', 'html2canvas'], $this->version, true);

        $this->enqueue_marketing_graphics();
    }

    /**
     * Zasoby grafik marketingowych (post/story × jasny/ciemny) — generowanie po
     * stronie klienta (html2canvas) + panel ALT/galeria. Bezpieczne do wielokrotnego
     * wywołania (wp_enqueue_* deduplikuje po uchwycie).
     */
    private function enqueue_marketing_graphics() {
        wp_enqueue_script('html2canvas');
        wp_enqueue_style('devents-generator-graphic-style', DEW_PLUGIN_URL . 'assets/css/frontend/generate-graphic.css', [], $this->version);
        wp_enqueue_style('devents-marketing-graphics-style', DEW_PLUGIN_URL . 'assets/css/frontend/marketing-graphics.css', [], $this->version);
        wp_enqueue_script('devents-marketing-graphics', DEW_PLUGIN_URL . 'assets/js/frontend/marketing-graphics.js', ['jquery', 'html2canvas'], $this->version, true);
        wp_localize_script('devents-marketing-graphics', 'deventsMarketingCfg', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('devents_marketing'),
            'i18n'    => [
                'generating' => __('Generuję grafiki…', 'devents'),
                'ready'      => __('Grafiki gotowe.', 'devents'),
                'error'      => __('Nie udało się wygenerować grafik.', 'devents'),
                'copied'     => __('Skopiowano!', 'devents'),
                'checking'   => __('Sprawdzam wydarzenia…', 'devents'),
                'all_done'   => __('Wszystkie nadchodzące wydarzenia mają już grafiki.', 'devents'),
                'done'       => __('Gotowe — wygenerowano grafiki dla', 'devents'),
                'events'     => __('wydarzeń.', 'devents'),
                'failed'     => __('nieudane', 'devents'),
            ],
        ]);
    }

    /**
     * Rejestracja bibliotek (CDN)
     */
    private function register_external_libraries() {
        wp_register_script('choices-js', 'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js', [], '10.2.0', true);
        wp_register_script('flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true);
        wp_register_script('flatpickr-pl-js', 'https://npmcdn.com/flatpickr/dist/l10n/pl.js', ['flatpickr-js'], '4.6.13', true); // Dodano
        wp_register_script('easymde-js', 'https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js', [], '2.18.0', true);
        
        wp_register_style('cropper-css', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css', [], '1.5.13'); // Dodano
        wp_register_script('cropper-js', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js', [], '1.5.13', true);
        
        wp_register_script('jspdf', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', [], '2.5.1', true);
        wp_register_script('html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', [], '1.4.1', true);
        
        wp_register_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
    }

    /**
     * Enqueue dla formularzy publikacji
     */
	private function enqueue_form_libraries() {
        // Zmieniono na ścieżkę lokalną wewnątrz wtyczki
        wp_enqueue_style('choices-css', DEW_PLUGIN_URL . 'assets/css/choices.min.css', [], $this->version);
        
        wp_enqueue_style('flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        wp_enqueue_style('easymde-css', 'https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css');
        
        wp_enqueue_script('choices-js');
        wp_enqueue_script('flatpickr-js');
        wp_enqueue_script('flatpickr-pl-js'); 
        wp_enqueue_script('easymde-js');
    }
}

new DEvents_Assets();