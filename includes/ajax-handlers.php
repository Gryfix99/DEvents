<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Inicjalizacja kontrolerów
 */
function devents_init_ajax_controllers() {
    new DEvents_Admin_Actions_Controller();
    new DEvents_Event_Controller();
    new DEvents_User_Controller();
    new DEvents_Stats_Controller();
    new DEvents_Contact_Controller();
    new DEvents_Institution_Controller();
}
add_action('init', 'devents_init_ajax_controllers');

// =============================================================================
// I. DEPENDENCJE I REJESTRACJA HOOKÓW
// =============================================================================

require_once DEW_PLUGIN_PATH . 'includes/handlers/auth-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/public-profiles.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/file-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/notifications-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/audit-log-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/stripe-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/graphic-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/marketing-graphics-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/social-poster-handler.php';
require_once DEW_PLUGIN_PATH . 'includes/handlers/event-graphics-handler.php';

// Ładowanie kontrolerów (Nowa Architektura)
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-event-controller.php';
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-user-controller.php';
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-institution-controller.php';
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-stats-controller.php';
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-contact-controller.php';
require_once DEW_PLUGIN_PATH . 'includes/controllers/class-devents-admin-actions-controller.php';

/**
 * Rejestruje hooki dla funkcji AJAX (Frontend / Publiczne / Panel Użytkownika)
 */
function devents_register_ajax_handlers() {
    // --- Akcje publiczne (dla niezalogowanych) ---
    add_action('wp_ajax_nopriv_devents_password_reset', 'devents_ajax_password_reset_callback');
    add_action('wp_ajax_nopriv_get_calendar_events', 'devents_ajax_get_calendar_events_callback');
    
    // Zgłaszanie nieprawidłowości
    add_action('wp_ajax_devents_report_item', 'devents_ajax_report_item_callback');

    // Statystyka czasu spędzonego na stronie wydarzenia (publiczne — także niezalogowani)
    add_action('wp_ajax_nopriv_devents_track_time', 'devents_ajax_track_time_callback');
    add_action('wp_ajax_devents_track_time', 'devents_ajax_track_time_callback');

    // --- Akcje dla zalogowanych użytkowników ---
    add_action('wp_ajax_devents_password_reset', 'devents_ajax_password_reset_callback');
    add_action('wp_ajax_get_calendar_events', 'devents_ajax_get_calendar_events_callback');
    add_action('wp_ajax_devents_toggle_favorite', 'devents_ajax_toggle_favorite_callback');

    // Panel Użytkownika - Powiadomienia
    add_action('wp_ajax_devents_fetch_notifications', 'devents_ajax_fetch_notifications_callback');
    add_action('wp_ajax_devents_mark_notifications_read', 'devents_ajax_mark_notifications_read_callback');

    // Pytania do organizatora (dawniej opinie) — dostępne też dla niezalogowanych (anonimowo)
    add_action('wp_ajax_devents_add_review', 'devents_ajax_add_review_callback');
    add_action('wp_ajax_nopriv_devents_add_review', 'devents_ajax_add_review_callback');
    add_action('wp_ajax_devents_delete_review', 'devents_ajax_delete_review_callback');
    add_action('wp_ajax_devents_toggle_subscription', 'devents_handle_toggle_subscription');

    // Zgłoś problem (tylko zalogowani) — formularz + załącznik
    add_action('wp_ajax_devents_submit_problem', 'devents_ajax_submit_problem_callback');

    // Płatność & Zamówienia
    // UWAGA: 'devents_create_payment' obsługuje WYŁĄCZNIE DEvents_Order_Handler::handle_general_payment
    // (order-handler.php) — wersja pełna z kuponami i zamówieniami za 0 zł. Wcześniej ta sama akcja
    // była podpięta tu pod devents_ajax_create_payment (wersja bez kuponów), co tworzyło konflikt
    // dwóch handlerów na jednym hooku. Rejestrację usunięto, by uniknąć niedeterministycznego działania.
    add_action('wp_ajax_devents_update_order_details', 'devents_ajax_update_order_details_callback');

    // Grafiki marketingowe (post/story × jasny/ciemny) — tylko zalogowani (organizator/admin)
    add_action('wp_ajax_devents_marketing_graphic_html', 'devents_ajax_marketing_graphic_html');
    add_action('wp_ajax_devents_marketing_upload',       'devents_ajax_marketing_upload');
    add_action('wp_ajax_devents_marketing_data',         'devents_ajax_marketing_data');
    add_action('wp_ajax_devents_marketing_pending',      'devents_ajax_marketing_pending');
}

