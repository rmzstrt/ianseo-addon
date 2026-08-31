<?php
/**
 * ianselp — Affichage des scores en direct
 * Source de données JSON interrogée en boucle par live.php.
 *
 * Lecture seule, résultats publics : la page accepte ?tour=<ToId> pour pouvoir
 * être affichée sur un poste sans session IANSEO (vidéoprojecteur). Aucune
 * donnée personnelle au-delà de ce qui figure déjà sur les résultats affichés.
 *
 * ?demo=1 renvoie un jeu de données factice, sans aucun accès aux scores.
 */

require_once(dirname(dirname(__FILE__)) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once(dirname(__FILE__) . '/Lib/Fun_ianselp.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$TourId = ianselp_TourId();
if (!$TourId) {
    http_response_code(404);
    echo json_encode(array('error' => 'Aucun concours sélectionné.'));
    exit;
}

$cfg  = ianselp_GetConfig($TourId);
$Tour = safe_fetch(safe_r_sql(
    "SELECT ToName, ToNameShort, ToGolds, ToXNine FROM Tournament WHERE ToId=" . StrSafe_DB($TourId)
));

$demo = !empty($_REQUEST['demo']);
$rank = $demo
    ? ianselp_DemoRanking()
    : ianselp_Ranking($TourId, $cfg['events'], $cfg['dist']);

echo json_encode(array(
    'error'      => 0,
    'demo'       => $demo ? 1 : 0,
    'serverTime' => time(),
    'title'      => $cfg['title'] !== '' ? $cfg['title'] : $Tour->ToName,
    'dist'       => $cfg['dist'],
    'goldLabel'  => $Tour->ToGolds !== '' ? $Tour->ToGolds : '10+',
    'xnineLabel' => $Tour->ToXNine !== '' ? $Tour->ToXNine : 'X',
    'scrollsec'  => $cfg['scrollsec'],
    'refresh'    => $cfg['refresh'],
    'rows'       => $cfg['rows'],
    'lastUpdate' => $rank['lastUpdate'],
    'sections'   => $rank['sections'],
), JSON_UNESCAPED_UNICODE);
