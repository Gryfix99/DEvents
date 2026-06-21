<?php
if (!defined('ABSPATH')) exit;

class DEvents_Email_Manager {

    /**
     * Główna funkcja wysyłająca e-maile
     */
    public function send_email($to, $subject, $template_key, $data = [], $attachments = [], $is_system_message = false) {

        // 0. Walidacja odbiorcy — chroni przed pustym/nieprawidłowym adresem
        //    (np. z kolejki maili) i wstrzyknięciem nagłówków przez subject.
        if (!is_email($to)) {
            return false;
        }

        // 1. Ładowanie definicji szablonów
        $templates_path = DEW_PLUGIN_PATH . 'emails/email-templates.php';
        $templates = file_exists($templates_path) ? include($templates_path) : [];
        
        $template_config = $templates[$template_key] ?? [];

        // Pobieranie treści (Priorytet: Baza Danych -> Plik config -> Argument funkcji -> Pusty string)
        $default_subject = $template_config['subject'] ?? ($subject ?? 'Powiadomienie ze strony ' . get_bloginfo('name'));
        
        // UWAGA: Szablony w email-templates.php są już wygenerowanym HTML-em (z funkcji get_email_html_wrapper),
        // dlatego traktujemy je jako gotowy szablon, w którym tylko podmieniamy tagi {{zmienne}}.
        $default_body    = $template_config['body'] ?? ($data['content'] ?? '');

        // Pobierz ustawienia z opcji WP (jeśli istnieją nadpisania w panelu admina)
        $final_subject = get_option("devents_email_{$template_key}_subject", $default_subject);
        $final_body    = get_option("devents_email_{$template_key}_body", $default_body);

        // 2. Przygotowanie danych do podmiany
        $default_data = [
            'site_name'   => get_bloginfo('name'),
            'site_url'    => home_url(),
            'admin_email' => get_option('admin_email'),
            'date'        => date_i18n(get_option('date_format')), 
            'time'        => date_i18n(get_option('time_format'))  
        ];
        
        // Zabezpieczenie typu danych
        if (!is_array($data)) {
            $data = ['content' => (string)$data];
        }
        
        $data = array_merge($default_data, $data);

        // 3. Podstawianie zmiennych (Obsługa {{key}}, {{ key }}, {key})
        foreach ($data as $key => $value) {
            $val_str = is_array($value) ? implode(', ', $value) : (string)$value;
            
            $search = [
                '{{' . $key . '}}',
                '{{ ' . $key . ' }}',
                '{' . $key . '}'
            ];
            
            $final_subject = str_replace($search, $val_str, $final_subject);
            $final_body    = str_replace($search, $val_str, $final_body);
        }

        // 4. USUNIĘTO TWARDY WRAPPER HTML!
        // Szablony w email-templates.php korzystają już z get_email_html_wrapper(), który buduje cały plik HTML.
        // Jeśli dodalibyśmy tu drugi wrapper, style gryzłyby się ze sobą.
        $full_html_body = $final_body;

        // 5. Nagłówki E-maila
        $headers = [];
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        
        $sender_name  = function_exists('devents_get_sender_name')  ? devents_get_sender_name()  : get_bloginfo('name');
        $sender_email = function_exists('devents_get_sender_email') ? devents_get_sender_email() : get_option('admin_email');
        $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';

        // Obsługa Reply-To (np. dla formularza kontaktowego)
        if (isset($data['sender_email']) && is_email($data['sender_email'])) {
            $sender_reply_name = isset($data['sender_name']) ? $data['sender_name'] : $data['sender_email'];
            $headers[] = 'Reply-To: ' . $sender_reply_name . ' <' . $data['sender_email'] . '>';
        }

        if ($is_system_message) {
            $headers[] = 'X-Priority: 1 (Highest)';
            $headers[] = 'X-MSMail-Priority: High';
            $headers[] = 'Importance: High';
        } else {
            // E-maile marketingowe
            $token = hash('sha256', $to . wp_salt());
            $unsubscribe_link = home_url('/newsletter/unsubscribe?email=' . urlencode($to) . '&token=' . $token);
            
            $headers[] = 'Precedence: bulk';
            $headers[] = 'List-Unsubscribe: <' . $unsubscribe_link . '>';
            
            // Ponieważ usunęliśmy twardy wrapper w PHP, link do wypisu doklejamy ładnie na samym końcu gotowego HTML z Twiga
            if (strpos($full_html_body, 'newsletter/unsubscribe') === false && strpos($full_html_body, 'Wypisz się') === false) {
               $unsubscribe_html = '<div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #999;">Nie chcesz otrzymywać tych wiadomości? <a href="' . $unsubscribe_link . '" style="color: #666; text-decoration: underline;">Wypisz się</a>.</div>';
               
               // Wklejamy przed zamknięciem </body>, a jeśli go nie ma (bo padł Twig), po prostu doklejamy na końcu
               if (strpos($full_html_body, '</body>') !== false) {
                   $full_html_body = str_replace('</body>', $unsubscribe_html . '</body>', $full_html_body);
               } else {
                   $full_html_body .= $unsubscribe_html;
               }
            }
        }

        // 6. Wysyłka — sanityzacja tematu zapobiega wstrzyknięciu nagłówków (CRLF)
        $final_subject = sanitize_text_field($final_subject);
        $result = wp_mail($to, $final_subject, $full_html_body, $headers, $attachments);

        // 7. Logowanie błędów
        if (!$result) {
            global $phpmailer;
            $error_msg = isset($phpmailer) ? $phpmailer->ErrorInfo : 'Nieznany błąd';
            error_log("DEvents Mailer Error: Nie udało się wysłać do $to. Błąd: $error_msg");
        }

        return $result;
    }
}

// Wrapper dla kompatybilności wstecznej
if (!function_exists('devents_send_email')) {
    function devents_send_email($to, $template_key, $data = [], $attachments = []) {
        $mailer = new DEvents_Email_Manager();
        
        // Automatyczna naprawa typu danych
        if (is_string($data)) {
            $data = [
                'name'    => $data, 
                'content' => $data
            ];
        }

        $marketing_keys = ['newsletter', 'weekly_subscriber_digest'];
        $is_system = !in_array($template_key, $marketing_keys);

        return $mailer->send_email($to, null, $template_key, $data, $attachments, $is_system);
    }
}