// Inicjalizacja handlerów
add_action('init', 'devents_register_ajax_handlers');


/**
 * „Zgłoś problem" — zapis zgłoszenia użytkownika (z opcjonalnym załącznikiem ≤15 MB).
 */
function devents_ajax_submit_problem_callback() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => __( 'Musisz być zalogowany, aby zgłosić problem.', 'devents' ) ], 403 );
    }
    check_ajax_referer( 'devents_problem_nonce', 'security' );

    $user_id = get_current_user_id();
    $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
    $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

    if ( $message === '' ) {
        wp_send_json_error( [ 'message' => __( 'Opisz proszę, na czym polega problem.', 'devents' ) ] );
    }

    $attachment_url = '';
    if ( ! empty( $_FILES['attachment']['name'] ) ) {
        $allowed = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ];
        $ft      = wp_check_filetype( $_FILES['attachment']['name'] );
        $ext     = strtolower( $ft['ext'] ?? '' );
        if ( ! in_array( $ext, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Dozwolone pliki: obraz (JPG, PNG, GIF, WEBP) lub PDF.', 'devents' ) ] );
        }
        if ( (int) ( $_FILES['attachment']['size'] ?? 0 ) > 15 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => __( 'Plik jest za duży — maksymalnie 15 MB.', 'devents' ) ] );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload( $_FILES['attachment'], [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Błąd przesyłania pliku:', 'devents' ) . ' ' . $upload['error'] ] );
        }
        $attachment_url = $upload['url'] ?? '';
    }

    global $wpdb;
    $table = $wpdb->prefix . 'devents_problem_reports';
    $ok = $wpdb->insert( $table, [
        'user_id'        => $user_id,
        'subject'        => $subject,
        'message'        => $message,
        'attachment_url' => $attachment_url,
        'status'         => 'new',
        'created_at'     => current_time( 'mysql' ),
    ] );

    if ( $ok === false ) {
        wp_send_json_error( [ 'message' => __( 'Nie udało się zapisać zgłoszenia.', 'devents' ) . ' ' . $wpdb->last_error ] );
    }

    wp_send_json_success( [ 'message' => __( 'Dziękujemy! Twoje zgłoszenie zostało wysłane.', 'devents' ) ] );
}


// =============================================================================
// II. PUBLICZNE HANDLERY AJAX (Reset Hasła, Kalendarz, Zgłoszenia)
// =============================================================================

/**
 * Zlicza czas spędzony przez odwiedzającego na stronie wydarzenia.
 * Wywoływane przez navigator.sendBeacon (time-tracker.js) przy ukryciu/zamknięciu karty.
 * Akumuluje sekundy i liczbę próbek (średni czas = total_time_spent / time_visits).
 */
function devents_ajax_track_time_callback() {
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $seconds  = isset($_POST['seconds'])  ? intval($_POST['seconds'])  : 0;
    $nonce    = isset($_POST['nonce'])    ? sanitize_text_field($_POST['nonce']) : '';

    // Lekka ochrona przed zaśmiecaniem (nonce sesyjny strony) + walidacja zakresu.
    if ( ! wp_verify_nonce($nonce, 'devents_track_time') ) {
        wp_send_json_error(['message' => 'bad nonce'], 403);
    }
    if ($event_id < 1 || $seconds < 1) {
        wp_send_json_error(['message' => 'bad params'], 400);
    }
    if ($seconds > 3600) {
        $seconds = 3600; // pojedyncza próbka nie zawyża statystyki
    }

    global $wpdb;
    $table = $wpdb->prefix . 'events_list';
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET total_time_spent = total_time_spent + %d, time_visits = time_visits + 1 WHERE id = %d",
        $seconds, $event_id
    ));

    wp_send_json_success();
}

