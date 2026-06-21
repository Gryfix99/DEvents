<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ============================================================================
 * DEvents Helpers — v13.2 (COMPLETE)
 *
 * Wszystkie stringi opakowane w __() dla i18n.
 * Kompatybilne z nowym class-devents-i18n.php i plikami .mo
 * ============================================================================
 */

if ( ! function_exists( 'devents_optimize_uploaded_image' ) ) {
    /**
     * Optymalizuje wgrany obraz: skaluje w dół do maksymalnego wymiaru, kompresuje
     * (mniejszy plik = mniej miejsca) i nadaje czytelną, jednoznaczną nazwę.
     *
     * Dzięki temu z jednego uploadu powstaje JEDEN, lekki plik o ładnej nazwie
     * (np. "wydarzenie-123-20260612-101500.jpg") zamiast oryginału + wielu miniatur.
     *
     * @param array  $upload    Wynik wp_handle_upload (['file','url','type']).
     * @param string $nice_base Bazowa, czytelna nazwa pliku (zostanie zsanityzowana).
     * @param int    $max_dim   Maks. szerokość/wysokość w px (proporcje zachowane).
     * @param int    $quality   Jakość kompresji (JPEG/WebP 1-100).
     * @return array            Zaktualizowane ['file','url','type'] lub oryginał przy błędzie.
     */
    function devents_optimize_uploaded_image( array $upload, string $nice_base, int $max_dim = 1600, int $quality = 82 ) : array {
        if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
            return $upload;
        }

        $type = isset( $upload['type'] ) ? (string) $upload['type'] : '';
        // Tylko rastrowe obrazy. SVG/PDF/GIF (animowane) zostawiamy bez zmian.
        if ( strpos( $type, 'image/' ) !== 0 || strpos( $type, 'svg' ) !== false || strpos( $type, 'gif' ) !== false ) {
            return $upload;
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Podnosimy limit pamięci TYLKO na czas operacji na obrazie (standard WP).
        // Bez tego duże zdjęcia (np. 5000 px prosto z telefonu) potrafią wyczerpać
        // pamięć PHP zanim zdążą się przeskalować — i cały upload pada.
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'image' );
        }

        // Czysta, unikalna nazwa pliku.
        $dir = pathinfo( $upload['file'], PATHINFO_DIRNAME );
        $ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
        if ( $ext === '' || $ext === 'jpeg' ) {
            $ext = 'jpg';
        }
        // Konwersja do WebP (mniejszy rozmiar pliku) — gdy serwer ją obsługuje.
        $webp_ok = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
        if ( $webp_ok ) {
            $ext = 'webp';
        }
        $base = sanitize_title( $nice_base );
        if ( $base === '' ) {
            $base = 'devents';
        }
        $filename = wp_unique_filename( $dir, $base . '-' . gmdate( 'Ymd-His' ) . '.' . $ext );
        $target   = trailingslashit( $dir ) . $filename;

        // Cała obróbka w try/catch — przy braku pamięci/Imagicku łapiemy wyjątek
        // i zwracamy oryginał, zamiast wywalać błąd 500 podczas zapisu wydarzenia.
        try {
            $editor = wp_get_image_editor( $upload['file'] );
            if ( is_wp_error( $editor ) ) {
                return $upload; // brak GD/Imagick — nie ryzykujemy, zostawiamy oryginał
            }

            // Skalowanie w dół bez przycinania, gdy obraz jest większy niż limit.
            $size = $editor->get_size();
            if ( ! empty( $size['width'] ) && ( $size['width'] > $max_dim || $size['height'] > $max_dim ) ) {
                $editor->resize( $max_dim, $max_dim, false );
            }
            $editor->set_quality( $quality );

            $saved = $webp_ok ? $editor->save( $target, 'image/webp' ) : $editor->save( $target );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'DEvents optimize image: ' . $e->getMessage() );
            }
            return $upload; // awaria obróbki — zostaw oryginał, nie psuj zapisu
        }

        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            return $upload; // zapis się nie udał — zostaw oryginał
        }

        // Usuń oryginał, jeśli powstał nowy plik o innej nazwie.
        if ( $saved['path'] !== $upload['file'] && file_exists( $upload['file'] ) ) {
            @unlink( $upload['file'] );
        }

        $upload_dir = wp_upload_dir();
        $new_url = ( ! empty( $upload_dir['basedir'] ) && ! empty( $upload_dir['baseurl'] ) )
            ? str_replace( trailingslashit( $upload_dir['basedir'] ), trailingslashit( $upload_dir['baseurl'] ), $saved['path'] )
            : $upload['url'];

        return [
            'file' => $saved['path'],
            'url'  => $new_url,
            'type' => isset( $saved['mime-type'] ) ? $saved['mime-type'] : $type,
        ];
    }
}

if ( ! function_exists( 'devents_store_institution_logo' ) ) {
    /**
     * Zapisuje logo instytucji: konwersja do WebP (kasuje oryginał jpg/png) i
     * przeniesienie do uploads/devents_logos pod stałą, czytelną nazwą
     * LOGO_[id]_nazwa-instytucji.webp. Stare logo tej instytucji jest usuwane.
     *
     * @param array  $upload    Wynik wp_handle_upload (['file','url','type']).
     * @param int    $inst_id   ID instytucji.
     * @param string $inst_name Nazwa instytucji (do czytelnej nazwy pliku).
     * @return array            Zaktualizowane ['file','url','type'].
     */
    function devents_store_institution_logo( array $upload, int $inst_id, string $inst_name ) : array {
        // 1. Optymalizacja + WebP (usuwa oryginał). Bezpieczne, gdy plik już jest webp.
        if ( function_exists( 'devents_optimize_uploaded_image' ) ) {
            $upload = devents_optimize_uploaded_image( $upload, 'logo', 600, 85 );
        }
        if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) || $inst_id <= 0 ) {
            return $upload;
        }

        $updir = wp_upload_dir();
        if ( empty( $updir['basedir'] ) || ! empty( $updir['error'] ) ) {
            return $upload;
        }
        $logos_dir = trailingslashit( $updir['basedir'] ) . 'devents_logos';
        $logos_url = trailingslashit( $updir['baseurl'] ) . 'devents_logos';
        if ( ! file_exists( $logos_dir ) ) {
            wp_mkdir_p( $logos_dir );
        }

        $ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
        if ( $ext === '' ) {
            $ext = 'webp';
        }
        $slug = sanitize_title( $inst_name );
        if ( $slug === '' ) {
            $slug = 'instytucja';
        }
        $filename = 'LOGO_' . $inst_id . '_' . $slug . '.' . $ext;
        $target   = trailingslashit( $logos_dir ) . $filename;

        // Usuń poprzednie logo tej instytucji (dowolna nazwa/rozszerzenie LOGO_[id]_*).
        foreach ( (array) glob( trailingslashit( $logos_dir ) . 'LOGO_' . $inst_id . '_*' ) as $old ) {
            if ( $old !== $target && is_file( $old ) ) {
                @unlink( $old );
            }
        }

        if ( @rename( $upload['file'], $target ) ) {
            $upload['file'] = $target;
            $upload['url']  = trailingslashit( $logos_url ) . $filename;
        }
        return $upload;
    }
}

if ( ! function_exists( 'devents_delete_upload_by_url' ) ) {
    /**
     * Bezpiecznie usuwa plik z katalogu uploads na podstawie jego URL-a
     * (np. poprzednie logo/awatar przy zmianie). Usuwa WYŁĄCZNIE pliki
     * leżące wewnątrz wp-content/uploads — nigdy spoza tego katalogu.
     */
    function devents_delete_upload_by_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return;
        }
        $url   = preg_replace( '/\?.*$/', '', $url ); // bez ewentualnego ?v=…
        $updir = wp_upload_dir();
        if ( empty( $updir['baseurl'] ) || empty( $updir['basedir'] ) ) {
            return;
        }
        if ( strpos( $url, $updir['baseurl'] ) !== 0 ) {
            return; // URL spoza uploads — nie ruszamy
        }
        $path = str_replace( trailingslashit( $updir['baseurl'] ), trailingslashit( $updir['basedir'] ), $url );
        $real = realpath( $path );
        $base = realpath( $updir['basedir'] );
        // realpath + prefix => zabezpieczenie przed wyjściem poza uploads (../).
        if ( $real && $base && strpos( $real, $base ) === 0 && is_file( $real ) ) {
            @unlink( $real );
        }
    }
}

/* =====================================================================
   MODEL RÓL v2 — 3 osie (rola konta / typ instytucji / pozycja).
   Helpery są zgodne WSTECZ: czytają nowy model (typ instytucji + meta
   devents_institution_role + rola 'organizer'), a gdy go brak — starych ról
   (master_organizer / organizer_mod / master_organizer_mod). Dzięki temu
   przejście (migracja) jest bezpieczne, a sprawdzenia działają przed i po.
   ===================================================================== */
