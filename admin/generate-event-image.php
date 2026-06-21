<?php
/**
 * Kompletny, samowystarczalny plik generatora grafik.
 * Poprawiona wersja (bez zmiany zasadniczej logiki).
 */

// --- Środowisko WordPress ---
if ( ! defined( 'ABSPATH' ) ) {
    $wp_load_path = dirname(__FILE__, 4) . '/wp-load.php';
    if ( file_exists( $wp_load_path ) ) {
        require_once( $wp_load_path );
    } else {
        exit( 'Nie można załadować środowiska WordPress.' );
    }
}
if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Brak uprawnień.' ); }
if ( ! defined( 'DEW_PLUGIN_PATH' ) ) define( 'DEW_PLUGIN_PATH', plugin_dir_path( dirname( __FILE__, 2 ) ) );
if ( ! defined( 'DEW_PLUGIN_URL' ) ) define( 'DEW_PLUGIN_URL', plugin_dir_url( dirname( __FILE__, 2 ) ) );

// --- Dane wydarzenia ---
$event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
if ( ! $event_id ) { echo '<p class="error-message">Błąd: Brak ID wydarzenia.</p>'; return; }

global $wpdb;
$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}events_list WHERE id = %d", $event_id ) );
if ( ! $event ) { echo '<p class="error-message">Nie znaleziono wydarzenia.</p>'; return; }

// Konwertuj do tablicy / oczyszczanie podstawowych pól
$event_arr = (array) $event;
$title_raw = isset($event_arr['title']) ? $event_arr['title'] : '';
$category_raw = isset($event_arr['category']) ? $event_arr['category'] : '';
$image_url_raw = isset($event_arr['image_url']) ? $event_arr['image_url'] : '';
$start_datetime_raw = isset($event_arr['start_datetime']) ? $event_arr['start_datetime'] : '';
$end_datetime_raw = isset($event_arr['end_datetime']) ? $event_arr['end_datetime'] : '';
$is_all_day = ! empty($event_arr['is_all_day']);
$other_organizer = ! empty($event_arr['other_organizer']) ? $event_arr['other_organizer'] : '';
$user_id = ! empty($event_arr['user_id']) ? intval($event_arr['user_id']) : 0;
$access_raw = ! empty($event_arr['accessibility']) ? $event_arr['accessibility'] : '';
$delivery_mode = ! empty($event_arr['delivery_mode']) ? $event_arr['delivery_mode'] : '';
$price_raw = isset($event_arr['price']) ? $event_arr['price'] : 0;
$location_raw = ! empty($event_arr['location']) ? $event_arr['location'] : '';
$special_occasion_id = ! empty($event_arr['special_occasion_id']) ? intval($event_arr['special_occasion_id']) : 0;

$title = wp_kses_post( stripslashes( $title_raw ) );
$category = sanitize_text_field( stripslashes( $category_raw ) );
$image_url_processed = devents_get_image_as_base64( $image_url_raw ); // zakładamy, że helper jest dostępny

$start_ts = $start_datetime_raw ? strtotime( $start_datetime_raw ) : false;
$end_ts = ( $end_datetime_raw && $end_datetime_raw !== '0000-00-00 00:00:00' ) ? strtotime( $end_datetime_raw ) : null;
$file_date = $start_ts ? date_i18n( 'Y-m-d', $start_ts ) : date_i18n( 'Y-m-d' );

// --- Dane specjalnej okazji (jedno zapytanie zawierające slug/color/name) ---
$special_occasion_name = null;
$special_occasion_color = null;
$special_occasion_slug = null;
$info_bar_style = '';
if ( $special_occasion_id ) {
    $special_occasion = $wpdb->get_row( $wpdb->prepare(
        "SELECT name, color, slug FROM {$wpdb->prefix}devents_special_occasions WHERE id = %d",
        $special_occasion_id
    ) );
    if ( $special_occasion ) {
        $special_occasion_name = $special_occasion->name;
        $special_occasion_color = $special_occasion->color;
        $special_occasion_slug = $special_occasion->slug;
        // styl ramki (escape)
        $info_bar_style = sprintf(
            'style="border: 10px solid %s; box-shadow: 0 0 25px 3px %s;"',
            esc_attr( $special_occasion_color ),
            esc_attr( $special_occasion_color )
        );
    }
}