function devents_ajax_password_reset_callback() {
    check_ajax_referer('devents_reset_nonce', 'nonce');
    $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
    $user = get_user_by('email', $email);

    switch ($step) {
        case 'send_code':
            if ($user) {
                devents_add_audit_log('password_reset_request', 'user', $user->ID);
                $reset_code = str_pad(wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                update_user_meta($user->ID, '_password_reset_code', password_hash($reset_code, PASSWORD_DEFAULT));
                update_user_meta($user->ID, '_password_reset_expiry', time() + 15 * MINUTE_IN_SECONDS);
                devents_send_email($email, 'password_reset_code', ['reset_code' => $reset_code]);
            }
            // Zawsze zwracaj sukces, aby nie ujawniać, czy dany e-mail istnieje w bazie
            wp_send_json_success(['message' => 'Jeśli konto o podanym adresie e-mail istnieje, wysłaliśmy na nie kod do resetu hasła.']);
            break;

        case 'validate_code':
            $is_valid = $user && get_user_meta($user->ID, '_password_reset_expiry', true) > time() && password_verify($code, get_user_meta($user->ID, '_password_reset_code', true));
            if (!$is_valid) {
                wp_send_json_error(['message' => 'Nieprawidłowy lub wygasły kod weryfikacyjny. Spróbuj ponownie.']);
            }
            wp_send_json_success(['message' => 'Kod poprawny.']);
            break;

        case 'set_password':
            $pass1 = isset($_POST['pass1']) ? $_POST['pass1'] : '';
            
            if (empty($pass1)) {
                wp_send_json_error(['message' => 'Hasło nie może być puste.']);
            }
            if (strlen($pass1) < 8 || !preg_match('/[A-Z]/', $pass1) || !preg_match('/[a-z]/', $pass1) || !preg_match('/\d/', $pass1)) {
                wp_send_json_error(['message' => 'Hasło nie spełnia wymagań bezpieczeństwa.']);
            }

            $is_valid = $user && password_verify($code, get_user_meta($user->ID, '_password_reset_code', true));
            if (!$is_valid) {
                wp_send_json_error(['message' => 'Błąd weryfikacji. Spróbuj od nowa.']);
            }

            reset_password($user, $pass1);

            devents_add_audit_log('password_reset_success', 'user', $user->ID);

            delete_user_meta($user->ID, '_password_reset_code');
            delete_user_meta($user->ID, '_password_reset_expiry');
            
            wp_send_json_success(['message' => 'Hasło zostało pomyślnie zmienione.']);
            break;

        default:
            wp_send_json_error(['message' => 'Nieznana akcja.']);
    }
}

function devents_ajax_get_calendar_events_callback() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'events_list';
    $start_date = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : null;
    $end_date = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : null;
    if (!$start_date || !$end_date) wp_send_json_error('Brak zakresu dat.');
    
    $events_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, start_datetime, end_datetime FROM {$table_name} WHERE verified = 1 AND start_datetime BETWEEN %s AND %s",
        $start_date, $end_date
    ));
    
    $events_for_calendar = [];
    foreach ($events_raw as $event) {
        $events_for_calendar[] = [
            'title' => $event->title,
            'start' => $event->start_datetime,
            'end' => $event->end_datetime,
            'url' => home_url('/wydarzenia/' . sanitize_title($event->title) . '-' . $event->id),
        ];
    }
    wp_send_json($events_for_calendar);
}

function devents_ajax_report_item_callback() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musisz być zalogowany, aby zgłosić treść.']);
    }

    check_ajax_referer('devents_report_nonce', 'security');

    $user_id = get_current_user_id();
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $item_type = isset($_POST['item_type']) ? sanitize_key($_POST['item_type']) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

    if (empty($item_id) || empty($item_type) || empty($reason)) {
        wp_send_json_error(['message' => 'Nieprawidłowe dane formularza. Upewnij się, że wybrałeś powód zgłoszenia.']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'devents_reports';

    $existing_report = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE reporter_user_id = %d AND item_id = %d AND item_type = %s",
        $user_id, $item_id, $item_type
    ));

    if ($existing_report) {
        wp_send_json_error(['message' => 'Już zgłosiłeś/aś ten element.']);
    }

    $wpdb->insert(
        $table_name,
        [
            'reporter_user_id' => $user_id,
            'item_id' => $item_id,
            'item_type' => $item_type,
            'reason' => $reason,
            'status' => 'new',
        ]
    );
    devents_add_audit_log('content_reported', $item_type, $item_id, ['reason' => $reason]);

    wp_send_json_success(['message' => 'Dziękujemy za zgłoszenie! Administratorzy wkrótce się nim zajmą.']);
}