if ( ! function_exists( 'devents_get_user_institution_id' ) ) {
    function devents_get_user_institution_id( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        return (int) get_user_meta( $user_id, 'devents_institution_id', true );
    }
}
if ( ! function_exists( 'devents_get_user_institution_type' ) ) {
    /** Typ instytucji użytkownika: 'master' | 'unit' | '' (brak). */
    function devents_get_user_institution_type( $user_id = null ) {
        $inst_id = devents_get_user_institution_id( $user_id );
        if ( $inst_id <= 0 ) {
            return '';
        }
        global $wpdb;
        $type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}devents_institutions WHERE id = %d", $inst_id ) );
        return $type ? (string) $type : '';
    }
}
if ( ! function_exists( 'devents_user_roles' ) ) {
    function devents_user_roles( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        $u = get_userdata( $user_id );
        return $u ? (array) $u->roles : array();
    }
}
if ( ! function_exists( 'devents_user_is_business' ) ) {
    /** Czy użytkownik jest członkiem instytucji (organizatorem dowolnego typu/pozycji). */
    function devents_user_is_business( $user_id = null ) {
        $roles = devents_user_roles( $user_id );
        if ( in_array( 'organizer', $roles, true ) ) {
            return true;
        }
        return (bool) array_intersect( array( 'master_organizer', 'organizer_mod', 'master_organizer_mod' ), $roles );
    }
}
if ( ! function_exists( 'devents_user_is_owner' ) ) {
    /** Pozycja: właściciel/założyciel instytucji lub jednostki. */
    function devents_user_is_owner( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! devents_user_is_business( $user_id ) ) {
            return false;
        }
        $pos = get_user_meta( $user_id, 'devents_institution_role', true );
        if ( $pos === 'owner' ) {
            return true;
        }
        if ( $pos === 'member' || $pos === 'moderator' ) {
            return false;
        }
        // legacy: rola bez '_mod' = właściciel
        $roles = devents_user_roles( $user_id );
        return ! in_array( 'organizer_mod', $roles, true ) && ! in_array( 'master_organizer_mod', $roles, true );
    }
}
if ( ! function_exists( 'devents_user_is_moderator' ) ) {
    /** Pozycja: moderator (członek) instytucji lub jednostki. */
    function devents_user_is_moderator( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! devents_user_is_business( $user_id ) ) {
            return false;
        }
        return ! devents_user_is_owner( $user_id );
    }
}
if ( ! function_exists( 'devents_user_is_master' ) ) {
    /** Czy instytucja użytkownika jest typu MASTER (kieruje jednostkami). */
    function devents_user_is_master( $user_id = null ) {
        $type = devents_get_user_institution_type( $user_id );
        if ( $type !== '' ) {
            return $type === 'master';
        }
        $roles = devents_user_roles( $user_id );
        return in_array( 'master_organizer', $roles, true ) || in_array( 'master_organizer_mod', $roles, true );
    }
}
if ( ! function_exists( 'devents_user_can_publish' ) ) {
    /** Może publikować wydarzenia (członek instytucji albo admin strony). */
    function devents_user_can_publish( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        return devents_user_is_business( $user_id ) || user_can( $user_id, 'manage_options' );
    }
}
if ( ! function_exists( 'devents_user_can_manage_units' ) ) {
    /** Może zarządzać siecią jednostek (właściciel instytucji typu master). */
    function devents_user_can_manage_units( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }
        return devents_user_is_master( $user_id ) && devents_user_is_owner( $user_id );
    }
}
if ( ! function_exists( 'devents_user_role_label' ) ) {
    /** Czytelna etykieta roli/pozycji do UI. */
    function devents_user_role_label( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( user_can( $user_id, 'manage_options' ) ) {
            return __( 'Administrator', 'devents' );
        }
        if ( devents_user_is_business( $user_id ) ) {
            $type = devents_get_user_institution_type( $user_id );
            $base = ( $type === 'master' ) ? __( 'Instytucja kierująca', 'devents' ) : __( 'Organizator', 'devents' );
            $pos  = devents_user_is_owner( $user_id ) ? __( 'administrator', 'devents' ) : __( 'moderator', 'devents' );
            return $base . ' · ' . $pos;
        }
        return __( 'Użytkownik', 'devents' );
    }
}


if ( ! function_exists( 'devents_user_allows_event_emails' ) ) {
    /**
     * Czy użytkownik zgodził się na e-maile o nowych wydarzeniach obserwowanych
     * organizatorów. Domyślnie TAK (brak meta = zgoda), wyłączone tylko przy '0'.
     * Używaj przy wysyłce powiadomień do obserwujących.
     */
    function devents_user_allows_event_emails( $user_id ) : bool {
        return get_user_meta( (int) $user_id, 'devents_notify_followed_events', true ) !== '0';
    }
}

if ( ! function_exists( 'devents_user_allows_marketing' ) ) {
    /** Czy użytkownik zgodził się na wiadomości marketingowe / newsletter. */
    function devents_user_allows_marketing( $user_id ) : bool {
        return (string) get_user_meta( (int) $user_id, 'devents_marketing_consent', true ) === '1';
    }
}

/* =====================================================================
   NARZĘDZIA OGÓLNE
   ===================================================================== */

/**
 * Rekursywnie usuwa ukośniki (slashes) z danych wydarzenia.
 */
if ( ! function_exists( 'devents_unslash_event_data' ) ) {
    function devents_unslash_event_data( $data ) {
        return stripslashes_deep( $data );
    }
}

/**
 * Zwraca informacje o statusie weryfikacji.
 */
function get_status_info_helper( $status_id ) {
    $status_map = [
        1 => [ 'text' => __( 'Zweryfikowany', 'devents' ),   'class' => 'status-badge--published' ],
        2 => [ 'text' => __( 'Wersja robocza', 'devents' ),  'class' => 'status-badge--draft' ],
        3 => [ 'text' => __( 'Do weryfikacji', 'devents' ),  'class' => 'status-badge--updated' ],
        0 => [ 'text' => __( 'Oczekuje', 'devents' ),        'class' => 'status-badge--pending' ],
    ];
    return $status_map[ intval( $status_id ) ] ?? $status_map[0];
}


/* =====================================================================
   BADGE SYSTEM (Odznaki aktywności)
   ===================================================================== */

/**
 * Główna funkcja obliczająca odznakę.
 * Obsługuje zarówno ID Instytucji jak i ID Użytkownika.
 */
function devents_calculate_badge( $id, $type = 'institution' ) {
    global $wpdb;
    $table_events = $wpdb->prefix . 'events_list';

    $transient_key = 'devents_badge_' . $type . '_' . $id;

    if ( false === ( $badge_data = get_transient( $transient_key ) ) ) {

        if ( $type === 'institution' ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM {$table_events} WHERE institution_id = %d AND verified = 1", $id
            ) );
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(id) FROM {$table_events} WHERE user_id = %d AND verified = 1", $id
            ) );
        }

        $badge = null;

        if ( $count >= 500 ) {
            $badge = [ 'key' => 'legend',   'label' => __( 'Legenda', 'devents' ),    'color' => '#000000', 'icon' => 'auto_awesome',      'bg' => 'linear-gradient(135deg, #1a1a1a 0%, #434343 100%)', 'text' => '#ffffff' ];
        } elseif ( $count >= 100 ) {
            $badge = [ 'key' => 'diamond',  'label' => __( 'Diamentowy', 'devents' ), 'color' => '#0ea5e9', 'icon' => 'diamond',           'bg' => '#e0f2fe', 'text' => '#0369a1' ];
        } elseif ( $count >= 50 ) {
            $badge = [ 'key' => 'platinum', 'label' => __( 'Platynowy', 'devents' ),  'color' => '#475569', 'icon' => 'shield',            'bg' => '#f1f5f9', 'text' => '#334155' ];
        } elseif ( $count >= 25 ) {
            $badge = [ 'key' => 'gold',     'label' => __( 'Złoty', 'devents' ),      'color' => '#d97706', 'icon' => 'verified',          'bg' => '#fef3c7', 'text' => '#92400e' ];
        } elseif ( $count >= 10 ) {
            $badge = [ 'key' => 'silver',   'label' => __( 'Srebrny', 'devents' ),    'color' => '#64748b', 'icon' => 'military_tech',     'bg' => '#f8fafc', 'text' => '#475569' ];
        } elseif ( $count >= 5 ) {
            $badge = [ 'key' => 'bronze',   'label' => __( 'Aktywny', 'devents' ),    'color' => '#b45309', 'icon' => 'workspace_premium', 'bg' => '#fffbeb', 'text' => '#92400e' ];
        }

        $badge_data = [
            'count' => $count,
            'info'  => $badge,
        ];

        set_transient( $transient_key, $badge_data, 12 * HOUR_IN_SECONDS );
    }

    return $badge_data;
}

/**
 * Wrapper dla Instytucji.
 */
function devents_get_institution_badge( $institution_id ) {
    if ( ! $institution_id ) return null;
    return devents_calculate_badge( $institution_id, 'institution' );
}

/**
 * Wrapper dla Użytkownika.
 * Sprawdza instytucję, fallback na prywatne wydarzenia.
 */
function devents_get_user_badge_info( $user_id ) {
    $institution_id = get_user_meta( $user_id, 'devents_institution_id', true );

    if ( $institution_id ) {
        $data = devents_get_institution_badge( $institution_id );
    } else {
        $data = devents_calculate_badge( $user_id, 'user' );
    }

    if ( $data && $data['info'] ) {
        return [
            'name'  => $data['info']['label'],
            'color' => $data['info']['color'],
            'icon'  => $data['info']['icon'],
            'count' => $data['count'],
            'info'  => $data['info'],
        ];
    }

    return null;
}


/* =====================================================================
   BASE64 IMAGE HELPER
   ===================================================================== */

function devents_get_image_as_base64( $url ) {
    $default_image = DEW_PLUGIN_URL . 'assets/images/default-event-bg.jpg';
    if ( empty( $url ) ) return $default_image;
    if ( strpos( $url, home_url() ) !== false ) return $url;

    $transient_key = 'devents_img_b64_' . md5( $url );
    if ( $cached = get_transient( $transient_key ) ) return $cached;

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return $default_image;
    }

    $body      = wp_remote_retrieve_body( $response );
    $mime_type = wp_remote_retrieve_header( $response, 'content-type' );

    if ( strpos( $mime_type, 'image/' ) !== 0 ) return $default_image;

    $base64 = 'data:' . $mime_type . ';base64,' . base64_encode( $body );
    set_transient( $transient_key, $base64, HOUR_IN_SECONDS );

    return $base64;
}


/* =====================================================================
   MENU PANELU UŻYTKOWNIKA
   ===================================================================== */

