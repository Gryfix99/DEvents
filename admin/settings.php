<?php
/**
 * Plik: settings.php
 * Kompleksowe centrum zarządzania DEvents: Ustawienia, E-maile oraz Narzędzia Naprawcze.
 * Wersja: 8.5 (Aktualizacja SQL, Migracja Slugów, i18n)
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. Rejestracja ustawień WordPress (Stripe, Konserwacja, Email)
 */
function devents_register_settings() {
    register_setting('devents_options_group', 'devents_stripe_secret_key');
    register_setting('devents_options_group', 'devents_stripe_webhook_secret');
    register_setting('devents_options_group', 'devents_maintenance_mode');
    register_setting('devents_options_group', 'devents_email_header_img');
    register_setting('devents_options_group', 'devents_email_footer_text');
}
add_action('admin_init', 'devents_register_settings');

/**
 * 2. Procesor formularzy (Akcje AJAX i POST)
 */
function devents_handle_settings_actions() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table_settings = $wpdb->prefix . 'events_settings';

    // --- AKCJA: Dodawanie Kategorii/Opcji ---
    if (isset($_POST['devents_add_setting']) && check_admin_referer('devents_add_setting_nonce')) {
        $type = sanitize_key($_POST['setting_type'] ?? '');
        $value = sanitize_text_field($_POST['setting_value'] ?? '');
        if (!empty($type) && !empty($value)) {
            $wpdb->insert($table_settings, ['setting_type' => $type, 'setting_value' => $value]);
            wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=' . (strpos($type, 'video') === 0 ? 'video' : 'events') . '&message=1'));
            exit;
        }
    }

    // --- AKCJA: Usuwanie Kategorii/Opcji ---
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['setting_id'])) {
        if (check_admin_referer('devents_delete_setting_' . $_GET['setting_id'])) {
            $wpdb->delete($table_settings, ['id' => intval($_GET['setting_id'])]);
            wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=' . sanitize_key($_GET['tab'] ?? 'events') . '&message=2'));
            exit;
        }
    }

    // --- AKCJA: E-maile ---
    if (isset($_POST['devents_save_email_templates']) && check_admin_referer('devents_email_templates_nonce')) {
        update_option('devents_email_header_img', esc_url_raw($_POST['devents_email_header_img'] ?? ''));
        update_option('devents_email_footer_text', wp_kses_post($_POST['devents_email_footer_text'] ?? ''));
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=emails&message=1'));
        exit;
    }

    // --- AKCJA: Migracja na slugi (Etap V) ---
    if (isset($_POST['run_slug_migration']) && check_admin_referer('devents_tools_action')) {
        $stats = devents_tool_migrate_categories_to_slugs();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=9&fixed=' . $stats));
        exit;
    }

    // --- AKCJA: Aktualizacja struktury SQL (Etap 0) ---
    if (isset($_POST['run_db_update']) && check_admin_referer('devents_tools_action')) {
        $added = devents_tool_update_db_schema();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=8&added='.$added));
        exit;
    }

    // --- AKCJA: Pełna Synchronizacja ---
    if (isset($_POST['run_total_sync']) && check_admin_referer('devents_tools_action')) {
        $stats = devents_tool_total_sync_cleanup();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=4&deleted='.$stats['deleted'].'&fixed='.$stats['fixed']));
        exit;
    }

    // --- AKCJA: Migracja Nagród ---
    if (isset($_POST['run_reward_migration']) && check_admin_referer('devents_tools_action')) {
        $stats = devents_tool_migrate_rewards_and_cleanup();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=5&migrated='.$stats['migrated'].'&cleaned='.$stats['cleaned']));
        exit;
    }

    // --- AKCJA: Migracja biletów ---
    if (isset($_POST['run_migration_tickets']) && check_admin_referer('devents_tools_action')) {
        $count = devents_tool_migrate_tickets();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=1&count='.$count));
        exit;
    }

    // --- AKCJA: Naprawa Instytucji ---
    if (isset($_POST['run_fix_institutions']) && check_admin_referer('devents_tools_action')) {
        $count = devents_tool_fix_institutions();
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=1&count='.$count));
        exit;
    }

    // --- AKCJA: Czyszczenie Debug.log ---
    if (isset($_POST['devents_clear_debug_log']) && check_admin_referer('devents_tools_action')) {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
        }
        wp_safe_redirect(admin_url('admin.php?page=admin-settings&tab=tools&message=6'));
        exit;
    }
}
add_action('admin_init', 'devents_handle_settings_actions');

