<?php
/**
 * Plik: class-devents-admin-dashboard.php
 * Kompleksowe zarządzanie panelem administracyjnym na froncie.
 * Wersja: 8.9.3 (Przywrócona pełna logika filtrów + Naprawa nagród instytucji)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DEvents_Admin_Dashboard {
    private $twig_helper;
    public $twig;

    /**
     * Baza URL dla linków panelu — pozwala renderować ten sam dashboard
     * na froncie ([devents_admin_panel]) ORAZ w wp-admin (menu „DEvents Manager").
     */
    private $base_url;
    private $base_args = [ 'view' => 'panel-admina' ];

    /** Buduje link panelu względem aktualnego kontekstu (front vs wp-admin). */
    private function panel_link( $extra = [] ) {
        $base = $this->base_url ?: home_url( '/panel-uzytkownika/' );
        return add_query_arg( array_merge( $this->base_args, $extra ), $base );
    }

    public function __construct() {
        if (class_exists('DEvents_Twig_Helper')) {
            $this->twig = DEvents_Twig_Helper::get_instance()->twig;
        } else {
            wp_die('Błąd krytyczny: Klasa DEvents_Twig_Helper nie została znaleziona.');
        }
    }

    public function render( $admin_context = false ) {
        if (!current_user_can('edit_posts')) return '<div class="message-box message-box--error">Brak uprawnień do przeglądania panelu.</div>';

        // Kontekst URL: ten sam dashboard obsługuje front i wp-admin.
        if ( $admin_context ) {
            $this->base_url  = admin_url( 'admin.php' );
            $this->base_args = [ 'page' => 'devents-manager' ];
        } else {
            $this->base_url  = home_url( '/panel-uzytkownika/' );
            $this->base_args = [ 'view' => 'panel-admina' ];
        }

        // 1. Pobieranie widoku (Edycja lub Panel)
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'panel-admina';

        // --- TRYB EDYCJI WYDARZENIA ---
        if ($view === 'edytuj-wydarzenie') {
            return $this->render_edit_event_view();
        }

        // --- TRYB DASHBOARDU ---
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        
        // Definicja Menu
        $menu_items = [
            'overview'          => ['title' => 'Przegląd', 'icon' => 'dashboard'],
            'orders'            => ['title' => 'Zamówienia', 'icon' => 'shopping_cart'],
            'events'            => ['title' => 'Wydarzenia', 'icon' => 'event_note'],
            'special_occasions' => ['title' => 'Festiwale', 'icon' => 'celebration'],
            'news'              => ['title' => 'Aktualności', 'icon' => 'newspaper'],
            'users'             => ['title' => 'Użytkownicy', 'icon' => 'group'],
            'institutions'      => ['title' => 'Instytucje', 'icon' => 'domain'],
            'contact'           => ['title' => 'Kontakt', 'icon' => 'forum'],
            'audit_log'         => ['title' => 'Dziennik zdarzeń', 'icon' => 'history'],
            'reports'           => ['title' => 'Zgłoszenia', 'icon' => 'report_problem'],
            'problem_reports'   => ['title' => 'Zgłoszone problemy', 'icon' => 'support_agent'],
            'surveys'           => ['title' => 'Ankiety', 'icon' => 'reviews'],
            // Klucze API scalone z zakładką „Ustawienia".
            'settings'          => ['title' => 'Ustawienia', 'icon' => 'settings'],
        ];

        // Pobieramy dane dla aktywnej zakładki
        $tab_data = $this->get_tab_data($active_tab);

        // Obsługa modułu sprzedaży (statystyki cen, kupony - jeśli klasa istnieje)
        if (in_array($active_tab, ['orders', 'pricing', 'coupons']) && class_exists('DEvents_Admin_Sales_Handler')) {
            $sales_handler = new DEvents_Admin_Sales_Handler();
            $sales_data = $sales_handler->prepare_sales_dashboard_data([]);
            $tab_data = array_merge($tab_data, $sales_data);
        }

        // Scalamy kontekst dla Twiga
        $context = array_merge(['active_tab' => $active_tab, 'menu_items' => $menu_items], $tab_data);
        $context['tab_data'] = $tab_data; // Kompatybilność wsteczna
        $context['panel_base'] = $this->panel_link(); // baza dla linków nawigacji (bez tab)

        return $this->twig->render('admin/admin-panel.twig', $context);
    }

    // --- WIDOK EDYCJI WYDARZENIA ---
    private function render_edit_event_view() {
        $event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
        $event_data = $this->get_event_data($event_id);
        
        if (!$event_data) {
            return '<div class="notice notice-error"><p>Wydarzenie nie istnieje.</p></div><a href="'.esc_url($this->panel_link(['tab' => 'events'])).'" class="button">Wróć</a>';
        }

        global $wpdb;
        $settings_table  = $wpdb->prefix . 'events_settings';
        $types_table     = $wpdb->prefix . 'devents_accessibility_types';
        $rel_table       = $wpdb->prefix . 'devents_event_accessibility';

        // Pobierz typy dostepnosci (v8.0)
        $accessibility_types = $wpdb->get_results(
            "SELECT id, slug, name, icon FROM {$types_table} WHERE is_active = 1 ORDER BY sort_order ASC"
        ) ?: [];

        // Pobierz zaznaczone IDs dla tego wydarzenia
        $selected_accessibility_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT accessibility_id FROM {$rel_table} WHERE event_id = %d",
            $event_id
        )));

        // (Usunięto martwy fallback wywołujący migrate_accessibility_to_relational() — metoda
        //  nie istniała, więc blok i tak nigdy się nie wykonywał.)

        // Pobierz jezyki migowe (v8.0)
        $sl_table    = $wpdb->prefix . 'devents_sign_languages';
        $sign_languages = ($wpdb->get_var("SHOW TABLES LIKE '{$sl_table}'") === $sl_table)
            ? $wpdb->get_results("SELECT country_code, country_name, sl_code, sl_name, spoken_code, spoken_name FROM {$sl_table} WHERE is_active = 1 ORDER BY sort_order ASC") ?: []
            : [];

        $context = [
            'event'                      => $event_data,
            'is_edit'                    => true,
            'is_admin_editing'           => true,
            'user'                       => wp_get_current_user(),
            'back_url'                   => $this->panel_link(['tab' => 'events']),
            'event_categories'           => $wpdb->get_col("SELECT setting_value FROM {$settings_table} WHERE setting_type = 'event_category' ORDER BY setting_value ASC"),
            'event_methods'              => ['Na żywo', 'Hybrydowo', 'Online'],
            'accessibility_types'        => $accessibility_types,
            'selected_accessibility_ids' => $selected_accessibility_ids,
            'sign_languages'             => $sign_languages,
            'active_occasions'           => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}devents_special_occasions ORDER BY start_date DESC"),
        ];

        return $this->twig->render('pages/publish-event.twig', $context);
    }

    private function get_event_data($id) {
        global $wpdb;
        if (!$id) return null;
        $table = $wpdb->prefix . 'events_list';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }
    
    // --- POBIERANIE DANYCH ZAKŁADEK (ROUTER DANYCH) ---
    private function get_tab_data($active_tab) {
        global $wpdb;
        $tab_data = ['panel_url' => $this->panel_link()];

        $events_table = $wpdb->prefix . 'events_list';
        $users_table = $wpdb->prefix . 'users';

        switch ($active_tab) {
            case 'overview':
                $overview_data = [];

                // Średni czas spędzony na stronach wydarzeń (sekundy → „Xm Ys").
                $time_totals = $wpdb->get_row("SELECT SUM(total_time_spent) AS secs, SUM(time_visits) AS visits FROM {$events_table}");
                $avg_seconds = ($time_totals && $time_totals->visits > 0)
                    ? (int) round($time_totals->secs / $time_totals->visits)
                    : 0;
                $avg_time_label = $avg_seconds > 0
                    ? sprintf('%dm %02ds', intdiv($avg_seconds, 60), $avg_seconds % 60)
                    : '—';

                $overview_data['stats'] = [
                    'total_users'        => count_users()['total_users'],
                    'total_institutions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}devents_institutions"),
                    'total_views'        => (int) $wpdb->get_var("SELECT SUM(views) FROM {$events_table}"),
                    'avg_time_spent'     => $avg_time_label,
                ];

                $recent_users = get_users(['number' => 5, 'orderby' => 'user_registered', 'order' => 'DESC']);
                $role_labels = [
                    'administrator'        => 'Administrator',
                    'master_organizer'     => 'Master',
                    'master_organizer_mod' => 'Master (mod.)',
                    'organizer'            => 'Organizator',
                    'organizer_mod'        => 'Organizator (mod.)',
                    'subscriber'           => 'Subskrybent',
                ];
                foreach ($recent_users as $u) {
                    $first = get_user_meta($u->ID, 'first_name', true);
                    $last  = get_user_meta($u->ID, 'last_name', true);
                    $u->full_name  = trim($first . ' ' . $last) ?: $u->display_name;
                    $role          = !empty($u->roles) ? $u->roles[0] : '';
                    $u->role_label = $role_labels[$role] ?? ($role ?: '—');
                    $inst_id       = get_user_meta($u->ID, 'devents_institution_id', true);
                    $u->institution = $inst_id
                        ? ($wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}devents_institutions WHERE id = %d", $inst_id)) ?: '')
                        : '';
                }
                $overview_data['recent_users'] = $recent_users;

                // Ranking publikacji — TYLKO organizatorzy / master (z nazwą instytucji
                // lub organizatora), bez zwykłych użytkowników. Liczymy po user_id,
                // a role i nazwę rozstrzygamy w PHP (role siedzą w usermeta, nie w SQL).
                $raw_ranking = $wpdb->get_results("
                    SELECT e.user_id, COUNT(e.id) as count
                    FROM {$events_table} e
                    WHERE e.verified = 1 AND e.user_id > 0
                    GROUP BY e.user_id
                    ORDER BY count DESC
                    LIMIT 40
                ");

                $org_roles = ['organizer', 'organizer_mod', 'master_organizer', 'master_organizer_mod'];
                $ranking   = [];
                foreach ($raw_ranking as $row) {
                    $u = get_userdata($row->user_id);
                    if (!$u || !array_intersect($org_roles, (array) $u->roles)) continue;

                    // Nazwa: instytucja > nazwa organizatora (meta) > display_name.
                    $name    = $u->display_name;
                    $inst_id = get_user_meta($u->ID, 'devents_institution_id', true);
                    if ($inst_id) {
                        $inst_name = $wpdb->get_var($wpdb->prepare(
                            "SELECT name FROM {$wpdb->prefix}devents_institutions WHERE id = %d", $inst_id
                        ));
                        if ($inst_name) $name = $inst_name;
                    } else {
                        $org_name = get_user_meta($u->ID, 'org_name', true);
                        if ($org_name) $name = $org_name;
                    }

                    $ranking[] = (object) ['display_name' => $name, 'count' => (int) $row->count];
                    if (count($ranking) >= 5) break;
                }
                $overview_data['user_ranking'] = $ranking;

                $tab_data = array_merge($tab_data, $overview_data);
                break;

            case 'orders': 
            case 'events': 
            case 'special_occasions': 
            case 'news': 
            case 'users': 
            case 'institutions': 
                $tab_data = array_merge($tab_data, $this->get_list_table_data($active_tab));
                if ($active_tab === 'events') {
                    $tab_data['special_occasions'] = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}devents_special_occasions ORDER BY name ASC");
                }
                break;
            
            case 'audit_log':
                $logs_table = $wpdb->prefix . 'devents_audit_log';
                $usermeta_table = $wpdb->prefix . 'usermeta';

                $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                $per_page = 20;
                $offset = ($paged - 1) * $per_page;
                
                $log_user_type = isset($_GET['log_user_type']) ? sanitize_key($_GET['log_user_type']) : 'all';
                $where_sql = '';
                $params = [];

                if ($log_user_type === 'admin') {
                    $where_sql = "WHERE EXISTS (SELECT 1 FROM {$usermeta_table} um WHERE um.user_id = al.user_id AND um.meta_key = %s AND um.meta_value LIKE %s)";
                    $params[] = $wpdb->prefix . 'capabilities';
                    $params[] = '%administrator%';
                }

                $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$logs_table} al {$where_sql}", $params));

                $query_params = array_merge($params, [$per_page, $offset]);
                $tab_data['audit_logs'] = $wpdb->get_results($wpdb->prepare(
                    "SELECT al.*, u.display_name as admin_name FROM {$logs_table} al LEFT JOIN {$users_table} u ON al.user_id = u.ID {$where_sql} ORDER BY al.log_time DESC LIMIT %d OFFSET %d",
                    $query_params
                ));
                
                $tab_data['total_items'] = $total_items;

                // FIX paginacji: add_query_arg() koduje '%#%' na '%25%23%25', przez co
                // paginate_links nie podstawiał numeru strony (wszystkie linki = ta sama
                // strona). Budujemy czysty bazowy URL i dopinamy placeholder DOSŁOWNIE.
                $base_clean = remove_query_arg( 'paged', $this->panel_link([
                    'tab'           => 'audit_log',
                    'log_user_type' => $log_user_type,
                ]) );
                $sep = ( strpos( $base_clean, '?' ) !== false ) ? '&' : '?';

                $tab_data['pagination_links'] = paginate_links([
                    'base'      => $base_clean . $sep . 'paged=%#%',
                    'format'    => '',
                    'total'     => max( 1, (int) ceil( $total_items / $per_page ) ),
                    'current'   => $paged,
                    'prev_text' => '«', 'next_text' => '»',
                ]);
                break;

            case 'reports':
                $reports_table = $wpdb->prefix . 'devents_reports';
                $tab_data['reports'] = $wpdb->get_results("
                    SELECT r.*, u.display_name as reporter_name
                    FROM {$reports_table} r
                    LEFT JOIN {$users_table} u ON r.reporter_user_id = u.ID
                    ORDER BY r.reported_at DESC
                ");
                break;

            case 'problem_reports':
                $pr_table = $wpdb->prefix . 'devents_problem_reports';
                $tab_data['problem_reports'] = [];
                if ( $wpdb->get_var("SHOW TABLES LIKE '{$pr_table}'") === $pr_table ) {
                    $tab_data['problem_reports'] = $wpdb->get_results("
                        SELECT pr.*, u.display_name as reporter_name, u.user_email as reporter_email
                        FROM {$pr_table} pr
                        LEFT JOIN {$users_table} u ON pr.user_id = u.ID
                        ORDER BY (pr.status = 'new') DESC, pr.created_at DESC
                        LIMIT 300
                    ");
                }
                break;

            case 'contact':
                $contact_table = $wpdb->prefix . 'devents_contact_messages';
                $threads = [];
                if ( $wpdb->get_var("SHOW TABLES LIKE '{$contact_table}'") === $contact_table ) {
                    // Wątki = wiadomości przychodzące (parent_id=0), najnowsze u góry.
                    $threads = $wpdb->get_results(
                        "SELECT * FROM {$contact_table} WHERE parent_id = 0 ORDER BY created_at DESC LIMIT 200"
                    );
                    foreach ( $threads as $t ) {
                        $t->replies = $wpdb->get_results( $wpdb->prepare(
                            "SELECT * FROM {$contact_table} WHERE parent_id = %d ORDER BY created_at ASC",
                            $t->id
                        ) );
                        $t->created_at_fmt = mysql2date('d.m.Y H:i', $t->created_at);
                        foreach ( $t->replies as $r ) {
                            $r->created_at_fmt = mysql2date('d.m.Y H:i', $r->created_at);
                        }
                    }
                    $tab_data['contact_unread'] = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$contact_table} WHERE parent_id = 0 AND is_read = 0"
                    );
                }
                $tab_data['contact_threads'] = $threads;
                break;

            case 'surveys':
                $survey = function_exists('devents_get_survey_data') ? devents_get_survey_data(300) : ['responses' => [], 'count' => 0, 'avg' => 0, 'distribution' => []];
                $tab_data['survey_responses']    = $survey['responses'];
                $tab_data['survey_count']        = $survey['count'];
                $tab_data['survey_avg']          = $survey['avg'];
                $tab_data['survey_distribution'] = $survey['distribution'];
                break;

            case 'api-keys':
                // „Jedno miejsce na wszystkie klucze API" — Stripe, Social (FB/IG),
                // integracja januszmigowego.pl (PJM) oraz powiadomienia push (VAPID).
                $tab_data['stripe'] = [
                    'secret_key'     => get_option('devents_stripe_secret_key', ''),
                    'webhook_secret' => get_option('devents_stripe_webhook_secret', ''),
                    'webhook_url'    => home_url('/wp-json/devents/v1/stripe-webhook'),
                ];
                $tab_data['social'] = [
                    'fb_page_id'      => get_option('devents_fb_page_id', ''),
                    'ig_account_id'   => get_option('devents_ig_account_id', ''),
                    'fb_access_token' => get_option('devents_fb_access_token', ''),
                    'post_to_story'   => get_option('devents_post_to_story', '1') !== '0',
                ];
                $remote_key = get_option('devents_remote_api_key', '');
                $tab_data['remote'] = [
                    'api_key'         => $remote_key,
                    'url'             => class_exists('DEvents_Order_Handler') ? DEvents_Order_Handler::REMOTE_API_URL : 'https://januszmigowego.pl/wp-json/pjm/v1/create-order',
                    'constant_active' => defined('DEVENTS_REMOTE_API_KEY'),
                    'no_key'          => ( $remote_key === '' && ! defined('DEVENTS_REMOTE_API_KEY') ),
                ];
                break;

            case 'settings':
                $log_file_path = WP_CONTENT_DIR . '/debug.log';
                $log_content = 'Plik debug.log nie istnieje lub jest pusty.';
                if (file_exists($log_file_path) && filesize($log_file_path) > 0) {
                    $file = file($log_file_path);
                    $log_content = implode("", array_reverse(array_slice($file, -50)));
                }
                $tab_data['error_log_content'] = esc_html($log_content);

                $tab_data['stripe'] = [
                    'secret_key'     => get_option('devents_stripe_secret_key', ''),
                    'webhook_secret' => get_option('devents_stripe_webhook_secret', ''),
                    'webhook_url'    => home_url('/wp-json/devents/v1/stripe-webhook'),
                ];

                $tab_data['social'] = [
                    'fb_page_id'      => get_option('devents_fb_page_id', ''),
                    'ig_account_id'   => get_option('devents_ig_account_id', ''),
                    'fb_access_token' => get_option('devents_fb_access_token', ''),
                    'post_to_story'   => get_option('devents_post_to_story', '1') !== '0',
                ];

                // Klucz API integracji PJM (scalony do zakładki Ustawienia).
                $remote_key = get_option('devents_remote_api_key', '');
                $tab_data['remote'] = [
                    'api_key'         => $remote_key,
                    'url'             => class_exists('DEvents_Order_Handler') ? DEvents_Order_Handler::REMOTE_API_URL : 'https://januszmigowego.pl/wp-json/pjm/v1/create-order',
                    'constant_active' => defined('DEVENTS_REMOTE_API_KEY'),
                    'no_key'          => ( $remote_key === '' && ! defined('DEVENTS_REMOTE_API_KEY') ),
                ];
                $tab_data['fakturownia'] = [
                    'token'  => get_option('devents_fakturownia_token', ''),
                    'domain' => get_option('devents_fakturownia_domain', ''),
                ];

                $tab_data['maintenance_mode'] = get_option('devents_maintenance_mode', false);
                break;
        }
        $tab_data['vapid_public'] = get_option('devents_vapid_public', '');
        return $tab_data;
    }

    // --- GŁÓWNA METODA DO LISTOWANIA DANYCH (TABELE) ---
    private function get_list_table_data($type) {
        global $wpdb;
        $data = [];
        $users_table = $wpdb->prefix . 'users';
        $settings_table = $wpdb->prefix . 'events_settings';
        
        $search  = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $paged   = get_query_var('paged') ? get_query_var('paged') : (isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        
        $current_role = isset($_GET['role']) ? sanitize_key($_GET['role']) : 'all'; 
        $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all'; 
        $current_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
        if ($current_per_page < 1 && $current_per_page !== -1) $current_per_page = 20; 

        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : '';
        $order   = isset($_GET['order']) ? strtoupper(sanitize_key($_GET['order'])) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';

        // Filtr nagród (współdzielony w widoku instytucji)
        $reward_status = isset($_GET['reward_status']) ? sanitize_key($_GET['reward_status']) : '';

        switch ($type) {
            case 'orders':
                $table_name = $wpdb->prefix . 'devents_orders';
                $offset = ($paged - 1) * $current_per_page;
                
                $where = "1=1";
                $params = [];
                
                if ($search) {
                    $where .= " AND (order_number LIKE %s OR buyer_email LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                }

                $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$table_name} WHERE {$where}", $params));
                $params[] = $current_per_page;
                $params[] = $offset;
                
                $data['orders'] = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.*, u.display_name as user_name, u.user_login, u.user_email as user_account_email
                     FROM {$table_name} o 
                     LEFT JOIN {$users_table} u ON o.user_id = u.ID 
                     WHERE {$where} 
                     ORDER BY o.created_at DESC 
                     LIMIT %d OFFSET %d",
                    $params
                ));
                $data['total_items'] = $total_items;
                $data['bulk_actions'] = [
                    'mark_paid' => 'Oznacz jako opłacone',
                    'delete_order_bulk' => 'Usuń zaznaczone'
                ];
                break;

            case 'special_occasions':
                $table_name = $wpdb->prefix . 'devents_special_occasions';
                $offset = absint(($paged - 1) * $current_per_page);
                
                $where = "1=1";
                $params = [];

                if (!empty($search)) {
                    $where .= " AND (name LIKE %s OR description LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                }

                $count_sql = "SELECT COUNT(id) FROM {$table_name} WHERE {$where}";
                $total_items = !empty($params) ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

                $query_params = $params;
                $query_params[] = $current_per_page;
                $query_params[] = $offset;

                $sql = "SELECT * FROM {$table_name} WHERE {$where} ORDER BY start_date DESC LIMIT %d OFFSET %d";
                $results = $wpdb->get_results($wpdb->prepare($sql, $query_params));
                
                $data['occasions'] = is_array($results) ? $results : [];
                $data['total_items'] = $total_items;
                $data['bulk_actions'] = ['delete_occasion_bulk' => 'Usuń zaznaczone'];
                break;

            case 'news':
                $table_name = $wpdb->prefix . 'devents_news';
                $offset = absint(($paged - 1) * $current_per_page);
                
                $where = "1=1";
                $params = [];

                if (!empty($search)) {
                    $where .= " AND (title LIKE %s OR content LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                }

                $count_sql = "SELECT COUNT(id) FROM {$table_name} WHERE {$where}";
                $total_items = !empty($params) ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

                $query_params = $params;
                $query_params[] = $current_per_page;
                $query_params[] = $offset;
                
                // FIX: tabela devents_news NIE ma kolumny author_id — poprzedni JOIN
                // (n.author_id = u.ID) powodował błąd SQL i PUSTĄ listę, przez co
                // aktualności „nie wyświetlały się" ani nie pojawiały po dodaniu.
                $sql = "SELECT n.*
                        FROM {$table_name} n
                        WHERE {$where}
                        ORDER BY n.is_pinned DESC, n.publish_date DESC
                        LIMIT %d OFFSET %d";

                $results = $wpdb->get_results($wpdb->prepare($sql, $query_params));

                // FIX #5: szablon news.twig iteruje po `tab_data.news_items`, a kontroler
                // ustawiał `news` → lista zawsze pusta. Ustawiamy poprawny klucz (+ alias).
                $news_rows = is_array($results) ? $results : [];
                $data['news_items'] = $news_rows;
                $data['news'] = $news_rows;
                $data['total_items'] = $total_items;
                $data['bulk_actions'] = ['delete_news_bulk' => 'Usuń zaznaczone'];
                break;

            case 'events':
                $table_name = $wpdb->prefix . 'events_list';
                $where = "1=1";
                $params = [];
                
                if ($search) {
                    $where .= " AND (e.title LIKE %s OR u.display_name LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                }

                if ($current_status !== 'all') {
                    if ($current_status === 'pending') {
                        $where .= " AND e.verified IN (0, 3)";
                    } elseif ($current_status === 'published') {
                        $where .= " AND e.verified = 1";
                    } elseif ($current_status === 'draft') {
                        $where .= " AND e.verified = 2";
                    }
                }

                // Filtr publikacji w social media.
                $social_filter = isset($_GET['social']) ? sanitize_key($_GET['social']) : 'all';
                if ($social_filter !== 'all') {
                    if ($social_filter === 'published') {
                        $where .= " AND e.social_published = 1";
                    } elseif ($social_filter === 'not_published') {
                        $where .= " AND e.social_published = 0";
                    } elseif ($social_filter === 'scheduled') {
                        $where .= " AND e.social_published = 0 AND e.verified = 1 AND e.start_datetime > %s";
                        $params[] = current_time('mysql');
                    } elseif ($social_filter === 'auto') {
                        $where .= " AND e.social_publish_method = 'auto'";
                    } elseif ($social_filter === 'manual') {
                        $where .= " AND e.social_publish_method = 'manual'";
                    }
                }

                if (!empty($_GET['date_from'])) {
                    $where .= " AND e.start_datetime >= %s";
                    $params[] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
                }
                if (!empty($_GET['date_to'])) {
                    $where .= " AND e.start_datetime <= %s";
                    $params[] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
                }

                if (!empty($_GET['category']) && is_array($_GET['category'])) {
                    $cat_clauses = [];
                    foreach ($_GET['category'] as $cat) {
                        $cat_clauses[] = "e.category LIKE %s";
                        $params[] = '%' . $wpdb->esc_like($cat) . '%';
                    }
                    if (!empty($cat_clauses)) {
                        $where .= " AND (" . implode(' OR ', $cat_clauses) . ")";
                    }
                }

                if (!empty($_GET['accessibility']) && is_array($_GET['accessibility'])) {
                    $acc_ids   = array_filter(array_map('intval', $_GET['accessibility']));
                    $acc_count = count($acc_ids);
                    if ($acc_count > 0) {
                        $acc_ph  = implode(',', array_fill(0, $acc_count, '%d'));
                        $rel_t   = $wpdb->prefix . 'devents_event_accessibility';
                        $where  .= " AND (
                            SELECT COUNT(DISTINCT ea_a.accessibility_id)
                            FROM {$rel_t} ea_a
                            WHERE ea_a.event_id = e.id
                            AND ea_a.accessibility_id IN ({$acc_ph})
                        ) = {$acc_count}";
                        foreach ($acc_ids as $aid) { $params[] = $aid; }
                    }
                }

                $allowed_sort_columns = [
                    'id' => 'e.id',
                    'title' => 'e.title',
                    'author' => 'u.display_name',
                    'start_datetime' => 'e.start_datetime',
                    'views' => 'e.views'
                ];
                $sql_orderby = isset($allowed_sort_columns[$orderby]) ? $allowed_sort_columns[$orderby] : 'e.created_at';

                $total_items = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(e.id) FROM {$table_name} e LEFT JOIN {$users_table} u ON e.user_id = u.ID WHERE {$where}", 
                    $params
                ));
                
                $offset = ($paged - 1) * $current_per_page;
                $params[] = $current_per_page;
                $params[] = $offset;
                
                $items_raw = $wpdb->get_results($wpdb->prepare(
                    "SELECT e.*, u.display_name as author_name 
                     FROM {$table_name} e 
                     LEFT JOIN {$users_table} u ON e.user_id = u.ID 
                     WHERE {$where} 
                     ORDER BY {$sql_orderby} {$order} 
                     LIMIT %d OFFSET %d",
                    $params
                ));
                
                $data['events'] = array_map(function($item) {
                    $item->status_info   = $this->get_status_info_for_item($item->verified);
                    $item->social_status = $this->get_social_status_for_event($item);
                    return $item;
                }, $items_raw);
                
                $data['total_items'] = $total_items;

                $data['all_categories']      = $wpdb->get_col("SELECT setting_value FROM {$settings_table} WHERE setting_type = 'event_category' ORDER BY setting_value ASC");
                $data['accessibility_types'] = $wpdb->get_results(
                    "SELECT id, slug, name, icon FROM {$wpdb->prefix}devents_accessibility_types WHERE is_active = 1 ORDER BY sort_order ASC"
                ) ?: [];
                
                $base_url = remove_query_arg(['orderby', 'order']);
                $new_order = ($order === 'ASC') ? 'desc' : 'asc';
                foreach (['id', 'title', 'author', 'start_datetime', 'views'] as $col) {
                    $data['sort_links'][$col] = add_query_arg(['orderby' => $col, 'order' => $new_order], $base_url);
                    $data['sort_arrows'][$col] = ($orderby === $col) ? (($order === 'ASC') ? ' ▲' : ' ▼') : '';
                }
                
                $data['bulk_actions'] = [
                    'verify_event_bulk' => 'Zatwierdź', 
                    'reject_event_bulk' => 'Odrzuć', 
                    'delete_event_bulk' => 'Usuń'
                ];
                break;

            case 'users':
                $per_page = ($current_per_page === -1) ? 999999 : $current_per_page;
                $offset = ($paged - 1) * $current_per_page;
                
                $args = [
                    'number'      => $per_page,
                    'paged'       => $paged,
                    'orderby'     => 'registered',
                    'order'       => 'DESC',
                    'count_total' => true,
                ];

                if ($search) {
                    $args['search'] = '*' . esc_attr($search) . '*';
                    $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
                }
                
                $valid_roles = ['organizer', 'subscriber', 'administrator'];
                if ($current_role !== 'all' && in_array($current_role, $valid_roles)) {
                    $args['role'] = $current_role;
                }

                $user_query = new WP_User_Query($args);
                $items_raw = $user_query->get_results();
                $total_items = $user_query->get_total();
                
                $data['users'] = []; 

                if (!empty($items_raw)) {
                    $data['users'] = array_map(function($user) {
                        global $wpdb;
                        $inst_id = get_user_meta($user->ID, 'devents_institution_id', true);
                        if ($inst_id) {
                            $inst_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}devents_institutions WHERE id = %d", $inst_id));
                            $user->institution_name = $inst_name ? $inst_name : 'ID: ' . $inst_id;
                        } else {
                            $user->institution_name = null;
                        }

                        $user_meta_raw = get_user_meta($user->ID, '_devents_organizer_data', true);
                        $user_data = is_array($user_meta_raw) ? $user_meta_raw : [];
                        $user->logo_url = !empty($user_data['logo_url']) ? $user_data['logo_url'] : get_avatar_url($user->ID);
                        
                        // Nazwa w liście = IMIĘ I NAZWISKO osoby (nazwa instytucji jest osobno).
                        $first = get_user_meta($user->ID, 'first_name', true);
                        $last  = get_user_meta($user->ID, 'last_name', true);
                        $full_name = trim($first . ' ' . $last);
                        $user->display_name_final = ($full_name !== '') ? $full_name : $user->display_name;

                        $user->is_verified_organizer = (bool) get_user_meta($user->ID, 'is_verified_organizer', true);
                        $user->has_pending_reward = false; // Nagrody przeniesione do instytucji
                        
                        return $user;
                    }, $items_raw);
                }

                $data['bulk_actions'] = [
                    'send_email_user'        => 'Wyślij e-mail',
                    'verify_organizer'       => 'Zweryfikuj',
                    'unverify_organizer'     => 'Cofnij weryfikację',
                    'role_to_organizer'      => 'Rola: Organizator',
                    'role_to_subscriber'     => 'Rola: Subskrybent',
                    'delete'                 => 'Usuń trwale'
                ];
                
                $data['total_items'] = $total_items;
                break;

            // --- INSTYTUCJE (POPRAWIONA LOGIKA NAGRÓD) ---
            case 'institutions': 
                $table_name = $wpdb->prefix . 'devents_institutions';
                $events_table = $wpdb->prefix . 'events_list';
                
                $offset = ($paged - 1) * $current_per_page;
                
                // Liczenie wydarzeń TYLKO dla danej organizacji
                $select_sql = "SELECT i.*, 
                              (SELECT COUNT(e.id) FROM {$events_table} e 
                               WHERE e.institution_id = i.id AND e.verified = 1
                              ) as events_count
                              FROM {$table_name} i";

                $where = "1=1";
                $params = [];

                if ($search) {
                    $where .= " AND (i.name LIKE %s OR i.metadata LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                    $params[] = '%' . $wpdb->esc_like($search) . '%';
                }

                $filter_type = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';
                if ($filter_type === 'master' || $filter_type === 'unit') {
                    $where .= " AND i.type = %s";
                    $params[] = $filter_type;
                }

                $count_sql = "SELECT COUNT(i.id) FROM {$table_name} i WHERE {$where}";
                $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

                $sql_orderby = 'i.created_at DESC';
                if ($orderby === 'name_asc') $sql_orderby = 'i.name ASC';
                if ($orderby === 'events_desc') $sql_orderby = 'events_count DESC';
                if ($orderby === 'events_asc') $sql_orderby = 'events_count ASC';
                if ($orderby === 'created_at_desc') $sql_orderby = 'i.created_at DESC';

                // Pobranie danych
                if ($reward_status === 'pending') {
                    $full_sql = "{$select_sql} WHERE {$where} ORDER BY {$sql_orderby}";
                    $items_raw = $wpdb->get_results($wpdb->prepare($full_sql, $params));
                } else {
                    $limit_sql = ($current_per_page !== -1) ? " LIMIT %d OFFSET %d" : "";
                    $full_sql = "{$select_sql} WHERE {$where} ORDER BY {$sql_orderby} {$limit_sql}";
                    
                    if ($current_per_page !== -1) {
                        $params[] = $current_per_page;
                        $params[] = $offset;
                    }
                    $items_raw = $wpdb->get_results($wpdb->prepare($full_sql, $params));
                }

                // Logika sprawdzania nagród w metadanych instytucji
                $badges_map = ['legend' => 500, 'diamond' => 100, 'platinum' => 50, 'gold' => 25, 'silver' => 10, 'bronze' => 5];
                $filtered_items = [];

                foreach ($items_raw as $inst) {
                    $meta = json_decode($inst->metadata, true);
                    $inst->city = $meta['address_struct']['city'] ?? $meta['address'] ?? '-';
                    $inst->nip  = $meta['nip'] ?? '-';
                    $inst->email = $meta['email'] ?? '-';
                    $inst->logo_url = $meta['logo_url'] ?? '';

                    $inst->has_pending_reward = false;
                    
                    // Czytamy odebrane nagrody ze struktury JSON instytucji
                    $claimed = $meta['rewards_claimed'] ?? [];
                    
                    foreach ($badges_map as $key => $threshold) {
                        if ((int)$inst->events_count >= $threshold && !in_array($key, $claimed)) {
                            $inst->has_pending_reward = true;
                            break;
                        }
                    }

                    if ($reward_status === 'pending') {
                        if ($inst->has_pending_reward) {
                            $filtered_items[] = $inst;
                        }
                    } else {
                        $filtered_items[] = $inst;
                    }
                }

                if ($reward_status === 'pending') {
                    $total_items = count($filtered_items);
                    if ($current_per_page !== -1) {
                        $filtered_items = array_slice($filtered_items, $offset, $current_per_page);
                    }
                }

                $data['institutions'] = $filtered_items;
                $data['total_items'] = $total_items;
                $data['bulk_actions'] = ['delete_institution_bulk' => 'Usuń zaznaczone'];
                
                $data['nonce_team'] = wp_create_nonce('devents_team_action_nonce');
                $data['nonce_org']  = wp_create_nonce('devents_org_action_nonce');
                $data['filters']['type'] = $filter_type;
                break;
        }
        
        // --- PAGINACJA WSPÓLNA ---
        if (isset($data['total_items'])) {
            $per_page_calc = isset($current_per_page) && $current_per_page > 0 ? $current_per_page : 20;
            
            $pagination_args = array_merge( $this->base_args, [ 'tab' => $type ] );

            if ($search) $pagination_args['search'] = $search;
            if ($type === 'users' && $current_role !== 'all') $pagination_args['role'] = $current_role;
            if (!empty($reward_status)) $pagination_args['reward_status'] = $reward_status;
            
            if ($type === 'events') {
                if ($current_status !== 'all') $pagination_args['status'] = $current_status;
                if ($orderby) $pagination_args['orderby'] = $orderby;
                if ($order)   $pagination_args['order'] = $order;
                if (!empty($_GET['date_from'])) $pagination_args['date_from'] = $_GET['date_from'];
                if (!empty($_GET['date_to']))   $pagination_args['date_to']   = $_GET['date_to'];
            }
            
            if ($type === 'institutions') {
                if (!empty($_GET['type'])) $pagination_args['type'] = $_GET['type'];
                if (!empty($orderby)) $pagination_args['orderby'] = $orderby;
            }

            if ($current_per_page !== 20) $pagination_args['per_page'] = $current_per_page;

            $base_url = add_query_arg($pagination_args, $this->base_url ?: home_url('/panel-uzytkownika/'));

            $data['pagination_links'] = paginate_links([
                'base' => $base_url . '&paged=%#%',
                'format' => '',
                'total' => ceil($data['total_items'] / $per_page_calc),
                'current' => $paged,
                'prev_text' => '«', 'next_text' => '»',
            ]);
        }

        // --- ZWRACANE DANE WIDOKU ---
        $data['panel_url'] = $this->panel_link(['tab' => $type]);
        $data['clean_url'] = $this->panel_link();
        $data['search_query'] = $search;
        $data['clear_filters_url'] = $this->panel_link(['tab' => $type]);
        
        $data['filters']['status'] = ($type === 'events') ? $current_status : $current_role;
        $data['filters']['role']   = $current_role;
        $data['filters']['social'] = isset($_GET['social']) ? sanitize_key($_GET['social']) : 'all';
        $data['filters']['search'] = $search;
        $data['filters']['per_page'] = $current_per_page;
        $data['filters']['reward_status'] = $reward_status;
        $data['filters']['date_from'] = $_GET['date_from'] ?? '';
        $data['filters']['date_to']   = $_GET['date_to'] ?? '';
        $data['filters']['category']  = $_GET['category'] ?? [];
        $data['filters']['accessibility'] = $_GET['accessibility'] ?? [];
        $data['filters']['orderby']   = $orderby;
        $data['filters']['order']     = $order;

        return $data;
    }

    private function get_status_info_for_item($status_id) {
        $status_map = [
            1 => ['text' => 'Zweryfikowany', 'class' => 'status-badge--published'],
            2 => ['text' => 'Wersja robocza', 'class' => 'status-badge--draft'],
            3 => ['text' => 'Do weryfikacji', 'class' => 'status-badge--updated'],
            0 => ['text' => 'Oczekuje', 'class' => 'status-badge--pending'],
        ];
        return $status_map[intval($status_id)] ?? $status_map[0];
    }

    /**
     * Status publikacji w mediach społecznościowych dla wiersza wydarzenia.
     * Zwraca: state, label, theme (jasny/ciemny/''), class, icon, date.
     */
    private function get_social_status_for_event($item) {
        $theme       = $item->social_publish_theme ?? '';
        $theme_label = $theme === 'light' ? 'jasny' : ($theme === 'dark' ? 'ciemny' : '');

        // 1) Opublikowane — rozróżniamy metodę.
        if (!empty($item->social_published) && (int) $item->social_published === 1) {
            $method = $item->social_publish_method ?? '';
            $date   = !empty($item->social_published_at) ? date('d.m.Y H:i', strtotime($item->social_published_at)) : '';

            if ($method === 'auto') {
                return ['state' => 'published', 'label' => 'Automatycznie', 'theme' => $theme_label, 'class' => 'social-badge--auto', 'icon' => 'cloud_done', 'date' => $date];
            }
            if ($method === 'manual') {
                return ['state' => 'published', 'label' => 'Ręcznie', 'theme' => $theme_label, 'class' => 'social-badge--manual', 'icon' => 'touch_app', 'date' => $date];
            }
            // social_published=1 bez metody → oznaczone starym przyciskiem.
            return ['state' => 'published', 'label' => 'Stara metoda', 'theme' => '', 'class' => 'social-badge--legacy', 'icon' => 'history', 'date' => $date];
        }

        // 2) Niepublikowane — czy zaplanowane przez CRON (verified=1, start w przyszłości)?
        $verified = (int) ($item->verified ?? 0);
        $start_ts = !empty($item->start_datetime) ? strtotime($item->start_datetime) : 0;
        $now      = current_time('timestamp');

        if ($verified === 1 && $start_ts > $now) {
            $window_open = $start_ts - 7 * DAY_IN_SECONDS; // publikacja na 7 dni przed startem
            $planned     = ($window_open <= $now) ? 'wkrótce' : date('d.m.Y', $window_open);
            return ['state' => 'scheduled', 'label' => 'Planowana publikacja', 'theme' => '', 'class' => 'social-badge--scheduled', 'icon' => 'schedule', 'date' => $planned];
        }

        // 3) Nie opublikowano (szkic / data minęła / brak konfiguracji).
        return ['state' => 'none', 'label' => 'Nie opublikowano', 'theme' => '', 'class' => 'social-badge--none', 'icon' => 'cloud_off', 'date' => ''];
    }
}