if ( ! function_exists( 'devents_get_panel_menu_items' ) ) {
    function devents_get_panel_menu_items() {
        if ( ! is_user_logged_in() ) return [];

        $user    = wp_get_current_user();
        $user_id = $user->ID;
        $roles   = (array) $user->roles;

        // Model ról v2 — przez helpery (typ instytucji + pozycja + rola), zgodne wstecz.
        $is_admin    = current_user_can( 'manage_options' );
        $is_master   = devents_user_is_master( $user_id );
        $is_owner    = devents_user_is_owner( $user_id );
        $is_business = devents_user_can_publish( $user_id );
        $can_publish = $is_business;

        $menu_items = [
            // --- Grupa: Start ---
            'pulpit' => [
                'title'    => __( 'Pulpit', 'devents' ),
                'subtitle' => __( 'Podsumowanie Twojej aktywności i statystyki', 'devents' ),
                'icon'     => 'space_dashboard',
                'group'    => 'start',
                'visible'  => true,
                'priority' => 10,
            ],
            // --- Grupa: Wydarzenia ---
            'dodaj-wydarzenie' => [
                'title'    => __( 'Dodaj wydarzenie', 'devents' ),
                'subtitle' => __( 'Opublikuj nowe, dostępne wydarzenie', 'devents' ),
                'icon'     => 'add_circle',
                'group'    => 'events',
                'visible'  => $can_publish,
                'priority' => 20,
            ],
            'moje-wydarzenia' => [
                'title'    => __( 'Zarządzaj wydarzeniami', 'devents' ),
                'subtitle' => __( 'Edytuj i zarządzaj swoimi wydarzeniami', 'devents' ),
                'icon'     => 'calendar_month',
                'group'    => 'events',
                'visible'  => $is_business,
                'priority' => 30,
            ],
            'moje-opinie' => [
                'title'    => __( 'Pytania o wydarzenia', 'devents' ),
                'subtitle' => __( 'Pytania uczestników o Twoje wydarzenia', 'devents' ),
                'icon'     => 'forum',
                'group'    => 'events',
                // Skrzynka pytań organizatora — ukryta dla administratora platformy.
                'visible'  => $is_business && ! $is_admin,
                'priority' => 40,
            ],
            // --- Grupa: Sprzedaż (ukryta dla admina) ---
            'zamowienia' => [
                'title'    => __( 'Zamówienia', 'devents' ),
                'subtitle' => __( 'Historia i status Twoich zamówień', 'devents' ),
                'icon'     => 'shopping_bag',
                'group'    => 'sales',
                'visible'  => $is_business && ! $is_admin,
                'priority' => 50,
            ],
            'moje-nagrody' => [
                'title'    => __( 'Nagrody', 'devents' ),
                'subtitle' => __( 'Odznaki za aktywność na DEvents', 'devents' ),
                'icon'     => 'military_tech',
                'group'    => 'sales',
                'visible'  => $is_business && ! $is_admin,
                'priority' => 60,
            ],
            // --- Grupa: Twoja organizacja (ukryta dla admina) ---
            'moja-instytucja' => [
                'title'    => __( 'Profil instytucji', 'devents' ),
                'subtitle' => __( 'Dane Twojej organizacji widoczne dla uczestników wydarzeń.', 'devents' ),
                'icon'     => 'domain',
                'group'    => 'org',
                // Tylko właściciele instytucji (organizator + master), nie moderatorzy.
                // Admin zarządza instytucjami w Panelu Admina, więc tu nie pokazujemy.
                'visible'  => $is_owner,
                'priority' => 70,
            ],
            'moje-jednostki' => [
                'title'    => __( 'Sieć jednostek', 'devents' ),
                'subtitle' => __( 'Zarządzaj siecią jednostek podległych', 'devents' ),
                'icon'     => 'account_tree',
                'group'    => 'org',
                'visible'  => $is_master && $is_owner,
                'priority' => 80,
            ],
            'raporty' => [
                'title'    => __( 'Raporty', 'devents' ),
                'subtitle' => __( 'Płatne zestawienia wydarzeń Twojej instytucji', 'devents' ),
                'icon'     => 'summarize',
                'group'    => 'org',
                // Płatne raporty o wydarzeniach — tylko właściciele instytucji.
                'visible'  => $is_owner,
                'priority' => 82,
            ],
            // --- Grupa: Moja aktywność ---
            // „Moje potrzeby" przeniesione do Ustawień konta (zakładka „Moje potrzeby").
            'moje-ulubione' => [
                'title'    => __( 'Ulubione wydarzenia', 'devents' ),
                'subtitle' => __( 'Wydarzenia, które obserwujesz', 'devents' ),
                'icon'     => 'favorite',
                'group'    => 'activity',
                'visible'  => true,
                'priority' => 90,
            ],
            'zapisane-wyszukiwania' => [
                'title'    => __( 'Zapisane wyszukiwania', 'devents' ),
                'subtitle' => __( 'Powiadomimy Cię o nowych wydarzeniach pasujących do filtrów', 'devents' ),
                'icon'     => 'saved_search',
                'group'    => 'activity',
                'visible'  => true,
                'priority' => 95,
            ],
            'moje-pytania' => [
                'title'    => __( 'Moje pytania', 'devents' ),
                'subtitle' => __( 'Twoje pytania do organizatorów', 'devents' ),
                'icon'     => 'help',
                'group'    => 'activity',
                'visible'  => true,
                'priority' => 100,
            ],
            'moje-subskrypcje' => [
                'title'    => __( 'Moje subskrypcje', 'devents' ),
                'subtitle' => __( 'Zarządzaj powiadomieniami i subskrypcjami', 'devents' ),
                'icon'     => 'rss_feed',
                'group'    => 'activity',
                'visible'  => true,
                'priority' => 110,
            ],
            // --- Grupa: Konto i pomoc ---
            'ustawienia' => [
                'title'    => __( 'Ustawienia konta', 'devents' ),
                'subtitle' => __( 'Zarządzaj swoimi danymi, hasłem i potrzebami dostępności.', 'devents' ),
                // Ikona spójna z nagłówkiem strony (devents_header → „Ustawienia" = settings).
                'icon'     => 'settings',
                'group'    => 'help',
                'visible'  => true,
                'priority' => 120,
            ],
            'zglos-problem' => [
                'title'    => __( 'Zgłoś problem', 'devents' ),
                'subtitle' => __( 'Zgłoś problem lub barierę dostępności', 'devents' ),
                'icon'     => 'report_problem',
                'group'    => 'help',
                'visible'  => true,
                'priority' => 130,
            ],
        ];

        if ( $is_admin ) {
            $menu_items['panel-admina'] = [
                'title'    => __( 'Panel Admina', 'devents' ),
                'icon'     => 'shield_person',
                'group'    => 'help',
                'visible'  => true,
                'priority' => 140,
            ];
            // Administrator platformy ma pełny dostęp — odsłaniamy wszystkie zakładki
            // panelu (m.in. Zamówienia, Profil instytucji, Sieć jednostek, Pytania, Nagrody).
            foreach ( $menu_items as $slug => $item ) {
                $menu_items[ $slug ]['visible'] = true;
            }
        }

        uasort( $menu_items, function ( $a, $b ) {
            return $a['priority'] <=> $b['priority'];
        } );

        return apply_filters( 'devents_panel_menu_items', $menu_items, $user_id );
    }
}

if ( ! function_exists( 'devents_get_panel_menu_groups' ) ) {
    /**
     * Etykiety sekcji nawigacji panelu (kolejność = kolejność renderowania).
     * Grupa 'start' nie ma etykiety — Pulpit stoi na górze bez nagłówka.
     */
    function devents_get_panel_menu_groups() {
        return apply_filters( 'devents_panel_menu_groups', [
            'start'    => '',
            'events'   => __( 'Wydarzenia', 'devents' ),
            'sales'    => __( 'Sprzedaż', 'devents' ),
            'org'      => __( 'Twoja organizacja', 'devents' ),
            'activity' => __( 'Moja aktywność', 'devents' ),
            'help'     => __( 'Konto i pomoc', 'devents' ),
        ] );
    }
}


/* =====================================================================
   NAWIGACJA — PROGRAMOWE POZYCJE MENU
   ===================================================================== */

if ( ! function_exists( 'devents_add_user_menu_items_to_nav' ) ) {
    function devents_add_user_menu_items_to_nav( $items, $args ) {
        $li_classes = 'menu-item menu-item-type-custom menu-item-object-custom menu-item-devents hfe-creative-menu';

        if ( is_user_logged_in() ) {
            $items .= '<li class="' . $li_classes . '">'
                . '<a class="hfe-menu-item" href="' . esc_url( home_url( '/panel-uzytkownika/' ) ) . '">'
                . esc_html__( 'Panel użytkownika', 'devents' )
                . '</a></li>';

            $items .= '<li class="' . $li_classes . '">'
                . '<a class="hfe-menu-item" href="' . esc_url( wp_logout_url( home_url() ) ) . '">'
                . esc_html__( 'Wyloguj się', 'devents' )
                . '</a></li>';
        } else {
            $items .= '<li class="' . $li_classes . '">'
                . '<a class="hfe-menu-item" href="' . esc_url( home_url( '/zaloguj-sie/' ) ) . '">'
                . esc_html__( 'Zaloguj się', 'devents' )
                . '</a></li>';

            $items .= '<li class="' . $li_classes . '">'
                . '<a class="hfe-menu-item" href="' . esc_url( home_url( '/zarejestruj-sie/' ) ) . '">'
                . esc_html__( 'Zarejestruj się', 'devents' )
                . '</a></li>';
        }

        return $items;
    }
    add_filter( 'wp_nav_menu_items', 'devents_add_user_menu_items_to_nav', 99, 2 );
}


/* =====================================================================
   TŁUMACZENIE POZYCJI MENU WORDPRESS (Wygląd → Menu)
   ===================================================================== */

if ( ! function_exists( 'devents_translate_menu_items' ) ) {

    /**
     * Słownik pozycji menu do tłumaczenia.
     * Dodaj tutaj KAŻDĄ pozycję menu z panelu WP którą chcesz tłumaczyć.
     */
    function devents_get_menu_translations(): array {
        return [
            'Strona główna'              => __( 'Strona główna', 'devents' ),
            'Wydarzenie'                 => __( 'Wydarzenie', 'devents' ),
            'Wydarzenia'                 => __( 'Wydarzenia', 'devents' ),
            'Wyszukiwarka wydarzeń'      => __( 'Wyszukiwarka wydarzeń', 'devents' ),
            'Wyszukiwarka Wydarzeń'      => __( 'Wyszukiwarka Wydarzeń', 'devents' ),
            'Organizatorzy'              => __( 'Organizatorzy', 'devents' ),
            'Wyszukiwarka organizatorów' => __( 'Wyszukiwarka organizatorów', 'devents' ),
            'Wyszukiwarka Organizatorów' => __( 'Wyszukiwarka Organizatorów', 'devents' ),
            'Aktualności'                => __( 'Aktualności', 'devents' ),
            'Kontakt'                    => __( 'Kontakt', 'devents' ),
            'O nas'                      => __( 'O nas', 'devents' ),
            'Regulamin'                  => __( 'Regulamin', 'devents' ),
            'Polityka prywatności'       => __( 'Polityka prywatności', 'devents' ),
            'Panel użytkownika'          => __( 'Panel użytkownika', 'devents' ),
            'Zaloguj się'                => __( 'Zaloguj się', 'devents' ),
            'Zarejestruj się'            => __( 'Zarejestruj się', 'devents' ),
            'Wyloguj się'                => __( 'Wyloguj się', 'devents' ),
            'Dostępność'                 => __( 'Dostępność', 'devents' ),
            'Pomoc'                      => __( 'Pomoc', 'devents' ),
            'FAQ'                        => __( 'FAQ', 'devents' ),
        ];
    }

    /**
     * Filtr na wp_nav_menu_objects — podmienia tytuły na przetłumaczone.
     */
    function devents_translate_menu_items( $items ) {
        if ( ! class_exists( 'DEvents_I18n' ) ) return $items;

        $i18n = DEvents_I18n::get_instance();
        if ( ! $i18n->is_overridden() ) return $items;

        $translations = devents_get_menu_translations();

        foreach ( $items as $item ) {
            $original = trim( $item->title );
            if ( isset( $translations[ $original ] ) ) {
                $item->title = $translations[ $original ];
            }

            if ( ! empty( $item->attr_title ) ) {
                $attr = trim( $item->attr_title );
                if ( isset( $translations[ $attr ] ) ) {
                    $item->attr_title = $translations[ $attr ];
                }
            }
        }

        return $items;
    }
    add_filter( 'wp_nav_menu_objects', 'devents_translate_menu_items', 20 );
}