// =============================================================================
// III. HANDLERY PANELU UŻYTKOWNIKA (ZALOGOWANY)
// =============================================================================

function devents_ajax_fetch_notifications_callback() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Brak uprawnień.', 403 );

    global $wpdb;
    $table_name = $wpdb->prefix . 'events_notifications';
    $user_id = get_current_user_id();

    $notifications = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE user_id = %d ORDER BY created_at DESC LIMIT 15",
        $user_id
    ) );

    wp_send_json_success( $notifications );
}

function devents_ajax_mark_notifications_read_callback() {
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Brak uprawnień.', 403 );

    global $wpdb;
    $table_name = $wpdb->prefix . 'events_notifications';
    $user_id = get_current_user_id();

    $wpdb->update(
        $table_name,
        [ 'is_read' => 1 ],
        [ 'user_id' => $user_id, 'is_read' => 0 ],
        [ '%d' ],
        [ '%d', '%d' ]
    );

    wp_send_json_success( 'Oznaczono jako przeczytane.' );
}

function devents_ajax_toggle_favorite_callback() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'Musisz być zalogowany.']);
    }
    
    check_ajax_referer('devents_favorites_nonce', 'security');

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $user_id = get_current_user_id();

    if ( empty($event_id) ) {
        wp_send_json_error(['message' => 'Brak ID wydarzenia.']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'devents_user_favorites';

    $is_favorite = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE user_id = %d AND event_id = %d",
        $user_id, $event_id
    ));

    if ($is_favorite) {
        $wpdb->delete($table_name, ['id' => $is_favorite]);
        devents_add_audit_log('favorite_removed', 'event', $event_id);
        wp_send_json_success(['status' => 'removed', 'message' => 'Usunięto z ulubionych.']);
    } else {
        $wpdb->insert($table_name, ['user_id' => $user_id, 'event_id' => $event_id]);
        devents_add_audit_log('favorite_added', 'event', $event_id);
        wp_send_json_success(['status' => 'added', 'message' => 'Dodano do ulubionych.']);
    }
}