/**
 * Logika: Migracja nazw kategorii i metod na techniczne SLUGI (Etap V)
 */
function devents_tool_migrate_categories_to_slugs() {
    global $wpdb;
    $table_events = $wpdb->prefix . 'events_list';
    $total_fixed = 0;

    // 1. Mapa Kategorii: 'slug' => 'nazwa polska'
    $category_map = [
        'film'          => 'Film',
        'concert'       => 'Koncert',
        'conference'    => 'Konferencja',
        'performance'   => 'Performens',
        'walk'          => 'Spacer',
        'spectacle'     => 'Spektakl',
        'meeting'       => 'Spotkanie',
        'workshop'      => 'Warsztaty',
        'lecture'       => 'Wykład',
        'exhibition'    => 'Wystawa',
        'sightseeing'   => 'Zwiedzanie',
        'sport'         => 'Sport',
        'opening'       => 'Wernisaż/Finisaż',
        'festival'      => 'Festiwal',
        'family'        => 'Rodzinne',
        'fair'          => 'Targi',
        'course'        => 'Kurs',
        'other'         => 'Inne'
    ];

    // 2. Mapa Metod: 'slug' => 'nazwa polska'
    $method_map = [
        'live'   => 'Na żywo',
        'hybrid' => 'Hybrydowo',
        'online' => 'Online'
    ];

    // Wykonaj aktualizację dla kategorii
    foreach ($category_map as $slug => $label) {
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table_events}` SET `category` = %s WHERE `category` = %s",
            $slug, $label
        ));
        if ($result !== false) $total_fixed += $result;
    }

    // Wykonaj aktualizację dla metod realizacji
    foreach ($method_map as $slug => $label) {
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table_events}` SET `delivery_mode` = %s WHERE `delivery_mode` = %s",
            $slug, $label
        ));
        if ($result !== false) $total_fixed += $result;
    }

    return $total_fixed;
}

/**
 * Logika: AKTUALIZACJA BAZY (SQL) - Etap 0
 */
function devents_tool_update_db_schema() {
    global $wpdb;
    $table_events = $wpdb->prefix . 'events_list';
    $added = 0;

    $col_age = $wpdb->get_results("SHOW COLUMNS FROM `{$table_events}` LIKE 'age_rating'");
    if (empty($col_age)) {
        $wpdb->query("ALTER TABLE `{$table_events}` ADD `age_rating` VARCHAR(10) DEFAULT '0+' AFTER `category`");
        $added++;
    }

    $col_inst = $wpdb->get_results("SHOW COLUMNS FROM `{$table_events}` LIKE 'institution_id'");
    if (empty($col_inst)) {
        $wpdb->query("ALTER TABLE `{$table_events}` ADD `institution_id` INT(11) DEFAULT 0 AFTER `user_id`");
        $added++;
    }

    return $added;
}

/**
 * Logika: TOTALNA SYNCHRONIZACJA
 */