/* =====================================================================
   UPLOAD — NAZWA PLIKU
   ===================================================================== */

function devents_custom_event_filename( $filename, $ext, $dir, $cb_type ) {
    if ( ! isset( $GLOBALS['devents_custom_upload_context'] ) ) return $filename . $ext;
    $context      = $GLOBALS['devents_custom_upload_context'];
    $new_filename = $context['event_id'] . '_' . $context['user_id'] . '_wydarzenie_' . sanitize_title( $context['category'] );
    unset( $GLOBALS['devents_custom_upload_context'] );
    return $new_filename . $ext;
}


/* =====================================================================
   EMAIL — NADAWCA
   ===================================================================== */

/** Adres nadawcy wszystkich e-maili (domyślnie noreply@devents.pl, nadpisywalny opcją). */
function devents_get_sender_email() {
    return get_option( 'devents_noreply_email', 'noreply@devents.pl' );
}

/** Nazwa nadawcy wszystkich e-maili. */
function devents_get_sender_name() {
    $name = get_bloginfo( 'name' );
    return $name !== '' ? $name : 'DEvents';
}

function devents_custom_sender_email( $original ) {
    return devents_get_sender_email();
}
add_filter( 'wp_mail_from', 'devents_custom_sender_email' );

function devents_custom_sender_name( $original ) {
    return devents_get_sender_name();
}
add_filter( 'wp_mail_from_name', 'devents_custom_sender_name' );


/* =====================================================================
   USUWANIE INSTYTUCJI — kaskada (kadra, zaproszenia, wydarzenia, rekord)
   ===================================================================== */

/**
 * Trwale usuwa instytucję wraz ze sprzątaniem: odpina i degraduje kadrę do roli
 * „subscriber" (z powiadomieniem), kasuje zaproszenia, cofa weryfikację wydarzeń
 * zespołu i usuwa rekord instytucji. Używane przez panel admina ORAZ przy usuwaniu
 * konta przez właściciela instytucji.
 *
 * @param int $inst_id          ID instytucji.
 * @param int $exclude_user_id  Użytkownik pominięty w degradacji/powiadomieniu
 *                              (np. właściciel kasujący własne konto i tak zostanie usunięty).
 * @return bool                 true, gdy rekord instytucji usunięto.
 */
function devents_delete_institution_cascade( $inst_id, $exclude_user_id = 0 ) {
    global $wpdb;
    $inst_id = (int) $inst_id;
    if ( $inst_id <= 0 ) {
        return false;
    }

    $table_inst = $wpdb->prefix . 'devents_institutions';
    $inst = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, name FROM {$table_inst} WHERE id = %d", $inst_id ) );
    if ( ! $inst ) {
        return false;
    }
    $inst_name = $inst->name;

    // Jednostki podrzędne odpinamy od usuwanego mastera.
    if ( $inst->type === 'master' ) {
        $wpdb->update( $table_inst, [ 'parent_id' => 0 ], [ 'parent_id' => $inst_id ] );
    }

    $users_table = $wpdb->prefix . 'usermeta';
    $staff_ids   = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$users_table} WHERE meta_key = 'devents_institution_id' AND meta_value = %d",
        $inst_id
    ) );

    if ( ! empty( $staff_ids ) ) {
        $notif_table     = $wpdb->prefix . 'events_notifications';
        $has_notif_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$notif_table}'" ) == $notif_table );

        foreach ( $staff_ids as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id === (int) $exclude_user_id ) {
                continue; // pomijamy konto usuwane przez właściciela
            }
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                continue;
            }

            delete_user_meta( $user_id, 'devents_institution_id' );
            delete_user_meta( $user_id, 'devents_employer_id' );
            delete_user_meta( $user_id, 'devents_institution_role' );
            delete_user_meta( $user_id, 'devents_master_id' );
            delete_user_meta( $user_id, 'is_verified_organizer' );

            $first_name       = get_user_meta( $user_id, 'first_name', true );
            $last_name        = get_user_meta( $user_id, 'last_name', true );
            $new_display_name = trim( $first_name . ' ' . $last_name );
            if ( empty( $new_display_name ) ) {
                $new_display_name = 'Użytkownik (' . $user->user_login . ')';
            }

            wp_update_user( [
                'ID'           => $user_id,
                'role'         => 'subscriber',
                'display_name' => $new_display_name,
            ] );

            if ( $has_notif_table ) {
                $wpdb->insert( $notif_table, [
                    'user_id'    => $user_id,
                    'type'       => 'error',
                    'message'    => "Instytucja <strong>{$inst_name}</strong> została trwale usunięta z systemu. Twoje uprawnienia zostały zmienione na standardowego użytkownika.",
                    'is_read'    => 0,
                    'created_at' => current_time( 'mysql' ),
                ] );
            }

            if ( function_exists( 'devents_send_email' ) ) {
                devents_send_email(
                    $user->user_email,
                    'institution_deleted_notification',
                    [
                        'user_name'        => $new_display_name,
                        'institution_name' => $inst_name,
                        'panel_url'        => home_url( '/panel-uzytkownika/' ),
                    ]
                );
            }
        }
    }

    // Zaproszenia do zespołu.
    $table_invites = $wpdb->prefix . 'devents_team_invitations';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_invites}'" ) == $table_invites ) {
        $wpdb->delete( $table_invites, [ 'institution_id' => $inst_id ], [ '%d' ] );
    }

    // Wydarzenia zespołu — cofamy weryfikację (znikają z list, nie kasujemy treści).
    $events_table = $wpdb->prefix . 'events_list';
    if ( ! empty( $staff_ids ) && $wpdb->get_var( "SHOW TABLES LIKE '{$events_table}'" ) == $events_table ) {
        $users_in = implode( ',', array_map( 'intval', $staff_ids ) );
        $wpdb->query( "UPDATE {$events_table} SET verified = 0 WHERE user_id IN ($users_in)" );
    }

    return $wpdb->delete( $table_inst, [ 'id' => $inst_id ], [ '%d' ] ) !== false;
}


/* =====================================================================
   TEKST POWTARZALNOŚCI
   ===================================================================== */

function devents_get_recurrence_text( $event ) {
    if ( empty( $event->recurrence ) || $event->recurrence === 'none' ) return '';

    $map = [
        'daily'   => __( 'Codziennie', 'devents' ),
        'weekly'  => __( 'Co tydzień', 'devents' ),
        'monthly' => __( 'Co miesiąc', 'devents' ),
    ];

    $text = $map[ $event->recurrence ] ?? '';

    if ( ! empty( $event->recurrence_end_date ) && $event->recurrence_end_date !== '0000-00-00 00:00:00' ) {
        $text .= ' ' . __( 'do', 'devents' ) . ' ' . date_i18n( 'd.m.Y', strtotime( $event->recurrence_end_date ) );
    }

    return $text;
}


/* =====================================================================
   HELPERY MASTER / UNIT
   ===================================================================== */

function devents_get_master_id( $user_id ) {
    return (int) get_user_meta( $user_id, 'devents_master_id', true );
}

function devents_is_master_of( $master_id, $unit_id ) {
    $actual = devents_get_master_id( $unit_id );
    return ( $actual > 0 && $actual === (int) $master_id );
}

function devents_get_units_ids( $master_id ) {
    return get_users( [ 'meta_key' => 'devents_master_id', 'meta_value' => $master_id, 'fields' => 'ID' ] ) ?: [];
}

function devents_get_pending_units_ids( $master_id ) {
    return get_users( [ 'meta_key' => 'devents_pending_master_id', 'meta_value' => $master_id, 'fields' => 'ID' ] ) ?: [];
}


/* =====================================================================
   HELPERY INSTYTUCJI
   ===================================================================== */

function devents_get_user_institution_id( $user_id ) {
    return (int) get_user_meta( $user_id, 'devents_institution_id', true );
}

function devents_get_institution( $institution_id ) {
    global $wpdb;
    if ( ! $institution_id ) return null;

    $table_name = $wpdb->prefix . 'devents_institutions';
    $row        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $institution_id ), ARRAY_A );

    if ( $row ) {
        $row['meta'] = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : [];
        $row         = array_merge( $row, $row['meta'] );
    }

    return $row;
}

function devents_get_user_institution_data( $user_id ) {
    $inst_id = devents_get_user_institution_id( $user_id );
    return $inst_id ? devents_get_institution( $inst_id ) : null;
}


/* =====================================================================
   SŁOWNIKI ISO — JĘZYKI FONICZNE I MIGOWE
   ===================================================================== */
/**
 * Słownik języków fonicznych (ISO 639-1).
 * Zwraca tablicę z pełnymi danymi: name, abbr, locale, flag, status.
 */