function devents_ajax_add_review_callback() {
    // System Pytań: dostępny także dla niezalogowanych (pytanie anonimowe).
    check_ajax_referer('devents_review_nonce', 'security');

    $logged_in = is_user_logged_in();
    $user_id   = $logged_in ? get_current_user_id() : 0;
    $item_id   = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $item_type = isset($_POST['item_type']) ? sanitize_key($_POST['item_type']) : '';
    $comment   = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : '';
    $parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;

    // Niezalogowany pyta zawsze anonimowo; zalogowany może zaznaczyć „jako anonim".
    $is_anonymous = (!$logged_in || !empty($_POST['is_anonymous'])) ? 1 : 0;

    // Odpowiedzi (parent_id>0) mogą dodawać tylko zalogowani (np. organizator).
    if ($parent_id > 0 && !$logged_in) {
        wp_send_json_error(['message' => 'Musisz być zalogowany, aby odpowiedzieć.']);
    }
    if (!$item_id || !$item_type) {
        wp_send_json_error(['message' => 'Brak wymaganych danych.']);
    }

    if (mb_strlen($comment) > 500) {
        $comment = mb_substr($comment, 0, 500);
    }
    if (empty($comment)) {
        wp_send_json_error(['message' => $parent_id > 0 ? 'Odpowiedź nie może być pusta.' : 'Wpisz treść pytania.']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'devents_reviews';

    $wpdb->insert(
        $table_name,
        [
            'user_id'      => $user_id,
            'item_id'      => $item_id,
            'item_type'    => $item_type,
            'rating'       => 0,
            'is_anonymous' => $is_anonymous,
            'comment'      => $comment,
            'is_approved'  => 1,
            'parent_id'    => $parent_id,
        ]
    );
    $new_review_id = $wpdb->insert_id;

    // Powiadomienie dla organizatora o nowym pytaniu (nie dla odpowiedzi).
    if ($parent_id == 0) {
        $notify_user = ($item_type === 'organizer')
            ? $item_id
            : (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}events_list WHERE id = %d", $item_id));
        if ($notify_user && function_exists('devents_add_notification')) {
            $notification_link = ($item_type === 'organizer') ? home_url('/?organizer_id=' . $item_id) : '';
            devents_add_notification($notify_user, 'Masz nowe pytanie do wydarzenia/profilu.', 'info', $notification_link);
        }
    }

    $log_action = ($parent_id > 0) ? 'review_replied' : 'question_added';
    if (function_exists('devents_add_audit_log')) {
        devents_add_audit_log($log_action, $item_type, $item_id);
    }

    $author_name = $is_anonymous
        ? __('Anonim', 'devents')
        : ( ($u = get_userdata($user_id)) ? $u->display_name : __('Użytkownik', 'devents') );

    $new_review = [
        'id' => $new_review_id,
        'author' => $author_name,
        'comment' => wpautop($comment),
        'created_at' => current_time('d.m.Y'),
    ];

    $thanks = $parent_id > 0 ? __('Odpowiedź dodana.', 'devents') : __('Dziękujemy! Pytanie zostało wysłane.', 'devents');
    wp_send_json_success(['message' => $thanks, 'review' => $new_review, 'is_reply' => $parent_id > 0]);
}

function devents_ajax_delete_review_callback() {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Brak uprawnień.']);
    // Szablon (reviews.twig) wystawia jeden nonce 'review_nonce' = devents_review_nonce
    // zarówno dla dodawania, jak i usuwania opinii. Wcześniej tu weryfikowano
    // 'devents_delete_review_nonce', który nigdzie nie był tworzony → usuwanie zawsze 403.
    check_ajax_referer('devents_review_nonce', 'security');

    $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
    if (empty($review_id)) wp_send_json_error(['message' => 'Brak ID opinii.']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'devents_reviews';
    $review = $wpdb->get_row($wpdb->prepare("SELECT user_id, item_type, item_id FROM {$table_name} WHERE id = %d", $review_id));

    if (!$review) wp_send_json_error(['message' => 'Opinia nie istnieje.']);

    if ($review->user_id == get_current_user_id() || current_user_can('manage_options')) {
        devents_add_audit_log('review_deleted', $review->item_type, $review->item_id);

        $wpdb->delete($table_name, ['id' => $review_id]);
        $wpdb->delete($table_name, ['parent_id' => $review_id]);
        wp_send_json_success(['message' => 'Opinia została usunięta.']);
    } else {
        wp_send_json_error(['message' => 'Nie masz uprawnień do usunięcia tej opinii.']);
    }
}

function devents_handle_toggle_subscription() {
    check_ajax_referer( 'devents_subscription_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( ['message' => 'Musisz być zalogowany.'] );
    }

    $organizer_id = isset( $_POST['organizer_id'] ) ? intval( $_POST['organizer_id'] ) : 0;
    $user_id = get_current_user_id();

    if ( ! $organizer_id ) {
        wp_send_json_error( ['message' => 'Błędne ID organizatora.'] );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'devents_subscriptions';

    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE subscriber_user_id = %d AND organizer_user_id = %d",
        $user_id, $organizer_id
    ));

    if ( $exists ) {
        $wpdb->delete( $table_name, [
            'subscriber_user_id' => $user_id,
            'organizer_user_id'  => $organizer_id
        ]);
        $action = 'removed';
    } else {
        $wpdb->insert( $table_name, [
            'subscriber_user_id' => $user_id,
            'organizer_user_id'  => $organizer_id,
            'created_at'         => current_time( 'mysql' )
        ]);
        $action = 'added';
    }

    $new_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE organizer_user_id = %d",
        $organizer_id
    ));

    wp_send_json_success( [
        'action' => $action,
        'new_count' => $new_count
    ]);
}

function devents_ajax_create_payment() {
    check_ajax_referer('devents_payment_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musisz być zalogowany.']);
    }

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $product_type = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : '';
    $existing_order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;

    if (!$event_id || !$product_type) {
        wp_send_json_error(['message' => 'Brak wymaganych danych płatności.']);
    }

    $stripe = new DEvents_Stripe_Handler();
    $checkout_url = $stripe->create_checkout_session($event_id, $product_type, $existing_order_id);

    if (is_wp_error($checkout_url)) {
        wp_send_json_error(['message' => $checkout_url->get_error_message()]);
    }

    wp_send_json_success(['redirect_url' => $checkout_url]);
}

