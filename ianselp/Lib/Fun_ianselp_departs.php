<?php
/**
 * ianselp — Affichage des scores en direct
 * Plusieurs départs : archers « terminés » / « en cours », et comparaison à
 * volée équivalente.
 *
 * Le problème : quand le 2e départ commence, les archers du 1er sont terminés
 * (60 flèches) et ceux du 2e en cours (6 flèches). Les classer ensemble relègue
 * les seconds tout en bas, alors qu'ils peuvent mener.
 *
 * Ici, un archer en cours sort du classement dès qu'un archer d'un départ
 * antérieur est déjà terminé dans la même épreuve. Il passe dans un bloc
 * « en cours » où son score après N volées est comparé à celui de tous les
 * autres archers de l'épreuve après leurs N premières volées. Les tirs hors
 * classement (EnIndFEvent=0, même licence + arme + classe qu'un archer qui
 * compte) y figurent aussi, comparés à leur propre départ qui compte.
 *
 * Les volées sont relues dans les arrowstrings (QuD<n>Arrowstring), une lettre
 * par flèche : voir Common/Lib/ArrTargets.inc.php.
 *
 * Ce fichier suppose que config.php est déjà inclus.
 */

/**
 * Volées et flèches par distance, pour chaque départ de qualification.
 * Un départ dont le plan est incomplet est ignoré : on ne peut pas dire s'il
 * est terminé.
 *
 * @return array [n° de départ => ['dists' => [n° distance => ['ends','arrows']], 'ends', 'arrows']]
 */
function ianselp_SessionPlan($TourId)
{
    $plan    = array();
    $numDist = 0;
    $Rs = safe_r_sql(
        "SELECT d.DiSession, d.DiDistance, d.DiEnds, d.DiArrows, t.ToNumDist
           FROM DistanceInformation AS d
          INNER JOIN Tournament AS t ON t.ToId = d.DiTournament
          WHERE d.DiTournament=" . StrSafe_DB($TourId) . "
            AND d.DiType='Q'
            AND d.DiDistance BETWEEN 1 AND t.ToNumDist
          ORDER BY d.DiSession, d.DiDistance"
    );
    $bad = array();
    while ($r = safe_fetch($Rs)) {
        $s       = (int)$r->DiSession;
        $numDist = (int)$r->ToNumDist;
        $ends    = (int)$r->DiEnds;
        $arrows  = (int)$r->DiArrows;
        if ($s < 1 || $ends <= 0 || $arrows <= 0) {
            $bad[$s] = true;
            continue;
        }
        if (!isset($plan[$s])) {
            $plan[$s] = array('dists' => array(), 'ends' => 0, 'arrows' => 0);
        }
        $plan[$s]['dists'][(int)$r->DiDistance] = array('ends' => $ends, 'arrows' => $arrows);
        $plan[$s]['ends']   += $ends;
        $plan[$s]['arrows'] += $ends * $arrows;
    }
    foreach ($plan as $s => $p) {
        if (isset($bad[$s]) || count($p['dists']) !== $numDist) {
            unset($plan[$s]);
        }
    }
    return $plan;
}

/**
 * Score cumulé après chaque volée complète.
 *
 * Les distances se tirent à la suite : on s'arrête à la première volée
 * incomplète (flèche manquante), car au-delà le total n'est plus comparable.
 *
 * @param array  $dists      [n° distance => ['ends','arrows']]
 * @param array  $strings    [n° distance => arrowstring]
 * @return array indices 0..N, chacun [score, ors, neufs] ; l'indice 0 vaut [0,0,0]
 */
