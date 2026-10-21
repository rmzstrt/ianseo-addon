<?php
/**
 * ianselp — Affichage des scores en direct
 * Diffusion aux spectateurs : adresse publique, QR code, cache de data.php.
 *
 * Ce fichier suppose que config.php et Lib/Fun_ianselp.php sont déjà inclus.
 */

/** Durée de vie, en secondes, du cache de data.php (les téléphones l'interrogent tous). */
define('IANSELP_CACHE_TTL', 4);

/** Vrai si l'hôte n'est joignable que depuis cette machine. */
function ianselp_IsLocalHost($host)
{
    $host = strtolower(trim((string)$host, '[]'));
    return $host === 'localhost'
        || $host === '::1'
        || $host === '0.0.0.0'
        || strpos($host, '127.') === 0
        || strpos($host, '169.254.') === 0
        || substr($host, -10) === '.localhost';
}

/** Schéma réel de la requête, y compris derrière un reverse proxy. */
function ianselp_Scheme()
{
    if ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
        return 'https';
    }
    return 'http';
}

/**
 * Adresse de base (schéma://hôte[:port], sans chemin ni slash final) que les
 * téléphones des spectateurs doivent atteindre.
 *
 * Réglage « baseurl » s'il est renseigné ; sinon l'adresse par laquelle la page
 * est appelée. Si cette page est ouverte via localhost (poste du vidéoprojecteur),
 * on essaie l'adresse réseau de la machine : localhost ne vaut rien sur un
 * téléphone.
 *
 * @return string '' si aucune adresse utilisable n'est connue
 */
function ianselp_BaseUrl(array $cfg)
{
    $custom = trim(isset($cfg['baseurl']) ? (string)$cfg['baseurl'] : '');
    if ($custom !== '') {
        if (!preg_match('#^https?://#i', $custom)) {
            $custom = 'http://' . $custom;
        }
        $p = parse_url($custom);
        if (!$p || empty($p['host']) || ianselp_IsLocalHost($p['host'])) {
            return '';
        }
        return strtolower($p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    $p    = parse_url('http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    $host = isset($p['host']) ? $p['host'] : '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    if ($host === '') {
        return '';
    }
    if (ianselp_IsLocalHost($host)) {
        $ip = gethostbyname(gethostname());
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ianselp_IsLocalHost($ip)) {
            return '';
        }
        $host = $ip;
    }
    return ianselp_Scheme() . '://' . $host . $port;
}

/** URL de la page mobile des spectateurs, ou '' si aucune adresse n'est utilisable. */
function ianselp_MobileUrl($TourId, array $cfg)
{
    global $CFG;
    $base = ianselp_BaseUrl($cfg);
    if ($base === '') {
        return '';
    }
    return $base . $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(dirname(__FILE__)))
         . '/mobile.php?tour=' . (int)$TourId;
}

/**
 * QR code en SVG, prêt à être inséré dans une page : taille libre (viewBox),
 * marge blanche incluse. Utilise l'encodeur TCPDF livré avec IANSEO.
 *
 * @return string '' si l'encodage échoue
 */
function ianselp_QrSvg($text)
{
    $arr = array();
    ob_start();
    try {
        require_once('Common/tcpdf/tcpdf_barcodes_2d.php');
        $qr  = new TCPDF2DBarcode($text, 'QRCODE,M');
        $arr = $qr->getBarcodeArray();
    } catch (Throwable $e) {
        $arr = array();
    }
    ob_end_clean();

    if (empty($arr['bcode']) || empty($arr['num_cols']) || empty($arr['num_rows'])) {
        return '';
    }

    $cols   = (int)$arr['num_cols'];
    $rows   = (int)$arr['num_rows'];
    $margin = 4;
    $path   = '';
    for ($r = 0; $r < $rows; $r++) {
        $c = 0;
        while ($c < $cols) {
            if ($arr['bcode'][$r][$c] == 1) {
                $start = $c;
                while ($c < $cols && $arr['bcode'][$r][$c] == 1) {
                    $c++;
                }
                $path .= 'M' . $start . ' ' . $r . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
            } else {
                $c++;
            }
        }
    }

    $w = $cols + 2 * $margin;
    $h = $rows + 2 * $margin;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-' . $margin . ' -' . $margin . ' ' . $w . ' ' . $h . '"'
         . ' shape-rendering="crispEdges" role="img" aria-label="QR code vers la page des scores">'
         . '<rect x="-' . $margin . '" y="-' . $margin . '" width="' . $w . '" height="' . $h . '" fill="#fff"/>'
         . '<path fill="#000" d="' . $path . '"/></svg>';
}

// ---------------------------------------------------------------------------
// Cache de data.php
//
// Chaque téléphone interroge data.php toutes les quelques secondes, et le calcul
// du classement est coûteux. Sans cache, cent spectateurs saturent le poste qui
// reçoit aussi les scores. Le cache est un fichier dans le dossier temporaire,
// valable quelques secondes ; il est vidé quand les réglages changent.
// ---------------------------------------------------------------------------

function ianselp_CacheFile($TourId)
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ianselp_' . md5(__FILE__ . '|' . (int)$TourId) . '.json';
}

/** @return string|null contenu encore valable, sinon null */
function ianselp_CacheGet($TourId, $ttl = IANSELP_CACHE_TTL)
{
    $file  = ianselp_CacheFile($TourId);
    $mtime = @filemtime($file);
    if ($mtime === false || time() - $mtime >= $ttl) {
        return null;
    }
    $json = @file_get_contents($file);
    return ($json === false || $json === '') ? null : $json;
}

function ianselp_CachePut($TourId, $json)
{
    $file = ianselp_CacheFile($TourId);
    $tmp  = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

function ianselp_CacheClear($TourId)
{
    @unlink(ianselp_CacheFile($TourId));
}