// --- Data / czas / "cały dzień" ---
$date_label = $start_ts ? date_i18n( 'd.m.Y', $start_ts ) : '';
$time_label = '';

if ( $is_all_day ) {
    // brak czasu - tylko data (jeśli jest data końcowa — zakres)
    if ( $end_ts && date( 'Y-m-d', $start_ts ) !== date( 'Y-m-d', $end_ts ) ) {
        $date_label .= ' - ' . date_i18n( 'd.m.Y', $end_ts );
    }
} else {
    if ( $start_ts ) {
        $time_label = date_i18n( 'H:i', $start_ts );
        if ( $end_ts ) {
            if ( date( 'Y-m-d', $start_ts ) !== date( 'Y-m-d', $end_ts ) ) {
                $time_label .= ' - ' . date_i18n( 'd.m.Y, H:i', $end_ts );
            } else {
                $time_label .= ' - ' . date_i18n( 'H:i', $end_ts );
            }
        }
    }
}

// --- Organizator ---
$organizer = 'Nie podano';
if ( $other_organizer ) {
    $organizer = wp_kses_post( stripslashes( $other_organizer ) );
} elseif ( $user_id ) {
    $user_data = get_userdata( $user_id );
    if ( $user_data ) {
        $organizer = sanitize_text_field( $user_data->display_name );
    }
}

// --- Inne pola ---
$access = $access_raw ? array_map( 'trim', explode( ',', $access_raw ) ) : [];
$is_online = in_array( $delivery_mode, array( 'Online', 'Hybrydowy' ), true );
$price_label = (floatval( $price_raw ) > 0) ? number_format_i18n( (float)$price_raw, 2 ) . ' zł' : 'Bezpłatnie';
$price_class = (floatval( $price_raw ) > 0) ? 'paid' : 'free';
$logo_url = DEW_PLUGIN_URL . 'assets/images/logo-white.png';

// --- Opis i hashtagi ---
$description_parts = array();
$hashtags = array( '#DEvents', '#DostępneWydarzenie', '#Głusi', '#PJM' );

if ( $special_occasion_name ) {
    $description_parts[] = "Wydarzenie organizowane w ramach " . $special_occasion_name . " (@festiwal.kulturybezbarier)";
    if ( $special_occasion_slug ) {
        $hashtags[] = '#' . preg_replace( '/\s+/', '', $special_occasion_slug );
    }
}

if ( $category ) {
    $hashtags[] = '#' . preg_replace( '/\s+/', '', $category );
}

$event_slug = sanitize_title( $title );
$event_url = "https://devents.pl/" . $event_slug . "-" . $event_id;

$description_parts[] = "🎉 " . $title . " organizowane przez " . $organizer;

$date_time_string = "🕓 Kiedy: " . $date_label;
if ( ! empty( $time_label ) ) {
    $date_time_string .= " " . $time_label;
}
$description_parts[] = $date_time_string;

if ( $location_raw ) {
    $description_parts[] = "📍 Lokalizacja: " . $location_raw;
}

if ( $category ) {
    $description_parts[] = "🏷️ Kategoria: " . $category;
}

if ( $price_label === 'Bezpłatnie' ) {
    $hashtags[] = '#Free';
    $hashtags[] = '#ZaDarmo';
}

$description_parts[] = "💵 Cena: " . $price_label;
$description_parts[] = "\n🔗 Więcej informacji: " . $event_url;

if ( ! empty( $access ) ) {
    $description_parts[] = "\nOrganizator zapewnia dostępność w zakresie:\n" . implode( ', ', $access );
}