function devents_tool_total_sync_cleanup() {
    global $wpdb;
    $table_events = $wpdb->prefix . 'events_list';
    
    $deleted_orphans = 0;
    $fixed_or_updated = 0;

    $all_event_posts = get_posts([
        'post_type' => 'wydarzenia',
        'posts_per_page' => -1,
        'post_status' => 'any'
    ]);

    foreach ($all_event_posts as $post) {
        $event_id = get_post_meta($post->ID, '_event_id', true);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_events} WHERE id = %d", $event_id));
        if (!$exists) {
            wp_delete_post($post->ID, true);
            $deleted_orphans++;
        }
    }

    $events = $wpdb->get_results("SELECT * FROM {$table_events}");
    foreach ($events as $event) {
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_event_id' AND meta_value = %d", $event->id));

        $clean_title = preg_replace('/[^\p{L}\p{N}\s]/u', '', $event->title);
        $custom_slug = sanitize_title($clean_title) . '-' . $event->id;
        
        $post_data = [
            'post_title'   => trim($clean_title),
            'post_content' => '[wydarzenie id="' . $event->id . '"]',
            'post_name'    => $custom_slug,
            'post_status'  => ($event->verified == 2) ? 'draft' : 'publish',
            'post_type'    => 'wydarzenia',
            'post_author'  => $event->user_id,
            'post_date'    => $event->created_at
        ];

        if ($post_id) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
            update_post_meta($post_id, '_event_id', $event->id);
        }
        $fixed_or_updated++;
    }
    return ['deleted' => $deleted_orphans, 'fixed' => $fixed_or_updated];
}

/**
 * Logika: MIGRACJA NAGRÓD I CZYSZCZENIE USERMETA
 */
function devents_tool_migrate_rewards_and_cleanup() {
    global $wpdb;
    $inst_table = $wpdb->prefix . 'devents_institutions';
    $usermeta_table = $wpdb->prefix . 'usermeta';

    $migrated_count = 0;

    $keys_to_delete = [
        '_devents_reward_codes_bronze', '_devents_reward_codes_silver', 
        '_devents_reward_codes_platinum', '_devents_reward_codes_diamond', 
        '_devents_reward_codes_gold', 'description', 'devents_org_unique_code', 
        'website', 'local', 'org_name', 'org_nip', 'org_website', 'nip', 'org_address'
    ];

    $users_with_rewards = $wpdb->get_results("SELECT user_id, meta_value FROM $usermeta_table WHERE meta_key = '_devents_rewards_claimed'");

    foreach ($users_with_rewards as $row) {
        $user_id = $row->user_id;
        $rewards_data = maybe_unserialize($row->meta_value);
        $institution_id = get_user_meta($user_id, 'devents_institution_id', true);

        if ($institution_id) {
            $inst_row = $wpdb->get_row($wpdb->prepare("SELECT metadata FROM $inst_table WHERE id = %d", $institution_id));
            if ($inst_row) {
                $current_meta = json_decode($inst_row->metadata, true) ?: [];
                $current_meta['rewards_claimed'] = $rewards_data;
                
                $wpdb->update(
                    $inst_table,
                    ['metadata' => json_encode($current_meta, JSON_UNESCAPED_UNICODE)],
                    ['id' => $institution_id]
                );
                $migrated_count++;
            }
        }
    }

    $key_placeholders = implode(',', array_fill(0, count($keys_to_delete), '%s'));
    $wpdb->query($wpdb->prepare("DELETE FROM $usermeta_table WHERE meta_key IN ($key_placeholders)", $keys_to_delete));

    return ['migrated' => $migrated_count, 'cleaned' => count($keys_to_delete)];
}

function devents_tool_migrate_tickets() {
    global $wpdb;
    $table = $wpdb->prefix . 'events_list';
    $events = $wpdb->get_results("SELECT id, price FROM $table WHERE (ticket_types IS NULL OR ticket_types = '')");
    $count = 0;
    foreach ($events as $e) {
        $structure = [['name' => ($e->price > 0 ? 'Bilet wstępu' : 'Wstęp wolny'), 'price' => (float)$e->price]];
        $wpdb->update($table, ['ticket_types' => json_encode($structure, JSON_UNESCAPED_UNICODE)], ['id' => $e->id]);
        $count++;
    }
    return $count;
}

