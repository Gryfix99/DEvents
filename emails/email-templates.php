<?php
/**
 * Tablica z szablonami e-maili zintegrowana z Twig.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renderowanie szablonu e-maila za pomocą Twig.
 * Zabezpieczenie przed ponowną deklaracją funkcji.
 */
if (!function_exists('get_email_html_wrapper')) {

    function get_email_html_wrapper($content_html, $title_text = 'DEvents', $header_variant = 'white') {
        // Spójny, estetyczny szablon e-mail (bez logo-obrazka — tekstowy wordmark).
        // Zbudowany w czystym PHP na tabelach + stylach inline (kompatybilność z klientami poczty).
        $primary = '#00857c';
        $ink     = '#1f2937';
        $muted   = '#6b7280';
        $bg      = '#eef2f4';
        $card    = '#ffffff';
        $border  = '#e5e7eb';

        $site_url  = function_exists( 'home_url' ) ? home_url( '/' ) : 'https://devents.pl/';
        $year      = function_exists( 'date_i18n' ) ? date_i18n( 'Y' ) : date( 'Y' );
        $contact   = function_exists( 'get_option' ) ? get_option( 'devents_contact_email', 'kontakt@devents.pl' ) : 'kontakt@devents.pl';
        $title_esc = function_exists( 'esc_html' ) ? esc_html( $title_text ) : htmlspecialchars( $title_text, ENT_QUOTES );

        return '<!DOCTYPE html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light only">
<title>' . $title_esc . '</title>
</head>
<body style="margin:0;padding:0;background:' . $bg . ';">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:' . $card . ';border:1px solid ' . $border . ';border-radius:14px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <tr><td style="background:' . $primary . ';padding:22px 28px;">
       <span style="display:inline-block;font-size:22px;font-weight:800;letter-spacing:.5px;color:#ffffff;">DEvents</span>
       <span style="display:block;margin-top:3px;font-size:12px;color:rgba(255,255,255,.88);">Wydarzenia dostępne dla każdego</span>
    </td></tr>
    <tr><td style="padding:28px;color:' . $ink . ';font-size:15px;line-height:1.65;">' . $content_html . '</td></tr>
    <tr><td style="padding:20px 28px;background:#f8fafc;border-top:1px solid ' . $border . ';color:' . $muted . ';font-size:12px;line-height:1.6;">
       <p style="margin:0 0 6px;"><strong style="color:' . $ink . ';">DEvents</strong> — platforma wydarzeń bez barier</p>
       <p style="margin:0 0 6px;">Kontakt: <a href="mailto:' . esc_attr_compat( $contact ) . '" style="color:' . $primary . ';">' . $contact . '</a> · <a href="' . $site_url . '" style="color:' . $primary . ';">devents.pl</a></p>
       <p style="margin:0;color:#9ca3af;">Wiadomość wysłana automatycznie — prosimy na nią nie odpowiadać. &copy; ' . $year . ' DEvents</p>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>';
    }

    // Mały pomocnik na wypadek braku WP (esc_attr) — utrzymuje plik samowystarczalnym.
    if ( ! function_exists( 'esc_attr_compat' ) ) {
        function esc_attr_compat( $s ) {
            return function_exists( 'esc_attr' ) ? esc_attr( $s ) : htmlspecialchars( (string) $s, ENT_QUOTES );
        }
    }
}