$generated_description = implode( "\n", $description_parts );
$generated_hashtags = implode( ' ', array_unique( $hashtags ) );
?>

<div id="graphic-generator-<?php echo esc_attr( $event_id ); ?>" class="graphic-generator-instance" data-file-date="<?php echo esc_attr( $file_date ); ?>">

    <div class="graphic-controls">
        <div class="theme-toggle">
            <button class="btn btn--outline btn--small light-btn">Jasny</button>
            <button class="btn btn--outline btn--small dark-btn active">Ciemny</button>
        </div>

        <button class="btn btn--primary download-btn">
            <span class="material-symbols-outlined">download</span>
            <span class="btn-text">Pobierz grafikę</span>
        </button>
    </div>


    <div class="graphic-preview-container">
        <img src="" alt="Podgląd grafiki" class="graphic-preview-image" style="display: none; width: 100%; height: auto; border-radius: 8px;">
    </div>

    <div class="img-event-graphic offscreen-render dark" data-event-id="<?php echo esc_attr( $event_id ); ?>">

        <div class="img-background-image" style="background-image: url('<?php echo esc_attr( $image_url_processed ); ?>'); background-size: cover; background-position: center;"></div>

        <?php if ( $is_online ): ?><div class="img-transmission-badge">🔴 TRANSMISJA ONLINE</div><?php endif; ?>

        <div class="img-overlay-content">
            <div class="img-info-bar" <?php echo $info_bar_style; ?>>
                <?php if ( $special_occasion_name ): ?>
                    <div class="img-special-occasion-title" style="background-color: <?php echo esc_attr( $special_occasion_color ); ?>;">
                        <?php echo esc_html( $special_occasion_name ); ?>
                    </div>
                <?php endif; ?>

                <div class="img-category"><?php echo esc_html( $category ); ?></div>
                <div class="img-title"><?php echo esc_html( $title ); ?></div>

                <div class="img-meta-item"><span class="material-symbols-outlined">calendar_month</span><span class="img-meta-text"><?php echo esc_html( $date_label ); ?></span></div>

                <?php if ( ! empty( $time_label ) ): // warunkowe wyświetlanie czasu ?>
                    <div class="img-meta-item"><span class="material-symbols-outlined">schedule</span><span class="img-meta-text"><?php echo esc_html( $time_label ); ?></span></div>
                <?php endif; ?>

                <?php if ( ! empty( $location_raw ) ): ?><div class="img-meta-item"><span class="material-symbols-outlined">map</span><span class="img-meta-text img-break-word"><?php echo esc_html( $location_raw ); ?></span></div><?php endif; ?>
                <?php if ( $organizer ): ?><div class="img-meta-item"><span class="material-symbols-outlined">person</span><span class="img-meta-text img-break-word"><?php echo esc_html( $organizer ); ?></span></div><?php endif; ?>

                <?php if ( ! empty( $access ) ): ?><div class="img-accessibility-list"><?php foreach ( $access as $a ): ?><div class="img-accessibility-item"><?php echo esc_html( $a ); ?></div><?php endforeach; ?></div><?php endif; ?>
            </div>
        </div>

        <div class="img-footer-bar">
            <div class="img-price <?php echo esc_attr( $price_class ); ?>"><?php echo esc_html( $price_label ); ?></div>
            <div class="img-footer-logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt="DEvents Logo"></div>
        </div>

    </div>

    <div class="generated-text-wrapper">
        <h4>Gotowy tekst do social media</h4>
        <textarea class="generated-social-text" readonly rows="12"><?php echo esc_textarea( $generated_description . "\n\n" . $generated_hashtags ); ?></textarea>
        <button class="btn btn--secondary copy-text-btn">
            <span class="material-symbols-outlined">content_copy</span>
            <span class="btn-text">Kopiuj tekst</span>
        </button>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
