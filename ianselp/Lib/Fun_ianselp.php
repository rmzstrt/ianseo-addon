<?php
/**
 * ianselp — Affichage des scores en direct
 * Fonctions communes.
 *
 * Ce fichier suppose que config.php a déjà été inclus par la page appelante.
 */

define('IANSELP_MODULE', 'ianselp');

/** Valeurs par défaut de la configuration (clés ModulesParameters : 20 car. max). */
function ianselp_Defaults()
{
    return array(
        'events'    => array(), // codes d'épreuve à afficher ; vide = toutes
        'scrollsec' => 20,      // secondes pour faire défiler une hauteur d'écran
        'refresh'   => 10,      // secondes entre deux interrogations du serveur
        'rows'      => 12,      // lignes visibles à l'écran (pilote la taille du texte)
        'dist'      => 0,       // 0 = total, sinon n° de distance
        'title'     => '',      // titre libre ; vide = nom du concours
    );
}

/**
 * Détermine le tournoi à afficher.
 * Accepte ?tour=<ToId> pour permettre un affichage sur un poste sans session
 * IANSEO (vidéoprojecteur), sinon reprend le tournoi ouvert en session.
 *
 * @return int 0 si aucun tournoi valide
 */
function ianselp_TourId()
{
    $TourId = 0;
    if (isset($_REQUEST['tour']) && ctype_digit((string)$_REQUEST['tour'])) {
        $TourId = (int)$_REQUEST['tour'];
    } elseif (!empty($_SESSION['TourId'])) {
        $TourId = (int)$_SESSION['TourId'];
    }
    if (!$TourId) {
        return 0;
    }

    $Rs = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($TourId));
    return safe_num_rows($Rs) ? $TourId : 0;
}

/** Lit la configuration du module pour un tournoi, complétée par les défauts. */
function ianselp_GetConfig($TourId)
{
    $cfg = ianselp_Defaults();
    foreach ($cfg as $key => $default) {
        $cfg[$key] = getModuleParameter(IANSELP_MODULE, $key, $default, $TourId);
    }

    // Garde-fous : des valeurs aberrantes rendraient l'affichage inutilisable.
    $cfg['events']    = is_array($cfg['events']) ? $cfg['events'] : array();
    $cfg['scrollsec'] = max(5, min(120, (int)$cfg['scrollsec']));
    $cfg['refresh']   = max(3, min(300, (int)$cfg['refresh']));
    $cfg['rows']      = max(4, min(40,  (int)$cfg['rows']));
    $cfg['dist']      = max(0, min(8,   (int)$cfg['dist']));
    $cfg['title']     = (string)$cfg['title'];

    return $cfg;
}

/** Enregistre la configuration du module. */
function ianselp_SaveConfig($TourId, array $cfg)
{
    foreach (array_keys(ianselp_Defaults()) as $key) {
        if (array_key_exists($key, $cfg)) {
            setModuleParameter(IANSELP_MODULE, $key, $cfg[$key], $TourId);
        }
    }
}

/**
 * Liste des épreuves individuelles du tournoi, avec le nombre d'archers
 * effectivement classés dans chacune.
 */
function ianselp_EventList($TourId)
{
    $out = array();
    $Rs  = safe_r_sql(
        "SELECT e.EvCode,
                e.EvEventName,
                e.EvProgr,
                COUNT(i.IndId) AS Nb
           FROM Events AS e
           LEFT JOIN Individuals AS i ON i.IndEvent      = e.EvCode
                                     AND i.IndTournament = e.EvTournament
          WHERE e.EvTournament=" . StrSafe_DB($TourId) . "
            AND e.EvTeamEvent=0
          GROUP BY e.EvCode, e.EvEventName, e.EvProgr
          ORDER BY e.EvProgr, e.EvCode"
    );
    while ($row = safe_fetch($Rs)) {
        $out[$row->EvCode] = array(
            'code'  => $row->EvCode,
            'name'  => $row->EvEventName,
            'progr' => (int)$row->EvProgr,
            'nb'    => (int)$row->Nb,
        );
    }
    return $out;
}

/**
 * Classement de qualification en direct.
 *
 * S'appuie sur le moteur de classement officiel d'IANSEO (Obj_Rank_Abs), qui
 * lit la table Individuals. Celle-ci est recalculée par le cœur à chaque
 * enregistrement de feuille de marque (Qualification/UpdateQuals.php) ou de
 * flèche ISK (Qualification/UpdateArrow.php) : le classement est donc à jour
 * sans que ce module n'ait à recalculer quoi que ce soit.
 *
 * @return array sections normalisées prêtes à être encodées en JSON
 */