// === Definicja szablonów e-mail ===
return [

    // Uniwersalny szablon: opakowuje dowolną treść {{content}} w spójną ramkę.
    // Wykorzystywany m.in. przez e-maile zamówień (send_system_email → 'base-email').
    'base-email' => [
        'subject' => 'Powiadomienie — {{site_name}}',
        'body'    => get_email_html_wrapper( '{{content}}', 'DEvents' ),
    ],

    'user_verification' => [
        'subject' => 'Potwierdź swoją rejestrację na {{site_name}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć {{first_name}},</p>
             <p>Dziękujemy za rejestrację w serwisie {{site_name}}. Aby dokończyć proces i aktywować swoje konto, kliknij w poniższy przycisk:</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{verification_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Aktywuj moje konto</a>
             </p>
             <p>Jeśli przycisk nie działa, wklej ten adres URL do przeglądarki: {{verification_link}}</p>
             <p><strong>Ważne:</strong> Link aktywacyjny jest ważny tylko przez 4 godziny.</p>',
            'Potwierdzenie rejestracji',
            'white'
        )
    ],

    'password_reset_code' => [
        'subject' => 'Twój kod do resetu hasła - {{site_name}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Twój jednorazowy kod do zresetowania hasła to:</p>
             <h2 style="text-align:center; font-size: 32px; letter-spacing: 5px; margin: 20px 0; color: #D99A29; font-weight:600;">{{reset_code}}</h2>
             <p>Kod jest ważny przez 15 minut.</p>
             <p>Jeśli to nie Ty prosiłeś o zmianę, zignoruj tę wiadomość.</p>',
            'Reset hasła',
            'dark'
        )
    ],
    
    'admin_new_content_for_review' => [
        'subject' => 'Nowa treść na portalu - {{site_name}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Na portalu {{site_name}} dodano nową treść:</p>
             <ul>
                <li><strong>Typ treści:</strong> {{content_type}}</li>
                <li><strong>Tytuł:</strong> {{content_title}}</li>
                <li><strong>Dodane przez:</strong> {{creator_name}}</li>
             </ul>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{review_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zarządzaj treścią</a>
             </p>',
            'Nowa treść na portalu',
            'dark'
        )
    ],

    'content_published' => [
        'subject' => 'Twoja treść została opublikowana! - {{site_name}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć {{creator_name}},</p>
             <p>Mamy dobre wieści! Twoje {{content_type}} o tytule <strong>"{{content_title}}"</strong> jest już dostępne na stronie.</p>
             {{pjm_invitation_offer}}
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{content_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zobacz swoją treść</a>
             </p>
             <p>Dziękujemy za Twój wkład w społeczność {{site_name}}!</p>',
            'Treść opublikowana',
            'white'
        )
    ],

    'event_reminder' => [
        'subject' => 'Przypomnienie: Twoje wydarzenie "{{event_title}}" już jutro!',
        'body'    => get_email_html_wrapper(
            '<p>Cześć {{organizer_name}},</p>
             <p>To tylko krótkie przypomnienie, że Twoje wydarzenie <strong>"{{event_title}}"</strong> rozpoczyna się już jutro o godzinie {{event_time}}.</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{event_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zobacz swoje wydarzenie</a>
             </p>
             <p>Życzymy udanego wydarzenia!</p>',
            'Przypomnienie o wydarzeniu',
            'white'
        )
    ],

    'weekly_subscriber_digest' => [
        'subject' => 'Nowe wydarzenia od obserwowanych twórców! Sprawdź nowości.',
        'body'    => get_email_html_wrapper(
            '<p>Cześć {{user_name}},</p>
             <p>Przygotowaliśmy dla Ciebie cotygodniowe podsumowanie nowych wydarzeń od organizatorów, których obserwujesz:</p>
             <div>{{new_events_list}}</div>
             <p>Mamy nadzieję, że znajdziesz coś dla siebie.</p>',
            'Tygodniowe podsumowanie',
            'white'
        )
    ],

    'admin_new_event_published' => [
        'subject' => 'Nowe wydarzenie opublikowane: {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Użytkownik <strong>{{user_name}}</strong> dodał i opublikował nowe wydarzenie: <strong>"{{event_title}}"</strong>.</p>
             <p>Data wydarzenia: {{event_date}}</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{event_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zobacz wydarzenie</a>
             </p>
             ',
            'Nowe wydarzenie',
            'dark'
        )
    ],

    'user_event_published' => [
        'subject' => 'Twoje wydarzenie "{{event_title}}" zostało opublikowane!',
        'body'    => get_email_html_wrapper(
            '<p>Cześć {{user_name}},</p>
             <p>Twoje wydarzenie <strong>"{{event_title}}"</strong> zostało pomyślnie opublikowane w serwisie.</p>
             <p>Możesz je zobaczyć klikając w poniższy przycisk:</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{event_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zobacz wydarzenie</a>
             </p>
             {{pjm_offer}}
             <p>Dziękujemy za korzystanie z DEvents!</p>',
            'Wydarzenie opublikowane',
            'white'
        )
    ],

    'admin_event_updated' => [
        'subject' => 'Wydarzenie zaktualizowane: {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Użytkownik <strong>{{user_name}}</strong> zaktualizował opublikowane wydarzenie: <strong>"{{event_title}}"</strong>.</p>
             <p>Warto sprawdzić, czy zmiany są zgodne z regulaminem.</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{event_link}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Zobacz zmiany</a>
             </p>
             ',
            'Aktualizacja wydarzenia',
            'dark'
        )
    ],

    'admin_contact_notification' => [
        'subject' => '[Kontakt] {{ subject }}',
        'body'    => get_email_html_wrapper(
            '<h2 style="color: #333; margin-top: 0;">Nowa wiadomość ze strony</h2>
            <div style="background-color: #f0fdf4; border-left: 4px solid #00857C; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <p style="margin: 0 0 5px;"><strong>Nadawca:</strong> {{ sender_name }}</p>
                <p style="margin: 0 0 5px;"><strong>E-mail:</strong> <a href="mailto:{{ sender_email }}" style="color:#00857C; font-weight:bold;">{{ sender_email }}</a></p>
                <p style="margin: 0;"><strong>Temat:</strong> {{ subject }}</p>
            </div>
            <h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">Treść wiadomości:</h3>
            <div style="font-size: 16px; line-height: 1.6; color: #444; background: #fff; padding: 10px;">
                {{ content }}
            </div>
            <br>
            <p style="font-size: 13px; color: #888; text-align: center;">
                Możesz odpowiedzieć bezpośrednio na ten e-mail – trafi on do nadawcy.
            </p>',
            'Wiadomość Kontaktowa',
            'white'
        )
    ],

    'user_contact_confirmation' => [
        'subject' => 'Potwierdzenie wysłania wiadomości - {{ site_name }}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć <strong>{{ name }}</strong>,</p>
            <p>Dziękujemy za kontakt! Twoja wiadomość dotarła do nas pomyślnie.</p>
            <p>Postaramy się odpowiedzieć najszybciej jak to możliwe.</p>',
            'Potwierdzenie kontaktu',
            'white'
        )
    ],
    
    // --- NOWY SZABLON: Zaproszenie do zespołu ---
    'team_invitation' => [
        'subject' => 'Zaproszenie do zespołu: {{institution_name}}',
        'body'    => get_email_html_wrapper(
            '<p style="font-size: 16px;">Cześć {{user_name}}!</p>
             <p>Zostałeś zaproszony do dołączenia do zespołu instytucji: <br>
                <strong>{{institution_name}}</strong>
             </p>
             <p>Kliknij poniższy przycisk, aby zaakceptować zaproszenie i ustawić hasło do konta:</p>
             <p style="text-align: center; margin: 30px 0;">
                <a href="{{login_url}}" class="button" style="background-color: #008C83; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 30px; font-weight: bold;">
                    Dołącz do zespołu
                </a>
             </p>
             <p style="font-size: 12px; color: #666;">
                Jeśli przycisk nie działa, skopiuj ten link: <br>
                {{login_url}}
             </p>
             <p style="font-size: 12px; color: #999; margin-top: 20px;">
                Link jest ważny przez 48 godzin.
             </p>',
            'Zaproszenie do zespołu',
            'white'
        )
    ],
    
    // --- NOWY SZABLON: Powiadomienie o dodaniu do zespołu (gdy user już istniał) ---
    'team_added_notification' => [
        'subject' => 'Zostałeś dodany do zespołu: {{institution_name}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć!</p>
             <p>Twoje konto zostało przypisane do zespołu instytucji: <strong>{{institution_name}}</strong>.</p>
             <p>Możesz teraz zalogować się do swojego panelu i zarządzać wydarzeniami tej instytucji.</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="' . home_url('/panel-uzytkownika/') . '" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">Przejdź do panelu</a>
             </p>',
            'Aktualizacja zespołu',
            'white'
        )
    ],

    // --- NOWY SZABLON: Powiadomienie hierarchiczne o nowym wydarzeniu ---
    'new_event_added_hierarchy' => [
        'subject' => 'Nowe wydarzenie dodane przez członka zespołu: {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Członek Twojego zespołu (<strong>{{creator_name}}</strong>) dodał nowe wydarzenie w portalu {{site_name}}.</p>
             <p><strong>Tytuł wydarzenia:</strong> {{event_title}}</p>
             <p style="text-align: center; margin: 25px 0;">
                <a href="{{event_url}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">
                    Zobacz wydarzenie
                </a>
             </p>
             <p style="font-size: 13px; color: #666;">
                Otrzymujesz tę wiadomość, ponieważ jesteś głównym organizatorem dla tej osoby, lub administratorem instytucji nadrzędnej.
             </p>',
            'Powiadomienie o wydarzeniu',
            'white'
        )
    ],

    // --- NOWY SZABLON: Powiadomienie o usunięciu wydarzenia ---
    'event_deleted_notification' => [
        'subject' => 'Uwaga: Usunięto wydarzenie "{{event_title}}"',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Informujemy, że wydarzenie o tytule <strong>"{{event_title}}"</strong> zostało trwale usunięte z portalu {{site_name}}.</p>
             <p><strong>Usunięte przez:</strong> {{deleted_by}}</p>
             
             <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0; color: #b91c1c; font-size: 14px;">
                    Zgodnie z polityką bezpieczeństwa powiadomienie to zostało wysłane do twórcy wydarzenia, administracji oraz podmiotu nadrzędnego.
                </p>
             </div>
             
             <p style="font-size: 13px; color: #666;">
                Jeśli to usunięcie nastąpiło przez pomyłkę lub bez Twojej zgody, niezwłocznie skontaktuj się z administratorem serwisu.
             </p>',
            'Usunięcie wydarzenia',
            'white'
        )
    ],

    // --- NOWY SZABLON: Wydarzenie odwołane (Soft Delete) ---
    'event_cancelled_notification' => [
        'subject' => 'Wydarzenie zostało odwołane: {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Informujemy, że wydarzenie o tytule <strong>"{{event_title}}"</strong> zostało odwołane na portalu {{site_name}}.</p>
             <p><strong>Odwołane przez:</strong> {{action_by}}</p>
             
             <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0; color: #b91c1c; font-size: 14px;">
                    Wydarzenie nie jest już widoczne publicznie. Możesz je przywrócić w zakładce "Odwołane" w swoim panelu.
                </p>
             </div>',
            'Wydarzenie odwołane',
            'white'
        )
    ],

    // --- NOWY SZABLON: Wydarzenie przywrócone ---
    'event_restored_notification' => [
        'subject' => 'Wydarzenie zostało przywrócone (Szkic): {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Wydarzenie <strong>"{{event_title}}"</strong> zostało przywrócone jako wersja robocza (Szkic).</p>
             <p><strong>Przywrócone przez:</strong> {{action_by}}</p>
             <p>Aby ponownie pojawiło się na stronie głównej, należy wejść w edycję i kliknąć "Opublikuj".</p>',
            'Przywrócenie wydarzenia',
            'white'
        )
    ],

    // --- NOWY SZABLON: Wydarzenie zaktualizowane ---
    'event_edited_notification' => [
        'subject' => 'Zaktualizowano wydarzenie: {{event_title}}',
        'body'    => get_email_html_wrapper(
            '<p>Cześć,</p>
             <p>Wydarzenie o tytule <strong>"{{event_title}}"</strong> zostało przed chwilą zaktualizowane w systemie.</p>
             <p><strong>Edytowane przez:</strong> {{editor_name}}</p>
             
             <p style="text-align: center; margin: 30px 0;">
                <a href="{{event_url}}" class="button" style="background-color:#008C83; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">
                    Zobacz wydarzenie
                </a>
             </p>
             <p style="font-size: 13px; color: #666;">
                Otrzymujesz tę wiadomość, ponieważ jesteś autorem tego wydarzenia lub osobą, która je edytowała.
             </p>',
            'Aktualizacja wydarzenia',
            'white'
        )
    ],

    'institution_deleted_notification' => [
        'subject' => 'Ważne: Twoja instytucja została usunięta z systemu',
        'body'    => get_email_html_wrapper(
            '<p>Cześć <strong>{{user_name}}</strong>,</p>
             <p>Informujemy, że placówka <strong>"{{institution_name}}"</strong>, do której byłeś/aś przypisany/a w naszym systemie, została trwale usunięta przez administratora.</p>
             <p>Twoje konto pozostaje aktywne, jednak Twoja rola została zmieniona na standardowego Uczestnika. Utraciłeś/aś uprawnienia do zarządzania tą placówką oraz tworzenia dla niej nowych wydarzeń.</p>
             
             <p style="text-align: center; margin: 30px 0;">
                <a href="{{panel_url}}" class="button" style="background-color:#e11d48; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:30px; font-weight:bold;">
                    Przejdź do swojego profilu
                </a>
             </p>
             <p style="font-size: 13px; color: #666;">
                Jeśli uważasz, że nastąpiła pomyłka lub masz dodatkowe pytania, skontaktuj się z głównym administratorem platformy.
             </p>',
            'Usunięcie instytucji',
            'white'
        )
    ],
];