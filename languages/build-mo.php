<?php
/**
 * build-mo.php — Kompiluje pliki .po do .mo
 * 
 * UŻYCIE: Z poziomu katalogu languages/ wtyczki:
 *   php build-mo.php
 * 
 * Lub z dowolnego miejsca:
 *   php build-mo.php /ścieżka/do/languages/
 * 
 * Kompiluje wszystkie pliki devents-*.po w katalogu do .mo
 * Nie wymaga msgfmt ani żadnych zależności zewnętrznych.
 */

$dir = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__;

$po_files = glob($dir . '/devents-*.po');

if (empty($po_files)) {
    echo "Brak plików devents-*.po w katalogu: {$dir}\n";
    exit(1);
}

foreach ($po_files as $po_path) {
    $mo_path = preg_replace('/\.po$/', '.mo', $po_path);
    
    echo "Kompiluję: " . basename($po_path) . " → " . basename($mo_path) . "... ";
    
    $entries = parse_po_file($po_path);
    
    if (empty($entries)) {
        echo "POMINIĘTO (0 wpisów)\n";
        continue;
    }
    
    write_mo_file($entries, $mo_path);
    
    $size = filesize($mo_path);
    echo "OK (" . count($entries) . " wpisów, {$size} bajtów)\n";
}

echo "\nGotowe! Pliki .mo zaktualizowane.\n";

// ============================================================================

function parse_po_file(string $path): array {
    $content = file_get_contents($path);
    $entries = [];
    
    // Dziel na bloki po pustej linii
    $blocks = preg_split('/\n{2,}/', $content);
    
    foreach ($blocks as $block) {
        $block = trim($block);
        if (empty($block)) continue;
        
        // Plural
        if (preg_match('/^msgid\s+"(.*)"\s*\nmsgid_plural\s+"(.*)"/m', $block, $m)) {
            $msgid = unescape_po($m[1]);
            $msgid_plural = unescape_po($m[2]);
            
            preg_match_all('/msgstr\[\d+\]\s+"(.*)"/m', $block, $pm);
            if (!empty($pm[1])) {
                $key = $msgid . "\x00" . $msgid_plural;
                $value = implode("\x00", array_map('unescape_po', $pm[1]));
                $entries[] = [$key, $value];
            }
            continue;
        }
        
        // Regular
        if (preg_match('/^msgid\s+"(.*)"/m', $block, $m1) && 
            preg_match('/^msgstr\s+"(.*)"/m', $block, $m2)) {
            $msgid = unescape_po($m1[1]);
            $msgstr = unescape_po($m2[1]);
            $entries[] = [$msgid, $msgstr];
        }
    }
    
    return $entries;
}

function unescape_po(string $s): string {
    return str_replace(
        ['\\n', '\\"', '\\\\'],
        ["\n", '"', '\\'],
        $s
    );
}

function write_mo_file(array $entries, string $path): void {
    // Sortuj po kluczu (wymagane przez format MO)
    usort($entries, function($a, $b) {
        return strcmp($a[0], $b[0]);
    });
    
    $num = count($entries);
    $header_size = 28;
    
    $orig_table_offset = $header_size;
    $trans_table_offset = $orig_table_offset + $num * 8;
    $string_data_offset = $trans_table_offset + $num * 8;
    
    $orig_table = '';
    $trans_table = '';
    $string_data = '';
    $current_offset = $string_data_offset;
    
    // Oryginały
    foreach ($entries as [$key, $val]) {
        $encoded = $key;
        $orig_table .= pack('VV', strlen($encoded), $current_offset);
        $string_data .= $encoded . "\x00";
        $current_offset += strlen($encoded) + 1;
    }
    
    // Tłumaczenia
    foreach ($entries as [$key, $val]) {
        $encoded = $val;
        $trans_table .= pack('VV', strlen($encoded), $current_offset);
        $string_data .= $encoded . "\x00";
        $current_offset += strlen($encoded) + 1;
    }
    
    // Nagłówek
    $header = pack('V', 0x950412de);  // Magic
    $header .= pack('V', 0);          // Revision
    $header .= pack('V', $num);       // Entries
    $header .= pack('V', $orig_table_offset);
    $header .= pack('V', $trans_table_offset);
    $header .= pack('V', 0);          // Hash table size
    $header .= pack('V', 0);          // Hash table offset
    
    file_put_contents($path, $header . $orig_table . $trans_table . $string_data);
}