(function(){
    const eventId = <?php echo json_encode( $event_id ); ?>;
    const container = document.getElementById(`graphic-generator-${eventId}`);
    if (!container || container.classList.contains('initialized')) return;
    container.classList.add('initialized');

    // Elementy
    const fileDate = container.dataset.fileDate;
    const lightBtn = container.querySelector('.light-btn');
    const darkBtn = container.querySelector('.dark-btn');
    const downloadBtn = container.querySelector('.download-btn');
    const previewImageElement = container.querySelector('.graphic-preview-image');
    const graphicElement = container.querySelector('.img-event-graphic');

    async function generatePreviewImage() {
        try {
            previewImageElement.style.display = 'none';
            // mały scale dla szybkiego podglądu
            const canvas = await html2canvas(graphicElement, { scale: 0.5, useCORS: true, backgroundColor: null });
            previewImageElement.src = canvas.toDataURL('image/jpeg', 0.9);
            previewImageElement.style.display = 'block';
        } catch (err) {
            console.error('Błąd generowania podglądu:', err);
        }
    }

    async function downloadFinalImage() {
        const downloadBtnText = downloadBtn.querySelector('.btn-text');
        downloadBtn.disabled = true;
        const originalText = downloadBtnText.innerText;
        downloadBtnText.innerText = 'Generowanie...';

        try {
            // wyższa jakość
            const canvas = await html2canvas(graphicElement, { scale: 2, useCORS: true, backgroundColor: null });
            const theme = graphicElement.classList.contains('dark') ? 'dark' : 'light';
            const fileName = `${fileDate}_${eventId}_Wydarzenie_${theme}.png`;
            await new Promise(resolve => {
                canvas.toBlob(blob => {
                    downloadOrShareImage(blob, fileName).then(resolve).catch(resolve);
                }, 'image/png');
            });
        } catch (err) {
            console.error('Błąd generowania finalnej grafiki:', err);
            alert('Wystąpił błąd podczas generowania grafiki.');
        } finally {
            downloadBtn.disabled = false;
            downloadBtnText.innerText = originalText;
        }
    }

    function setTheme(theme) {
        graphicElement.classList.remove('light','dark');
        graphicElement.classList.add(theme);
        lightBtn.classList.toggle('active', theme === 'light');
        darkBtn.classList.toggle('active', theme === 'dark');
        // odśwież podgląd
        generatePreviewImage();
    }

    lightBtn.addEventListener('click', () => setTheme('light'));
    darkBtn.addEventListener('click', () => setTheme('dark'));
    downloadBtn.addEventListener('click', downloadFinalImage);

    function downloadFile(blob, fileName) {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    async function downloadOrShareImage(blob, fileName) {
        // Poprawiona detekcja iOS (obsługuje iPadOS)
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (isIOS) {
            const file = new File([blob], fileName, { type: 'image/png' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                try {
                    await navigator.share({ files: [file], title: 'Grafika wydarzenia' });
                    return;
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    // w pozostałych wypadkach fallback do pobrania
                }
            }
        }
        downloadFile(blob, fileName);
    }

    // kopiowanie tekstu
    const copyBtn = container.querySelector('.copy-text-btn');
    const socialText = container.querySelector('.generated-social-text');
    copyBtn.addEventListener('click', function() {
        const copyBtnText = this.querySelector('.btn-text');
        navigator.clipboard.writeText(socialText.value).then(() => {
            copyBtnText.innerText = 'Skopiowano!';
            this.disabled = true;
            setTimeout(() => { copyBtnText.innerText = 'Kopiuj tekst'; this.disabled = false; }, 2000);
        }).catch(err => {
            console.error('Błąd kopiowania tekstu: ', err);
            alert('Nie udało się skopiować tekstu.');
        });
    });

    // inicjalizacja - ustaw domyślny motyw i wygeneruj podgląd
    setTheme('dark');

})();
</script>