if ( ! function_exists( 'devents_get_spoken_languages' ) ) {
    function devents_get_spoken_languages() {
        return [
            // --- CORE ---
            'pl' => [ 'name' => __( 'Polski', 'devents' ),      'abbr' => 'PL', 'locale' => 'pl_PL', 'flag' => '🇵🇱', 'status' => 'stable' ],
            'en' => [ 'name' => __( 'Angielski', 'devents' ),   'abbr' => 'EN', 'locale' => 'en_US', 'flag' => '🇺🇸', 'status' => 'beta' ],

            // --- DUŻE RYNKI WYDARZEŃ ---
            'de' => [ 'name' => __( 'Niemiecki', 'devents' ),   'abbr' => 'DE', 'locale' => 'de_DE', 'flag' => '🇩🇪', 'status' => 'beta' ],
            'es' => [ 'name' => __( 'Hiszpański', 'devents' ),  'abbr' => 'ES', 'locale' => 'es_ES', 'flag' => '🇪🇸', 'status' => 'planned' ],
            'fr' => [ 'name' => __( 'Francuski', 'devents' ),   'abbr' => 'FR', 'locale' => 'fr_FR', 'flag' => '🇫🇷', 'status' => 'planned' ],
            'it' => [ 'name' => __( 'Włoski', 'devents' ),      'abbr' => 'IT', 'locale' => 'it_IT', 'flag' => '🇮🇹', 'status' => 'planned' ],
            'pt' => [ 'name' => __( 'Portugalski', 'devents' ), 'abbr' => 'PT', 'locale' => 'pt_PT', 'flag' => '🇵🇹', 'status' => 'planned' ],

            // --- EUROPA PÓŁNOCNA I ZACHODNIA ---
            'nl' => [ 'name' => __( 'Niderlandzki', 'devents' ),'abbr' => 'NL', 'locale' => 'nl_NL', 'flag' => '🇳🇱', 'status' => 'planned' ],
            'sv' => [ 'name' => __( 'Szwedzki', 'devents' ),    'abbr' => 'SV', 'locale' => 'sv_SE', 'flag' => '🇸🇪', 'status' => 'planned' ],
            'no' => [ 'name' => __( 'Norweski', 'devents' ),    'abbr' => 'NO', 'locale' => 'nb_NO', 'flag' => '🇳🇴', 'status' => 'planned' ],
            'da' => [ 'name' => __( 'Duński', 'devents' ),      'abbr' => 'DA', 'locale' => 'da_DK', 'flag' => '🇩🇰', 'status' => 'planned' ],

            // --- EUROPA ŚRODKOWA I WSCHODNIA ---
            'uk' => [ 'name' => __( 'Ukraiński', 'devents' ),   'abbr' => 'UK', 'locale' => 'uk_UA', 'flag' => '🇺🇦', 'status' => 'planned' ],
            'cs' => [ 'name' => __( 'Czeski', 'devents' ),      'abbr' => 'CS', 'locale' => 'cs_CZ', 'flag' => '🇨🇿', 'status' => 'planned' ],
            'sk' => [ 'name' => __( 'Słowacki', 'devents' ),    'abbr' => 'SK', 'locale' => 'sk_SK', 'flag' => '🇸🇰', 'status' => 'planned' ],
            'hu' => [ 'name' => __( 'Węgierski', 'devents' ),   'abbr' => 'HU', 'locale' => 'hu_HU', 'flag' => '🇭🇺', 'status' => 'planned' ],
            'ru' => [ 'name' => __( 'Rosyjski', 'devents' ),    'abbr' => 'RU', 'locale' => 'ru_RU', 'flag' => '🇷🇺', 'status' => 'planned' ],

            // --- GLOBAL EXPANSION ---
            'ja' => [ 'name' => __( 'Japoński', 'devents' ),    'abbr' => 'JA', 'locale' => 'ja_JP', 'flag' => '🇯🇵', 'status' => 'planned' ],
            'ko' => [ 'name' => __( 'Koreański', 'devents' ),   'abbr' => 'KO', 'locale' => 'ko_KR', 'flag' => '🇰🇷', 'status' => 'planned' ],
            'zh' => [ 'name' => __( 'Chiński', 'devents' ),     'abbr' => 'ZH', 'locale' => 'zh_CN', 'flag' => '🇨🇳', 'status' => 'planned' ],
            'ar' => [ 'name' => __( 'Arabski', 'devents' ),     'abbr' => 'AR', 'locale' => 'ar_SA', 'flag' => '🇸🇦', 'status' => 'planned' ],
        ];
    }
}

/**
 * Słownik języków migowych (ISO 639-3 + systemy lokalne).
 * Zwraca tablicę: kod => ['name' => 'Nazwa', 'abbr' => 'KOD']
 */
if ( ! function_exists( 'devents_get_sign_languages' ) ) {
    function devents_get_sign_languages() {
        return [
            'ads' => ['name' => __('Adamorobe (Język migowy)', 'devents'), 'abbr' => 'ADS'],
            'aed' => ['name' => __('Argentyński język migowy', 'devents'), 'abbr' => 'AED'],
            'aen' => ['name' => __('Ormiański język migowy', 'devents'), 'abbr' => 'AEN'],
            'afg' => ['name' => __('Afgański język migowy', 'devents'), 'abbr' => 'AFG'],
            'ajs' => ['name' => __('Algierski żydowski język migowy', 'devents'), 'abbr' => 'AJS'],

            'ase' => ['name' => __('Amerykański język migowy', 'devents'), 'abbr' => 'ASL'],
            'asf' => ['name' => __('Australijski język migowy (Auslan)', 'devents'), 'abbr' => 'AUSLAN'],
            'asp' => ['name' => __('Algierski język migowy', 'devents'), 'abbr' => 'ASP'],
            'asq' => ['name' => __('Austriacki język migowy', 'devents'), 'abbr' => 'ÖGS'],
            'asw' => ['name' => __('Język migowy aborygenów australijskich', 'devents'), 'abbr' => 'ASW'],

            'bfi' => ['name' => __('Brytyjski język migowy', 'devents'), 'abbr' => 'BSL'],
            'bfk' => ['name' => __('Język migowy Ban Khor', 'devents'), 'abbr' => 'BFK'],
            'bog' => ['name' => __('Język migowy Bamako', 'devents'), 'abbr' => 'BOG'],
            'bqn' => ['name' => __('Bułgarski język migowy', 'devents'), 'abbr' => 'BGSL'],
            'bqy' => ['name' => __('Język migowy Bengkala', 'devents'), 'abbr' => 'BQY'],
            'bvl' => ['name' => __('Boliwijski język migowy', 'devents'), 'abbr' => 'BLSL'],
            'bzs' => ['name' => __('Brazylijski język migowy', 'devents'), 'abbr' => 'LIBRAS'],

            'csc' => ['name' => __('Kataloński język migowy', 'devents'), 'abbr' => 'LSC'],
            'cse' => ['name' => __('Czeski język migowy', 'devents'), 'abbr' => 'CZJ'],
            'csf' => ['name' => __('Kubański język migowy', 'devents'), 'abbr' => 'CSF'],
            'csg' => ['name' => __('Chilijski język migowy', 'devents'), 'abbr' => 'LSCH'],
            'csl' => ['name' => __('Chiński język migowy', 'devents'), 'abbr' => 'CSL'],
            'csn' => ['name' => __('Kolumbijski język migowy', 'devents'), 'abbr' => 'LSC'],
            'csq' => ['name' => __('Chorwacki język migowy', 'devents'), 'abbr' => 'HZJ'],
            'csr' => ['name' => __('Kostarykański język migowy', 'devents'), 'abbr' => 'LESCO'],
            'csx' => ['name' => __('Kambodżański język migowy', 'devents'), 'abbr' => 'CSX'],

            'dse' => ['name' => __('Holenderski język migowy', 'devents'), 'abbr' => 'NGT'],
            'dsl' => ['name' => __('Duński język migowy', 'devents'), 'abbr' => 'DTS'],

            'ecs' => ['name' => __('Ekwadorski język migowy', 'devents'), 'abbr' => 'LSEC'],
            'esl' => ['name' => __('Egipski język migowy', 'devents'), 'abbr' => 'ESL'],
            'eso' => ['name' => __('Estoński język migowy', 'devents'), 'abbr' => 'ESL'],
            'eth' => ['name' => __('Etiopski język migowy', 'devents'), 'abbr' => 'ETHSL'],

            'fcs' => ['name' => __('Język migowy Quebecu', 'devents'), 'abbr' => 'LSQ'],
            'fse' => ['name' => __('Fiński język migowy', 'devents'), 'abbr' => 'FSL'],
            'fsl' => ['name' => __('Francuski język migowy', 'devents'), 'abbr' => 'LSF'],
            'fss' => ['name' => __('Fińsko-szwedzki język migowy', 'devents'), 'abbr' => 'FSSL'],

            'gse' => ['name' => __('Ghański język migowy', 'devents'), 'abbr' => 'GSL'],
            'gsg' => ['name' => __('Niemiecki język migowy', 'devents'), 'abbr' => 'DGS'],
            'gsm' => ['name' => __('Gwatemalski język migowy', 'devents'), 'abbr' => 'GSM'],
            'gss' => ['name' => __('Grecki język migowy', 'devents'), 'abbr' => 'GSL'],

            'hks' => ['name' => __('Język migowy Hongkongu', 'devents'), 'abbr' => 'HKSL'],
            'hps' => ['name' => __('Hawajski język migowy', 'devents'), 'abbr' => 'HSL'],
            'hsh' => ['name' => __('Węgierski język migowy', 'devents'), 'abbr' => 'HSL'],

            'icl' => ['name' => __('Islandzki język migowy', 'devents'), 'abbr' => 'ÍTM'],
            'iks' => ['name' => __('Język migowy Inuitów', 'devents'), 'abbr' => 'IUR'],
            'ils' => ['name' => __('Międzynarodowy język migowy', 'devents'), 'abbr' => 'IS'],
            'ins' => ['name' => __('Indyjski język migowy', 'devents'), 'abbr' => 'ISL'],
            'ise' => ['name' => __('Włoski język migowy', 'devents'), 'abbr' => 'LIS'],
            'isg' => ['name' => __('Irlandzki język migowy', 'devents'), 'abbr' => 'ISL'],
            'isr' => ['name' => __('Izraelski język migowy', 'devents'), 'abbr' => 'ISL'],

            'jls' => ['name' => __('Jamajski język migowy', 'devents'), 'abbr' => 'JSL'],
            'jos' => ['name' => __('Jordański język migowy', 'devents'), 'abbr' => 'JSL'],
            'jsl' => ['name' => __('Japoński język migowy', 'devents'), 'abbr' => 'JSL'],

            'kvk' => ['name' => __('Koreański język migowy', 'devents'), 'abbr' => 'KSL'],

            'lls' => ['name' => __('Litewski język migowy', 'devents'), 'abbr' => 'LSL'],
            'lsl' => ['name' => __('Łotewski język migowy', 'devents'), 'abbr' => 'LTVSL'],

            'mdl' => ['name' => __('Maltański język migowy', 'devents'), 'abbr' => 'MSL'],
            'mfs' => ['name' => __('Meksykański język migowy', 'devents'), 'abbr' => 'LSM'],
            'msr' => ['name' => __('Mongolski język migowy', 'devents'), 'abbr' => 'MSL'],

            'ncs' => ['name' => __('Nikaraguański język migowy', 'devents'), 'abbr' => 'NSL'],
            'nsi' => ['name' => __('Nigeryjski język migowy', 'devents'), 'abbr' => 'NSL'],
            'nsl' => ['name' => __('Norweski język migowy', 'devents'), 'abbr' => 'NSL'],
            'nzs' => ['name' => __('Nowozelandzki język migowy', 'devents'), 'abbr' => 'NZSL'],

            'pso' => ['name' => __('Polski język migowy', 'devents'), 'abbr' => 'PJM'],
            'psp' => ['name' => __('Filipiński język migowy', 'devents'), 'abbr' => 'FSL'],
            'psr' => ['name' => __('Portugalski język migowy', 'devents'), 'abbr' => 'LGP'],

            'rsl' => ['name' => __('Rosyjski język migowy', 'devents'), 'abbr' => 'RSL'],
            'rms' => ['name' => __('Rumuński język migowy', 'devents'), 'abbr' => 'RSL'],

            'sdl' => ['name' => __('Saudyjski język migowy', 'devents'), 'abbr' => 'SASL'],
            'sfs' => ['name' => __('Południowoafrykański język migowy', 'devents'), 'abbr' => 'SASL'],
            'sgg' => ['name' => __('Szwajcarski język migowy (niemiecki)', 'devents'), 'abbr' => 'DSGS'],
            'ssp' => ['name' => __('Hiszpański język migowy', 'devents'), 'abbr' => 'LSE'],
            'svk' => ['name' => __('Słowacki język migowy', 'devents'), 'abbr' => 'SSZJ'],
            'swl' => ['name' => __('Szwedzki język migowy', 'devents'), 'abbr' => 'SSL'],

            'tsm' => ['name' => __('Turecki język migowy', 'devents'), 'abbr' => 'TİD'],
            'tsq' => ['name' => __('Tajski język migowy', 'devents'), 'abbr' => 'TSL'],
            'tss' => ['name' => __('Tajwański język migowy', 'devents'), 'abbr' => 'TSL'],

            'ukl' => ['name' => __('Ukraiński język migowy', 'devents'), 'abbr' => 'USL'],

            'vgt' => ['name' => __('Flamandzki język migowy', 'devents'), 'abbr' => 'VGT'],
            'vsl' => ['name' => __('Wenezuelski język migowy', 'devents'), 'abbr' => 'LSV'],

            'xki' => ['name' => __('Kenijski język migowy', 'devents'), 'abbr' => 'KSL'],
            'xml' => ['name' => __('Malezyjski język migowy', 'devents'), 'abbr' => 'BIM'],

            'ysm' => ['name' => __('Birmański język migowy', 'devents'), 'abbr' => 'MSL'],

            'zib' => ['name' => __('Zimbabwiański język migowy', 'devents'), 'abbr' => 'ZSL'],
            'zsl' => ['name' => __('Zambijski język migowy', 'devents'), 'abbr' => 'ZSL'],
        ];
    }
}