function ianselp_Cumul(array $dists, array $strings, $goldChars, $xChars)
{
    global $LetterPoint;

    $cum = array(array(0, 0, 0));
    $s = $g = $x = 0;
    foreach ($dists as $d => $def) {
        $str = isset($strings[$d]) ? (string)$strings[$d] : '';
        for ($k = 0; $k < $def['ends']; $k++) {
            $seg = substr($str, $k * $def['arrows'], $def['arrows']);
            if (strlen($seg) < $def['arrows'] || strpos($seg, ' ') !== false) {
                return $cum;
            }
            for ($i = 0; $i < strlen($seg); $i++) {
                $c = strtoupper($seg[$i]);
                if (isset($LetterPoint[$c])) {
                    $s += (int)$LetterPoint[$c]['N'];
                }
                if ($goldChars !== '' && strpos($goldChars, $c) !== false) {
                    $g++;
                }
                if ($xChars !== '' && strpos($xChars, $c) !== false) {
                    $x++;
                }
            }
            $cum[] = array($s, $g, $x);
        }
    }
    return $cum;
}

/**
 * Toutes les inscriptions ayant tiré, y compris les tirs hors classement
 * (EnIndFEvent=0) que le classement officiel ne renvoie pas.
 */
function ianselp_StageRows($TourId, $numDist)
{
    $numDist = max(1, min(8, (int)$numDist));
    $cols = '';
    for ($d = 1; $d <= $numDist; $d++) {
        $cols .= ", q.QuD{$d}Arrowstring AS S{$d}";
    }

    $rows = array();
    $Rs = safe_r_sql(
        "SELECT e.EnId, e.EnCode, e.EnDivision, e.EnClass, e.EnIndFEvent,
                CONCAT(UPPER(e.EnFirstName), ' ', e.EnName) AS Athlete,
                c.CoName, q.QuSession, q.QuTarget, q.QuLetter, q.QuHits, q.QuScore{$cols}
           FROM Entries AS e
          INNER JOIN Qualifications AS q ON q.QuId = e.EnId
           LEFT JOIN Countries AS c ON c.CoId = e.EnCountry AND c.CoTournament = e.EnTournament
          WHERE e.EnTournament=" . StrSafe_DB($TourId) . "
            AND e.EnAthlete=1 AND e.EnStatus<=1 AND q.QuHits>0"
    );
    while ($r = safe_fetch($Rs)) {
        $strings = array();
        for ($d = 1; $d <= $numDist; $d++) {
            $col = 'S' . $d;
            $strings[$d] = (string)$r->$col;
        }
        $rows[(int)$r->EnId] = array(
            'id'       => (int)$r->EnId,
            'code'     => trim((string)$r->EnCode),
            'div'      => (string)$r->EnDivision,
            'cls'      => (string)$r->EnClass,
            'counting' => ((int)$r->EnIndFEvent === 1),
            'session'  => (int)$r->QuSession,
            'hits'     => (int)$r->QuHits,
            'score'    => (int)$r->QuScore,
            'athlete'  => (string)$r->Athlete,
            'club'     => (string)$r->CoName,
            'target'   => ((int)$r->QuTarget > 0 ? $r->QuTarget . $r->QuLetter : ''),
            'strings'  => $strings,
        );
    }
    return $rows;
}

/** Lettres des ors et des neufs, par épreuve (celles de l'épreuve, sinon celles du concours). */
function ianselp_StageChars($TourId)
{
    $out = array();
    $Rs = safe_r_sql(
        "SELECT e.EvCode,
                IF(e.EvGoldsChars='', t.ToGoldsChars, e.EvGoldsChars) AS G,
                IF(e.EvXNineChars='', t.ToXNineChars, e.EvXNineChars) AS X
           FROM Events AS e
          INNER JOIN Tournament AS t ON t.ToId = e.EvTournament
          WHERE e.EvTournament=" . StrSafe_DB($TourId) . " AND e.EvTeamEvent=0"
    );
    while ($r = safe_fetch($Rs)) {
        $out[$r->EvCode] = array('g' => strtoupper((string)$r->G), 'x' => strtoupper((string)$r->X));
    }
    return $out;
}

/** vrai si (s1,g1,x1) bat (s2,g2,x2) : score, puis ors, puis neufs. */
function ianselp_Beats($a, $b)
{
    if ($a[0] !== $b[0]) { return $a[0] > $b[0]; }
    if ($a[1] !== $b[1]) { return $a[1] > $b[1]; }
    return $a[2] > $b[2];
}