function ianselp_Ranking($TourId, array $events, $dist = 0)
{
    require_once('Common/Lib/Obj_RankFactory.php');

    $opts = array('tournament' => $TourId, 'dist' => (int)$dist);
    $opts['events'] = $events ? $events : '%';

    $rank = Obj_RankFactory::create('Abs', $opts);
    if (!$rank) {
        return array('lastUpdate' => 0, 'sections' => array());
    }
    $rank->read();
    $data = $rank->getData();

    $sections = array();
    foreach ((isset($data['sections']) ? $data['sections'] : array()) as $section) {
        $items = array();
        foreach ($section['items'] as $item) {
            // Un archer sans aucune flèche tirée n'a pas sa place à l'écran.
            // On garde en revanche les IRM (DNS, DNF, DSQ...), où 'score' n'est
            // pas un nombre mais le code du forfait.
            if ((int)$item['hits'] <= 0 && is_numeric($item['score']) && (float)$item['score'] <= 0) {
                continue;
            }
            $items[] = array(
                // IANSEO laisse le rang à 0 tant que le classement n'a pas été
                // recalculé : mieux vaut ne rien afficher qu'un « 0 » faux.
                'rank'    => ((string)$item['rank'] === '0') ? '' : $item['rank'],
                'athlete' => $item['athlete'],
                'club'    => $item['countryName'],
                'target'  => $item['target'],
                'score'   => $item['score'],
                'gold'    => $item['gold'],
                'xnine'   => $item['xnine'],
                'hits'    => $item['hits'],
            );
        }
        if (!$items) {
            continue;
        }
        $sections[] = array(
            'event' => $section['meta']['event'],
            'descr' => $section['meta']['descr'],
            'items' => $items,
        );
    }

    // IANSEO initialise lastUpdate à 0000-00-00 : strtotime() renvoie alors une valeur négative.
    $lastUpdate = isset($data['meta']['lastUpdate']) ? @strtotime($data['meta']['lastUpdate']) : 0;
    if (!$lastUpdate || $lastUpdate < 0) { $lastUpdate = 0; }

    return array('lastUpdate' => (int)$lastUpdate, 'sections' => $sections);
}

/**
 * Jeu de données factice, pour régler l'écran / le vidéoprojecteur avant le
 * concours. N'interroge pas la base et n'y écrit rien.
 */
function ianselp_DemoRanking()
{
    $noms = array(
        'MARTIN Julien', 'BERNARD Camille', 'THOMAS Lucas', 'PETIT Emma',
        'ROBERT Hugo', 'RICHARD Léa', 'DURAND Nathan', 'DUBOIS Chloé',
        'MOREAU Enzo', 'LAURENT Manon', 'SIMON Théo', 'MICHEL Jade',
        'LEFEBVRE Noah', 'GARCIA Lina', 'DAVID Raphaël',
    );
    $clubs = array('ASPTT BASTIA', 'ARC CLUB AJACCIO', 'CIE DE CORTE', 'ARCHERS DU CAP');

    $sections = array();
    foreach (array('S1HCL' => 'Sénior 1 Homme Arc Classique',
                   'S1FCL' => 'Sénior 1 Femme Arc Classique',
                   'S3HCO' => 'Sénior 3 Homme Arc à Poulies') as $code => $descr) {
        $items = array();
        $score = 580;
        for ($i = 0; $i < 11; $i++) {
            $score -= rand(3, 14);
            $items[] = array(
                'rank'    => $i + 1,
                'athlete' => $noms[($i + strlen($code)) % count($noms)],
                'club'    => $clubs[($i + strlen($descr)) % count($clubs)],
                'target'  => (1 + $i) . chr(65 + ($i % 4)),
                'score'   => $score,
                'gold'    => rand(10, 30),
                'xnine'   => rand(2, 15),
                'hits'    => 60,
            );
        }
        $sections[] = array('event' => $code, 'descr' => $descr, 'items' => $items);
    }

    return array('lastUpdate' => time(), 'sections' => $sections);
}

/**
 * Nombre d'archers ayant tiré mais dont le rang n'est pas calculé.
 *
 * En fonctionnement normal, IANSEO recalcule le classement à chaque
 * enregistrement de feuille de marque. Un compteur non nul signale des scores
 * arrivés par un autre chemin (import, outil externe, simulation) : le
 * classement affiché serait alors incomplet.
 */
function ianselp_StaleCount($TourId)
{
    $Rs = safe_r_sql(
        "SELECT COUNT(*) AS Nb
           FROM Individuals AS i
           INNER JOIN Entries        AS e ON e.EnId = i.IndId AND e.EnTournament = i.IndTournament
           INNER JOIN Qualifications AS q ON q.QuId = e.EnId
          WHERE i.IndTournament=" . StrSafe_DB($TourId) . "
            AND e.EnAthlete=1
            AND e.EnIndFEvent=1
            AND q.QuHits > 0
            AND i.IndRank = 0"
    );
    $row = safe_fetch($Rs);
    return $row ? (int)$row->Nb : 0;
}

/**
 * Recalcule le classement de qualification, exactement comme le fait le cœur
 * après l'enregistrement d'une feuille de marque
 * (cf. Qualification/Fun_Qualification.local.inc.php).
 *
 * Écrit dans la table Individuals : réservé à la page de pilotage.
 */
function ianselp_Recalculate($TourId)
{
    require_once('Common/Lib/Obj_RankFactory.php');
    // Obj_Rank_Abs_calc::calculate() appelle SavedInPhase(), qui n'est pas
    // chargée par la factory.
    require_once('Common/Lib/Fun_Phases.inc.php');

    $Rs  = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId=" . StrSafe_DB($TourId));
    $row = safe_fetch($Rs);
    $numDist = $row ? (int)$row->ToNumDist : 0;

    $ok = true;
    for ($d = 0; $d <= $numDist; $d++) {
        $rank = Obj_RankFactory::create('Abs', array(
            'tournament' => $TourId,
            'events'     => '%',
            'dist'       => $d,
        ));
        if (!$rank || !$rank->calculate()) {
            $ok = false;
        }
    }
    return $ok;
}