function devents_tool_fix_institutions() {
    global $wpdb;
    $table = $wpdb->prefix . 'events_list';
    $events = $wpdb->get_results("SELECT id, user_id FROM $table WHERE institution_id = 0");
    $count = 0;
    foreach ($events as $e) {
        $inst_id = get_user_meta($e->user_id, 'devents_institution_id', true);
        if ($inst_id) {
            $wpdb->update($table, ['institution_id' => intval($inst_id)], ['id' => $e->id]);
            $count++;
        }
    }
    return $count;
}

/**
 * 6. GŁÓWNY RENDER STRONY (Twig)
 */
function devents_settings_page_callback() {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');

    global $wpdb;
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'events';

    $context = [
        'page_url'   => admin_url('admin.php?page=admin-settings'),
        'active_tab' => $active_tab,
        'messages'   => [],
        'stats'      => [
            'db_count'   => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}events_list"),
            'post_count' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wydarzenia'"),
        ],
        'stripe' => [
            'secret_key'     => get_option('devents_stripe_secret_key'),
            'webhook_secret' => get_option('devents_stripe_webhook_secret'),
            'webhook_url'    => home_url('/wp-json/devents/v1/stripe-webhook')
        ],
        'maintenance_mode' => get_option('devents_maintenance_mode'),
    ];

    // Obsługa komunikatów (W tym nowy message 9)
    if (isset($_GET['message'])) {
        switch($_GET['message']) {
            case 1: $context['messages'][] = ['type' => 'success', 'text' => 'Zaktualizowano dane.']; break;
            case 2: $context['messages'][] = ['type' => 'success', 'text' => 'Pomyślnie usunięto pozycję.']; break;
            case 4: $context['messages'][] = ['type' => 'success', 'text' => "Synchronizacja zakończona. Usunięto ".intval($_GET['deleted'])." postów. Naprawiono ".intval($_GET['fixed'])." wpisów."]; break;
            case 5: $context['messages'][] = ['type' => 'success', 'text' => "Migracja nagród pomyślna. Przeniesiono dane dla ".intval($_GET['migrated'])." instytucji."]; break;
            case 6: $context['messages'][] = ['type' => 'success', 'text' => 'Plik debug.log został pomyślnie wyczyszczony.']; break;
            case 8: $context['messages'][] = ['type' => 'success', 'text' => "Aktualizacja SQL zakończona! Dodano ".intval($_GET['added'])." kolumn."]; break;
            case 9: $context['messages'][] = ['type' => 'success', 'text' => "Migracja slugów zakończona. Zaktualizowano ".intval($_GET['fixed'])." rekordów."]; break;
        }
    }

    // Grupowanie ustawień
    $all_settings = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}events_settings ORDER BY setting_value ASC");
    $context['event_settings'] = [];
    $context['video_settings'] = [];

    foreach ($all_settings as $s) {
        $type = (strpos($s->setting_type, 'video_') === 0) ? 'video_settings' : 'event_settings';
        $context[$type][$s->setting_type][] = $s;
    }

    // Czytanie pliku debug.log
    $log_file = WP_CONTENT_DIR . '/debug.log';
    $debug_log_content = 'Brak pliku debug.log.';
    if (file_exists($log_file)) {
        $lines = file($log_file);
        if (is_array($lines)) $debug_log_content = implode("", array_slice($lines, -200));
    }
    $context['debug_log_content'] = esc_textarea($debug_log_content);

    // Edytor WordPress i Nonce
    ob_start();
    wp_editor(get_option('devents_email_footer_text'), 'devents_email_footer_text', ['textarea_rows' => 5, 'teeny' => true]);
    $context['footer_editor_html'] = ob_get_clean();
    
    $context['email_header_img'] = get_option('devents_email_header_img');
    $context['tools_nonce'] = wp_create_nonce('devents_tools_action');
    $context['email_nonce_field'] = wp_nonce_field('devents_email_templates_nonce', '_wpnonce', true, false);
    
    ob_start();
    settings_fields('devents_options_group');
    $context['settings_fields_html'] = ob_get_clean();

    echo DEvents_Twig_Helper::get_instance()->render('admin/settings-page', $context);
}