/**
 * Mapuje surowe nazwy z bazy danych na tłumaczenia i18n.
 * Dzięki temu edycja w DB nie psuje tłumaczeń, a my mamy kontrolę nad stringami.
 */
function devents_get_acc_label( $slug, $fallback_name = '' ) {
    $labels = [
        'audiodescription'     => __( 'Audiodeskrypcja', 'devents' ),
        'audio_guide'          => __( 'Audioprzewodnik', 'devents' ),
        'quiet_hours'          => __( 'Ciche godziny', 'devents' ),
        'film_guide'           => __( 'Filmowy przewodnik (tablet)', 'devents' ),
        'comfort_room'         => __( 'Komfortka', 'devents' ),
        'lector'               => __( 'Lektor', 'devents' ),
        'etr'                  => __( 'Łatwy tekst (ETR)', 'devents' ),
        'quiet_space'          => __( 'Miejsce wyciszenia', 'devents' ),
        'captions_live'        => __( 'Napisy na żywo', 'devents' ),
        'captions_deaf'        => __( 'Napisy dla niesłyszących', 'devents' ),
        'captions_standard'    => __( 'Napisy standardowe', 'devents' ),
        'noise_headphones'     => __( 'Słuchawki wyciszające', 'devents' ),
        'induction_loop'       => __( 'Pętla indukcyjna', 'devents' ),
        'pjm_led'              => __( 'W języku migowym', 'devents' ),
        'preguide'             => __( 'Przedprzewodnik', 'devents' ),
        'fm_system'            => __( 'Przenośne systemy FM', 'devents' ),
        'interpretation'       => __( 'Tłumaczenie na język migowy', 'devents' ),
        'spoken_translation'   => __( 'Tłumaczenie na język foniczny', 'devents' ),
        'tactile_materials'    => __( 'Tyflografika i materiały dotykowe', 'devents' ),
        'autism_spectrum'      => __( 'Wsparcie dla osób w spektrum autyzmu', 'devents' ),
        'intellectual_disability' => __( 'Wsparcie dla osób z niepełnosprawnością intelektualną', 'devents' ),
        'other'                => __( 'Inna forma wsparcia', 'devents' ),
    ];

    return $labels[ $slug ] ?? ( !empty($fallback_name) ? $fallback_name : $slug );
}

/**
 * Globalne tłumaczenie pól wydarzenia wg bieżącego języka.
 * JEDNO miejsce dla wszystkich list kart (strona główna, wyszukiwarka, ulubione,
 * subskrypcje, moje wydarzenia, profil organizatora) — koniec z duplikowaniem
 * logiki tłumaczeń na każdej stronie. Mutuje i zwraca obiekt $event.
 */
if ( ! function_exists( 'devents_apply_event_translation' ) ) {
    function devents_apply_event_translation( $event ) {
        if ( empty( $event ) || ! is_object( $event ) ) return $event;

        $current_lang = function_exists( 'determine_locale' ) ? substr( determine_locale(), 0, 2 ) : 'pl';
        if ( $current_lang === 'pl' || empty( $event->translations ) ) return $event;

        // translations bywa już tablicą (ścieżka formularza) lub stringiem JSON (z bazy).
        $trans = is_array( $event->translations ) ? $event->translations : json_decode( $event->translations, true );
        if ( empty( $trans ) || ! isset( $trans[ $current_lang ] ) ) return $event;

        $t = $trans[ $current_lang ];
        if ( ! empty( $t['title'] ) )              $event->title              = $t['title'];
        if ( ! empty( $t['description'] ) )        $event->description        = $t['description'];
        if ( ! empty( $t['address_name'] ) )       $event->address_name       = $t['address_name'];
        if ( ! empty( $t['address_city'] ) )       $event->address_city       = $t['address_city'];
        if ( ! empty( $t['location_info'] ) )      $event->location_info      = $t['location_info'];
        if ( ! empty( $t['image_alt_text'] ) )     $event->image_alt_text     = $t['image_alt_text'];
        if ( ! empty( $t['action_button_text'] ) ) $event->action_button_text = $t['action_button_text'];

        // Podmiana nazw biletów w JSON ticket_types.
        if ( ! empty( $t['tickets_names'] ) && ! empty( $event->ticket_types ) ) {
            $tickets_array = json_decode( $event->ticket_types, true );
            if ( is_array( $tickets_array ) ) {
                foreach ( $tickets_array as $idx => &$ticket_item ) {
                    if ( ! empty( $t['tickets_names'][ $idx ] ) ) {
                        $ticket_item['name'] = $t['tickets_names'][ $idx ];
                    }
                }
                unset( $ticket_item );
                $event->ticket_types = wp_json_encode( $tickets_array, JSON_UNESCAPED_UNICODE );
            }
        }

        return $event;
    }
}

/**
 * Zwraca listę kategorii (slug => nazwa)
 * Używane w selectach formularza
 */
function devents_get_event_categories() {
    return [
        'film'          => __( 'Film', 'devents' ),
        'concert'       => __( 'Koncert', 'devents' ),
        'conference'    => __( 'Konferencja', 'devents' ),
        'performance'   => __( 'Performens', 'devents' ),
        'walk'          => __( 'Spacer', 'devents' ),
        'spectacle'     => __( 'Spektakl', 'devents' ),
        'meeting'       => __( 'Spotkanie', 'devents' ),
        'workshop'      => __( 'Warsztaty', 'devents' ),
        'lecture'       => __( 'Wykład', 'devents' ),
        'exhibition'    => __( 'Wystawa', 'devents' ),
        'sightseeing'   => __( 'Zwiedzanie', 'devents' ),
        'sport'         => __( 'Sport', 'devents' ),
        'opening'       => __( 'Wernisaż/Finisaż', 'devents' ),
        'festival'      => __( 'Festiwal', 'devents' ),
        'family'        => __( 'Rodzinne', 'devents' ),
        'fair'          => __( 'Targi', 'devents' ),
        'course'        => __( 'Kurs', 'devents' ),
        'other'         => __( 'Inne', 'devents' ),
    ];
}

/**
 * Zaktualizowana funkcja metod realizacji
 */
function devents_get_event_methods() {
    return [
        'live'     => __( 'Na żywo', 'devents' ),
        'hybrid'   => __( 'Hybrydowo', 'devents' ),
        'online'   => __( 'Online', 'devents' ),
    ];
}

/**
 * Pobiera slug z bazy i zwraca przetłumaczoną etykietę.
 */
function devents_get_method_label( $slug ) {
    $methods = devents_get_event_methods();
    return isset( $methods[ $slug ] ) ? $methods[ $slug ] : $slug;
}

/**
 * Helper do pobierania nazwy na podstawie sluga (do widoku Single Event)
 */
function devents_get_category_label( $slug ) {
    $categories = devents_get_event_categories();
    return $categories[ $slug ] ?? $slug;
}