function devents_ajax_update_order_details_callback() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musisz być zalogowany.']);
    }

    check_ajax_referer('devents_payment_nonce', 'nonce');

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $user_id = get_current_user_id();

    if (!$order_id) wp_send_json_error(['message' => 'Brak ID zamówienia.']);

    global $wpdb;
    $table_orders = $wpdb->prefix . 'devents_orders';

    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_id));

    if (!$order) wp_send_json_error(['message' => 'Zamówienie nie istnieje.']);

    if ($order->user_id != $user_id && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Brak uprawnień.']);
    }

    if ($order->status !== 'paid' && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nie można edytować zamówienia w statusie: ' . $order->status]);
    }

    $pjm_details = json_decode($order->pjm_details, true);
    if (!is_array($pjm_details)) $pjm_details = [];

    $pjm_details['text'] = sanitize_textarea_field($_POST['pjm_text'] ?? '');
    $pjm_details['bg_color'] = sanitize_hex_color($_POST['pjm_bg_color'] ?? '#ffffff');
    $pjm_details['add_subtitles'] = isset($_POST['pjm_add_subtitles']) ? true : false;
    
    if (!isset($pjm_details['files']) || !is_array($pjm_details['files'])) {
        $pjm_details['files'] = [];
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');

    if (isset($_FILES['pjm_logo']) && $_FILES['pjm_logo']['error'] === UPLOAD_ERR_OK) {
        $upload = wp_handle_upload($_FILES['pjm_logo'], ['test_form' => false]);
        if (!isset($upload['error'])) {
            if (function_exists('devents_optimize_uploaded_image')) {
                $upload = devents_optimize_uploaded_image($upload, 'pjm-logo', 800, 85);
            }
            $pjm_details['files']['pjm_logo'] = $upload['url'];
        }
    }

    if (isset($_FILES['pjm_graphic']) && $_FILES['pjm_graphic']['error'] === UPLOAD_ERR_OK) {
        $upload = wp_handle_upload($_FILES['pjm_graphic'], ['test_form' => false]);
        if (!isset($upload['error'])) {
            if (function_exists('devents_optimize_uploaded_image')) {
                $upload = devents_optimize_uploaded_image($upload, 'pjm-grafika', 1600, 85);
            }
            $pjm_details['files']['pjm_graphic'] = $upload['url'];
        }
    }

    $buyer = $_POST['buyer'] ?? [];
    $recipient = $_POST['recipient'] ?? [];

    $sanitize_address_block = function($data) {
        return [
            'name'      => sanitize_text_field($data['name'] ?? ''),
            'nip'       => sanitize_text_field($data['nip'] ?? ''),
            'street'    => sanitize_text_field($data['street'] ?? ''),
            'house_no'  => sanitize_text_field($data['house_no'] ?? ''),
            'flat_no'   => sanitize_text_field($data['flat_no'] ?? ''),
            'postcode'  => sanitize_text_field($data['postcode'] ?? ''),
            'city'      => sanitize_text_field($data['city'] ?? '')
        ];
    };

    $clean_buyer = $sanitize_address_block($buyer);
    $clean_recipient = $sanitize_address_block($recipient);

    $billing_details = [
        'type'      => 'buyer_recipient',
        'buyer'     => $clean_buyer,
        'recipient' => $clean_recipient,
        'name'      => $clean_buyer['name'],
        'nip'       => $clean_buyer['nip'],
        'address'   => trim($clean_buyer['street'] . ' ' . $clean_buyer['house_no'] . ' ' . $clean_buyer['city'])
    ];

    $updated = $wpdb->update(
        $table_orders,
        [
            'pjm_details' => json_encode($pjm_details, JSON_UNESCAPED_UNICODE),
            'billing_details' => json_encode($billing_details, JSON_UNESCAPED_UNICODE)
        ],
        ['id' => $order_id]
    );

    if ($updated !== false) {
        devents_add_audit_log('order_updated', 'order', $order_id, ['user_id' => $user_id]);
        wp_send_json_success(['message' => 'Dane zamówienia zostały zaktualizowane.']);
    } else {
        wp_send_json_error(['message' => 'Błąd bazy danych podczas zapisu.']);
    }
}