/**
 * Logique pure, sans accès à la base : répartit chaque épreuve en archers
 * terminés (classement) et archers en cours / hors classement (bloc « live »).
 *
 * @param array $sections sections du classement officiel ; chaque item porte
 *                        id, session, hits, score, rank, athlete, club, target, gold, xnine
 * @param array $rows     inscriptions ayant tiré, par EnId (ianselp_StageRows)
 * @param array $plan     ianselp_SessionPlan
 * @param array $chars    ianselp_StageChars
 */
function ianselp_StageLogic(array $sections, array $rows, array $plan, array $chars)
{
    // Pour chaque licence + arme + classe, l'inscription qui compte : le plus petit départ.
    $twin = array();
    foreach ($rows as $r) {
        if (!$r['counting'] || $r['code'] === '') {
            continue;
        }
        $k = $r['code'] . '|' . $r['div'] . '|' . $r['cls'];
        if (!isset($twin[$k]) || $r['session'] < $twin[$k]['session']) {
            $twin[$k] = $r;
        }
    }

    foreach ($sections as &$sec) {
        $G = isset($chars[$sec['event']]) ? $chars[$sec['event']]['g'] : '';
        $X = isset($chars[$sec['event']]) ? $chars[$sec['event']]['x'] : '';

        // 1. Où en est chaque archer du classement officiel ?
        $info = array();
        foreach ($sec['items'] as $it) {
            $id = (int)$it['id'];
            $s  = (int)$it['session'];
            if (!isset($rows[$id]) || !is_numeric($it['score']) || !isset($plan[$s])) {
                $info[$id] = array('done' => true);   // IRM, ou départ inconnu : on ne touche à rien
                continue;
            }
            $c = ianselp_Cumul($plan[$s]['dists'], $rows[$id]['strings'], $G, $X);
            $n = count($c) - 1;
            $info[$id] = array(
                's'    => $s,
                'n'    => $n,
                'c'    => $c,
                'done' => ($rows[$id]['hits'] >= $plan[$s]['arrows'] || $n >= $plan[$s]['ends']),
            );
        }

        // 2. Un archer en cours quitte le classement s'il existe déjà, dans cette
        //    épreuve, un archer terminé d'un départ antérieur.
        $minDone = null;
        foreach ($sec['items'] as $it) {
            $s = (int)$it['session'];
            if (!empty($info[(int)$it['id']]['done']) && $s >= 1 && ($minDone === null || $s < $minDone)) {
                $minDone = $s;
            }
        }

        $kept  = array();
        $live  = array();
        $moved = 0;
        foreach ($sec['items'] as $it) {
            $id = (int)$it['id'];
            $i  = $info[$id];
            if (!empty($i['done']) || $minDone === null || $i['s'] <= $minDone) {
                $kept[] = $it;
                continue;
            }

            $moved++;
            $n = $i['n'];
            if ($n > 0) {
                $at = $i['c'][$n];
            } else {
                $at = array((int)$it['score'], (int)$it['gold'], (int)$it['xnine']);
            }

            // Rang qu'aurait l'archer si tout le monde s'était arrêté à sa volée N.
            $vrank = 1;
            $vtotal = 1;
            $best = null;
            if ($n > 0) {
                foreach ($info as $oid => $o) {
                    if ($oid === $id || !isset($o['n']) || $o['n'] < $n) {
                        continue;
                    }
                    $vtotal++;
                    if (ianselp_Beats($o['c'][$n], $at)) {
                        $vrank++;
                    }
                    if ($best === null || $o['c'][$n][0] > $best) {
                        $best = $o['c'][$n][0];
                    }
                }
            }

            $live[] = array(
                'id'         => $id,
                'athlete'    => $it['athlete'],
                'club'       => $it['club'],
                'target'     => $it['target'],
                'session'    => $i['s'],
                'official'   => 1,
                'state'      => 'progress',
                'end'        => $n,
                'ends'       => $plan[$i['s']]['ends'],
                'score'      => $at[0],
                'gold'       => $at[1],
                'xnine'      => $at[2],
                'vrank'      => $vrank,
                'vtotal'     => $vtotal,
                'best'       => $best,
                'own'        => null,
                'ownSession' => 0,
            );
        }

        // 3. Tirs hors classement : comparés à l'inscription qui compte.
        foreach ($rows as $r) {
            if ($r['counting'] || $r['code'] === '' || !isset($plan[$r['session']])) {
                continue;
            }
            $k = $r['code'] . '|' . $r['div'] . '|' . $r['cls'];
            if (!isset($twin[$k]) || $twin[$k]['session'] === $r['session'] || !isset($info[$twin[$k]['id']])) {
                continue;
            }
            $p = $plan[$r['session']];
            $c = ianselp_Cumul($p['dists'], $r['strings'], $G, $X);
            $n = count($c) - 1;
            $at = $n > 0 ? $c[$n] : array($r['score'], 0, 0);

            $own = null;
            $ownSession = 0;
            $t = $info[$twin[$k]['id']];
            if ($n > 0 && isset($t['n']) && $t['n'] >= $n) {
                $own = $t['c'][$n][0];
                $ownSession = $twin[$k]['session'];
            }

            $live[] = array(
                'id'         => $r['id'],
                'athlete'    => $r['athlete'],
                'club'       => $r['club'],
                'target'     => $r['target'],
                'session'    => $r['session'],
                'official'   => 0,
                'state'      => ($r['hits'] >= $p['arrows'] || $n >= $p['ends']) ? 'done' : 'progress',
                'end'        => $n,
                'ends'       => $p['ends'],
                'score'      => $at[0],
                'gold'       => $at[1],
                'xnine'      => $at[2],
                'vrank'      => null,
                'vtotal'     => null,
                'best'       => null,
                'own'        => $own,
                'ownSession' => $ownSession,
            );
        }

        usort($live, function ($a, $b) {
            if ($a['official'] !== $b['official']) {
                return $b['official'] - $a['official'];
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] - $a['score'];
            }
            return strcmp($a['athlete'], $b['athlete']);
        });

        // 4. Des archers ont quitté le classement : on le renumérote sans trous,
        //    en gardant les égalités du classement officiel.
        if ($moved) {
            $pos = 0;
            $prevOld = null;
            $prevNew = null;
            foreach ($kept as &$k) {
                if (!is_numeric($k['rank']) || (int)$k['rank'] <= 0) {
                    continue;
                }
                $pos++;
                if ($prevOld !== null && (int)$k['rank'] === $prevOld) {
                    $k['rank'] = (string)$prevNew;
                } else {
                    $prevOld = (int)$k['rank'];
                    $prevNew = $pos;
                    $k['rank'] = (string)$pos;
                }
            }
            unset($k);
        }

        $sec['items'] = $kept;
        $sec['live']  = $live;
    }
    unset($sec);

    return $sections;
}

/**
 * Point d'entrée : applique la séparation terminé / en cours au classement.
 *
 * @return array ['sections' => ..., 'multi' => vrai s'il y a plusieurs départs]
 */
function ianselp_SplitStages($TourId, array $sections)
{
    $plan  = ianselp_SessionPlan($TourId);
    $multi = count($plan) > 1;
    if (!$multi || !$sections) {
        return array('sections' => $sections, 'multi' => $multi);
    }

    // Tableau des points par lettre ($LetterPoint) ; défini au niveau global par le fichier.
    require_once('Common/Lib/ArrTargets.inc.php');

    $Tour = safe_fetch(safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId=" . StrSafe_DB($TourId)));
    $rows = ianselp_StageRows($TourId, $Tour ? $Tour->ToNumDist : 1);

    return array(
        'sections' => ianselp_StageLogic($sections, $rows, $plan, ianselp_StageChars($TourId)),
        'multi'    => true,
    );
}