if ( ! function_exists( 'devents_get_countries' ) ) {
    /**
     * Zwraca listę krajów (kod ISO => zlokalizowana nazwa).
     * * @return array
     */
    function devents_get_countries() {
        return [
            'AF' => __( 'Afganistan', 'devents' ),
            'AL' => __( 'Albania', 'devents' ),
            'DZ' => __( 'Algieria', 'devents' ),
            'AD' => __( 'Andora', 'devents' ),
            'AO' => __( 'Angola', 'devents' ),
            'AI' => __( 'Anguilla', 'devents' ),
            'AQ' => __( 'Antarktyda', 'devents' ),
            'AG' => __( 'Antigua i Barbuda', 'devents' ),
            'SA' => __( 'Arabia Saudyjska', 'devents' ),
            'AR' => __( 'Argentyna', 'devents' ),
            'AM' => __( 'Armenia', 'devents' ),
            'AW' => __( 'Aruba', 'devents' ),
            'AU' => __( 'Australia', 'devents' ),
            'AT' => __( 'Austria', 'devents' ),
            'AZ' => __( 'Azerbejdżan', 'devents' ),
            'BS' => __( 'Bahamy', 'devents' ),
            'BH' => __( 'Bahrajn', 'devents' ),
            'BD' => __( 'Bangladesz', 'devents' ),
            'BB' => __( 'Barbados', 'devents' ),
            'BE' => __( 'Belgia', 'devents' ),
            'BZ' => __( 'Belize', 'devents' ),
            'BJ' => __( 'Benin', 'devents' ),
            'BM' => __( 'Bermudy', 'devents' ),
            'BT' => __( 'Bhutan', 'devents' ),
            'BY' => __( 'Białoruś', 'devents' ),
            'MM' => __( 'Birma', 'devents' ),
            'BO' => __( 'Boliwia', 'devents' ),
            'BQ' => __( 'Bonaire, Sint Eustatius i Saba', 'devents' ),
            'BA' => __( 'Bośnia i Hercegowina', 'devents' ),
            'BW' => __( 'Botswana', 'devents' ),
            'BR' => __( 'Brazylia', 'devents' ),
            'BN' => __( 'Brunei', 'devents' ),
            'IO' => __( 'Brytyjskie Terytorium Oceanu Indyjskiego', 'devents' ),
            'VG' => __( 'Brytyjskie Wyspy Dziewicze', 'devents' ),
            'BG' => __( 'Bułgaria', 'devents' ),
            'BF' => __( 'Burkina Faso', 'devents' ),
            'BI' => __( 'Burundi', 'devents' ),
            'CL' => __( 'Chile', 'devents' ),
            'CN' => __( 'Chiny', 'devents' ),
            'HR' => __( 'Chorwacja', 'devents' ),
            'CW' => __( 'Curaçao', 'devents' ),
            'CY' => __( 'Cypr', 'devents' ),
            'TD' => __( 'Czad', 'devents' ),
            'ME' => __( 'Czarnogóra', 'devents' ),
            'CZ' => __( 'Czechy', 'devents' ),
            'UM' => __( 'Dalekie Wyspy Mniejsze Stanów Zjednoczonych', 'devents' ),
            'DK' => __( 'Dania', 'devents' ),
            'CD' => __( 'Demokratyczna Republika Konga', 'devents' ),
            'DM' => __( 'Dominika', 'devents' ),
            'DO' => __( 'Dominikana', 'devents' ),
            'DJ' => __( 'Dżibuti', 'devents' ),
            'EG' => __( 'Egipt', 'devents' ),
            'EC' => __( 'Ekwador', 'devents' ),
            'ER' => __( 'Erytrea', 'devents' ),
            'EE' => __( 'Estonia', 'devents' ),
            'ET' => __( 'Etiopia', 'devents' ),
            'FK' => __( 'Falklandy', 'devents' ),
            'FJ' => __( 'Fidżi', 'devents' ),
            'PH' => __( 'Filipiny', 'devents' ),
            'FI' => __( 'Finlandia', 'devents' ),
            'FR' => __( 'Francja', 'devents' ),
            'TF' => __( 'Francuskie Terytoria Południowe i Antarktyczne', 'devents' ),
            'GA' => __( 'Gabon', 'devents' ),
            'GM' => __( 'Gambia', 'devents' ),
            'GS' => __( 'Georgia Południowa i Sandwich Południowy', 'devents' ),
            'GH' => __( 'Ghana', 'devents' ),
            'GI' => __( 'Gibraltar', 'devents' ),
            'GR' => __( 'Grecja', 'devents' ),
            'GD' => __( 'Grenada', 'devents' ),
            'GL' => __( 'Grenlandia', 'devents' ),
            'GE' => __( 'Gruzja', 'devents' ),
            'GU' => __( 'Guam', 'devents' ),
            'GG' => __( 'Guernsey', 'devents' ),
            'GF' => __( 'Gujana Francuska', 'devents' ),
            'GY' => __( 'Gujana', 'devents' ),
            'GP' => __( 'Gwadelupa', 'devents' ),
            'GT' => __( 'Gwatemala', 'devents' ),
            'GW' => __( 'Gwinea Bissau', 'devents' ),
            'GQ' => __( 'Gwinea Równikowa', 'devents' ),
            'GN' => __( 'Gwinea', 'devents' ),
            'HT' => __( 'Haiti', 'devents' ),
            'ES' => __( 'Hiszpania', 'devents' ),
            'NL' => __( 'Holandia', 'devents' ),
            'HN' => __( 'Honduras', 'devents' ),
            'HK' => __( 'Hongkong', 'devents' ),
            'IN' => __( 'Indie', 'devents' ),
            'ID' => __( 'Indonezja', 'devents' ),
            'IQ' => __( 'Irak', 'devents' ),
            'IR' => __( 'Iran', 'devents' ),
            'IE' => __( 'Irlandia', 'devents' ),
            'IS' => __( 'Islandia', 'devents' ),
            'IL' => __( 'Izrael', 'devents' ),
            'JM' => __( 'Jamajka', 'devents' ),
            'JP' => __( 'Japonia', 'devents' ),
            'YE' => __( 'Jemen', 'devents' ),
            'JE' => __( 'Jersey', 'devents' ),
            'JO' => __( 'Jordania', 'devents' ),
            'KY' => __( 'Kajmany', 'devents' ),
            'KH' => __( 'Kambodża', 'devents' ),
            'CM' => __( 'Kamerun', 'devents' ),
            'CA' => __( 'Kanada', 'devents' ),
            'QA' => __( 'Katar', 'devents' ),
            'KZ' => __( 'Kazachstan', 'devents' ),
            'KE' => __( 'Kenia', 'devents' ),
            'KG' => __( 'Kirgistan', 'devents' ),
            'KI' => __( 'Kiribati', 'devents' ),
            'CO' => __( 'Kolumbia', 'devents' ),
            'KM' => __( 'Komory', 'devents' ),
            'CG' => __( 'Kongo', 'devents' ),
            'KR' => __( 'Korea Południowa', 'devents' ),
            'KP' => __( 'Korea Północna', 'devents' ),
            'CR' => __( 'Kostaryka', 'devents' ),
            'CU' => __( 'Kuba', 'devents' ),
            'KW' => __( 'Kuwejt', 'devents' ),
            'LA' => __( 'Laos', 'devents' ),
            'LS' => __( 'Lesotho', 'devents' ),
            'LB' => __( 'Liban', 'devents' ),
            'LR' => __( 'Liberia', 'devents' ),
            'LY' => __( 'Libia', 'devents' ),
            'LI' => __( 'Liechtenstein', 'devents' ),
            'LT' => __( 'Litwa', 'devents' ),
            'LU' => __( 'Luksemburg', 'devents' ),
            'LV' => __( 'Łotwa', 'devents' ),
            'MK' => __( 'Macedonia Północna', 'devents' ), // Zaktualizowane do obecnej nazwy (wcześniej Macedonia)
            'MG' => __( 'Madagaskar', 'devents' ),
            'YT' => __( 'Majotta', 'devents' ),
            'MO' => __( 'Makau', 'devents' ),
            'MW' => __( 'Malawi', 'devents' ),
            'MV' => __( 'Malediwy', 'devents' ),
            'MY' => __( 'Malezja', 'devents' ),
            'ML' => __( 'Mali', 'devents' ),
            'MT' => __( 'Malta', 'devents' ),
            'MP' => __( 'Mariany Północne', 'devents' ),
            'MA' => __( 'Maroko', 'devents' ),
            'MQ' => __( 'Martynika', 'devents' ),
            'MR' => __( 'Mauretania', 'devents' ),
            'MU' => __( 'Mauritius', 'devents' ),
            'MX' => __( 'Meksyk', 'devents' ),
            'FM' => __( 'Mikronezja', 'devents' ),
            'MD' => __( 'Mołdawia', 'devents' ),
            'MC' => __( 'Monako', 'devents' ),
            'MN' => __( 'Mongolia', 'devents' ),
            'MS' => __( 'Montserrat', 'devents' ),
            'MZ' => __( 'Mozambik', 'devents' ),
            'NA' => __( 'Namibia', 'devents' ),
            'NR' => __( 'Nauru', 'devents' ),
            'NP' => __( 'Nepal', 'devents' ),
            'DE' => __( 'Niemcy', 'devents' ),
            'NE' => __( 'Niger', 'devents' ),
            'NG' => __( 'Nigeria', 'devents' ),
            'NI' => __( 'Nikaragua', 'devents' ),
            'NU' => __( 'Niue', 'devents' ),
            'NF' => __( 'Norfolk', 'devents' ),
            'NO' => __( 'Norwegia', 'devents' ),
            'NC' => __( 'Nowa Kaledonia', 'devents' ),
            'NZ' => __( 'Nowa Zelandia', 'devents' ),
            'OM' => __( 'Oman', 'devents' ),
            'PK' => __( 'Pakistan', 'devents' ),
            'PW' => __( 'Palau', 'devents' ),
            'PS' => __( 'Palestyna', 'devents' ),
            'PA' => __( 'Panama', 'devents' ),
            'PG' => __( 'Papua-Nowa Gwinea', 'devents' ),
            'PY' => __( 'Paragwaj', 'devents' ),
            'PE' => __( 'Peru', 'devents' ),
            'PN' => __( 'Pitcairn', 'devents' ),
            'PF' => __( 'Polinezja Francuska', 'devents' ),
            'PL' => __( 'Polska', 'devents' ),
            'PR' => __( 'Portoryko', 'devents' ),
            'PT' => __( 'Portugalia', 'devents' ),
            'ZA' => __( 'Republika Południowej Afryki', 'devents' ),
            'CF' => __( 'Republika Środkowoafrykańska', 'devents' ),
            'CV' => __( 'Republika Zielonego Przylądka', 'devents' ),
            'RE' => __( 'Reunion', 'devents' ),
            'RU' => __( 'Rosja', 'devents' ),
            'RO' => __( 'Rumunia', 'devents' ),
            'RW' => __( 'Rwanda', 'devents' ),
            'EH' => __( 'Sahara Zachodnia', 'devents' ),
            'KN' => __( 'Saint Kitts i Nevis', 'devents' ),
            'LC' => __( 'Saint Lucia', 'devents' ),
            'VC' => __( 'Saint Vincent i Grenadyny', 'devents' ),
            'BL' => __( 'Saint-Barthélemy', 'devents' ),
            'MF' => __( 'Saint-Martin', 'devents' ),
            'PM' => __( 'Saint-Pierre i Miquelon', 'devents' ),
            'SV' => __( 'Salwador', 'devents' ),
            'AS' => __( 'Samoa Amerykańskie', 'devents' ),
            'WS' => __( 'Samoa', 'devents' ),
            'SM' => __( 'San Marino', 'devents' ),
            'SN' => __( 'Senegal', 'devents' ),
            'RS' => __( 'Serbia', 'devents' ),
            'SC' => __( 'Seszele', 'devents' ),
            'SL' => __( 'Sierra Leone', 'devents' ),
            'SG' => __( 'Singapur', 'devents' ),
            'SX' => __( 'Sint Maarten', 'devents' ),
            'SK' => __( 'Słowacja', 'devents' ),
            'SI' => __( 'Słowenia', 'devents' ),
            'SO' => __( 'Somalia', 'devents' ),
            'LK' => __( 'Sri Lanka', 'devents' ),
            'US' => __( 'Stany Zjednoczone', 'devents' ),
            'SZ' => __( 'Suazi', 'devents' ),
            'SD' => __( 'Sudan', 'devents' ),
            'SR' => __( 'Surinam', 'devents' ),
            'SJ' => __( 'Svalbard i Jan Mayen', 'devents' ),
            'SY' => __( 'Syria', 'devents' ),
            'CH' => __( 'Szwajcaria', 'devents' ),
            'SE' => __( 'Szwecja', 'devents' ),
            'TJ' => __( 'Tadżykistan', 'devents' ),
            'TH' => __( 'Tajlandia', 'devents' ),
            'TW' => __( 'Tajwan', 'devents' ),
            'TZ' => __( 'Tanzania', 'devents' ),
            'TL' => __( 'Timor Wschodni', 'devents' ),
            'TG' => __( 'Togo', 'devents' ),
            'TK' => __( 'Tokelau', 'devents' ),
            'TO' => __( 'Tonga', 'devents' ),
            'TT' => __( 'Trynidad i Tobago', 'devents' ),
            'TN' => __( 'Tunezja', 'devents' ),
            'TR' => __( 'Turcja', 'devents' ),
            'TM' => __( 'Turkmenistan', 'devents' ),
            'TC' => __( 'Turks i Caicos', 'devents' ),
            'TV' => __( 'Tuvalu', 'devents' ),
            'UG' => __( 'Uganda', 'devents' ),
            'UA' => __( 'Ukraina', 'devents' ),
            'UY' => __( 'Urugwaj', 'devents' ),
            'UZ' => __( 'Uzbekistan', 'devents' ),
            'VU' => __( 'Vanuatu', 'devents' ),
            'WF' => __( 'Wallis i Futuna', 'devents' ),
            'VA' => __( 'Watykan', 'devents' ),
            'VE' => __( 'Wenezuela', 'devents' ),
            'HU' => __( 'Węgry', 'devents' ),
            'GB' => __( 'Wielka Brytania', 'devents' ),
            'VN' => __( 'Wietnam', 'devents' ),
            'IT' => __( 'Włochy', 'devents' ),
            'CI' => __( 'Wybrzeże Kości Słoniowej', 'devents' ),
            'BV' => __( 'Wyspa Bouveta', 'devents' ),
            'CX' => __( 'Wyspa Bożego Narodzenia', 'devents' ),
            'IM' => __( 'Wyspa Man', 'devents' ),
            'SH' => __( 'Wyspa Świętej Heleny, Wyspa Wniebowstąpienia i Tristan da Cunha', 'devents' ),
            'AX' => __( 'Wyspy Alandzkie', 'devents' ),
            'CK' => __( 'Wyspy Cooka', 'devents' ),
            'VI' => __( 'Wyspy Dziewicze Stanów Zjednoczonych', 'devents' ),
            'HM' => __( 'Wyspy Heard i McDonalda', 'devents' ),
            'CC' => __( 'Wyspy Kokosowe', 'devents' ),
            'MH' => __( 'Wyspy Marshalla', 'devents' ),
            'FO' => __( 'Wyspy Owcze', 'devents' ),
            'SB' => __( 'Wyspy Salomona', 'devents' ),
            'ST' => __( 'Wyspy Świętego Tomasza i Książęca', 'devents' ),
            'ZM' => __( 'Zambia', 'devents' ),
            'ZW' => __( 'Zimbabwe', 'devents' ),
            'AE' => __( 'Zjednoczone Emiraty Arabskie', 'devents' ),
        ];
    }
}

/**
 * Helper: wyciąga 'name' ze słownika języka.
 * Obsługuje oba formaty: stary (string) i nowy (array z 'name'+'abbr').
 */
function devents_get_lang_name( $lang_entry ) {
    if ( is_array( $lang_entry ) && isset( $lang_entry['name'] ) ) {
        return $lang_entry['name'];
    }
    return is_string( $lang_entry ) ? $lang_entry : '';
}

/**
 * Helper: wyciąga 'abbr' ze słownika języka.
 */
function devents_get_lang_abbr( $lang_entry ) {
    if ( is_array( $lang_entry ) && isset( $lang_entry['abbr'] ) ) {
        return $lang_entry['abbr'];
    }
    return is_string( $lang_entry ) ? strtoupper( $lang_entry ) : '';
}


/* =====================================================================
   DEKODER DOSTĘPNOŚCI (JSON + legacy fallback)
   ===================================================================== */

/**
 * Inteligentny dekoder dostępności.
 * Format JSON: {"14": ["pso", "ils"], "3": ["pl"], "7": []}
 * Stary format: "Audiodeskrypcja, Lektor" lub "1, 14"
 */
if ( ! function_exists( 'devents_format_accessibility_data' ) ) {
    function devents_format_accessibility_data( $json_or_string ) {
        if ( empty( $json_or_string ) ) return [];

        $decoded = json_decode( $json_or_string, true );

        // FALLBACK: stary format CSV
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            $raw_items     = array_map( 'trim', explode( ',', $json_or_string ) );
            $legacy_result = [];
            foreach ( $raw_items as $item ) {
                if ( empty( $item ) ) continue;
                $legacy_result[] = [
                    'id'        => null,
                    'name'      => $item,
                    'type'      => 'none',
                    'languages' => [],
                ];
            }
            return $legacy_result;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'events_settings';

        $column_check  = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'language_type'" );
        $has_lang_type = ! empty( $column_check );

        $query = "SELECT id, setting_value";
        if ( $has_lang_type ) $query .= ", language_type";
        $query .= " FROM {$table} WHERE setting_type IN ('event_accessibility', 'video_accessibility')";

        $dict     = $wpdb->get_results( $query, ARRAY_A );
        $dict_map = [];
        if ( $dict ) {
            foreach ( $dict as $row ) {
                $dict_map[ $row['id'] ] = $row;
            }
        }

        $spoken_langs = devents_get_spoken_languages();
        $sign_langs   = devents_get_sign_languages();
        $formatted    = [];

        foreach ( $decoded as $acc_id => $langs ) {
            if ( ! isset( $dict_map[ $acc_id ] ) ) continue;

            $acc_name  = $dict_map[ $acc_id ]['setting_value'];
            $lang_type = $has_lang_type && isset( $dict_map[ $acc_id ]['language_type'] )
                ? $dict_map[ $acc_id ]['language_type'] : 'none';

            $lang_names = [];
            if ( is_array( $langs ) && ! empty( $langs ) ) {
                foreach ( $langs as $lang_code ) {
                    if ( $lang_type === 'spoken' && isset( $spoken_langs[ $lang_code ] ) ) {
                        $lang_names[] = devents_get_lang_name( $spoken_langs[ $lang_code ] );
                    } elseif ( $lang_type === 'sign' && isset( $sign_langs[ $lang_code ] ) ) {
                        $lang_names[] = devents_get_lang_name( $sign_langs[ $lang_code ] );
                    } else {
                        $lang_names[] = strtoupper( $lang_code );
                    }
                }
            }

            $formatted[] = [
                'id'        => $acc_id,
                'name'      => $acc_name,
                'type'      => $lang_type,
                'languages' => $lang_names,
            ];
        }

        return $formatted;
    }
}

if ( ! function_exists( 'devents_get_order_prefill' ) ) {
    /**
     * Dane do automatycznego wypełnienia formularza zamówienia (modal):
     * dane zamawiającego (z konta) + dane instytucji (do faktury VAT).
     * Używane przez JS (devents_config.order_prefill) w modalach wyróżnienia,
     * PJM i raportu. Zwraca płaską tablicę gotową do localize.
     */
    function devents_get_order_prefill( $user_id ) {
        $user_id = (int) $user_id;
        $u       = $user_id ? get_userdata( $user_id ) : null;

        $prefill = array(
            'name'         => $u ? $u->display_name : '',
            'email'        => $u ? $u->user_email : '',
            'street'       => '',
            'house_no'     => '',
            'flat_no'      => '',
            'zip'          => '',
            'city'         => '',
            'want_invoice' => false,
            'inst_name'    => '',
            'inst_nip'     => '',
            'inst_street'  => '',
            'inst_house_no'=> '',
            'inst_flat_no' => '',
            'inst_zip'     => '',
            'inst_city'    => '',
        );

        if ( ! $user_id ) {
            return $prefill;
        }

        // Adres zamawiającego z meta (jeśli kiedykolwiek zapisany).
        $meta_map = array(
            'street'   => 'devents_street',
            'house_no' => 'devents_house_no',
            'flat_no'  => 'devents_flat_no',
            'zip'      => 'devents_zip',
            'city'     => 'devents_city',
        );
        foreach ( $meta_map as $key => $meta_key ) {
            $val = get_user_meta( $user_id, $meta_key, true );
            if ( $val !== '' && $val !== false ) {
                $prefill[ $key ] = (string) $val;
            }
        }

        // Dane instytucji (nazwa + NIP + adres z metadata JSON) do sekcji „Nabywca".
        $inst_id = (int) get_user_meta( $user_id, 'devents_institution_id', true );
        if ( $inst_id > 0 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, metadata FROM {$wpdb->prefix}devents_institutions WHERE id = %d",
                $inst_id
            ) );
            if ( $row ) {
                $prefill['inst_name'] = (string) $row->name;
                $meta = $row->metadata ? json_decode( $row->metadata, true ) : array();
                if ( is_array( $meta ) ) {
                    $prefill['inst_nip'] = (string) ( $meta['nip'] ?? '' );
                    $addr = ( isset( $meta['address_data'] ) && is_array( $meta['address_data'] ) ) ? $meta['address_data'] : array();
                    $prefill['inst_street']   = (string) ( $addr['street'] ?? '' );
                    $prefill['inst_house_no'] = (string) ( $addr['house_number'] ?? '' );
                    $prefill['inst_zip']      = (string) ( $addr['zip_code'] ?? '' );
                    $prefill['inst_city']     = (string) ( $addr['city'] ?? '' );
                }
                // Gdy instytucja ma NIP — domyślnie proponujemy fakturę VAT na jej dane.
                if ( $prefill['inst_nip'] !== '' ) {
                    $prefill['want_invoice'] = true;
                }
                // Jeśli zamawiający nie ma własnego adresu, użyj adresu instytucji.
                if ( $prefill['street'] === '' && $prefill['inst_street'] !== '' ) {
                    $prefill['street']   = $prefill['inst_street'];
                    $prefill['house_no'] = $prefill['inst_house_no'];
                    $prefill['zip']      = $prefill['inst_zip'];
                    $prefill['city']     = $prefill['inst_city'];
                }
            }
        }

        return $prefill;
    }
}