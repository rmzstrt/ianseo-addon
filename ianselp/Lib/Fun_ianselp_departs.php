<?php
/**
 * ianselp — Affichage des scores en direct
 * Plusieurs départs : classement à volée équivalente.
 *
 * Seuls comptent les premiers départs des archers (EnIndFEvent=1) : c'est le
 * classement de la compétition. Les tirs supplémentaires (EnIndFEvent=0) ne sont
 * jamais affichés.
 *
 * Le problème : quand le 2e départ commence, les archers du 1er sont terminés
 * (60 flèches) et ceux du 2e en cours (6 flèches). Les classer ensemble relègue
 * les seconds tout en bas, alors qu'ils peuvent mener.
 *
 * La solution : tant qu'un archer en cours est précédé, dans la même épreuve,
 * d'un archer terminé d'un départ antérieur, l'épreuve est classée « à la
 * volée N », N étant la volée atteinte par les archers en cours. Les archers du
 * 1er départ y figurent avec leur score après N volées, comme s'ils venaient de
 * tirer cette volée en même temps que ceux du 2e départ ; leur score final reste
 * indiqué en regard. Quand tout le monde a fini, on retrouve le classement
 * officiel.
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

/** Flèches et nombre de flèches des archers qui comptent (premiers départs). */
function ianselp_StageRows($TourId, $numDist)
{
    $numDist = max(1, min(8, (int)$numDist));
    $cols = '';
    for ($d = 1; $d <= $numDist; $d++) {
        $cols .= ", q.QuD{$d}Arrowstring AS S{$d}";
    }

    $rows = array();
    $Rs = safe_r_sql(
        "SELECT e.EnId, q.QuHits{$cols}
           FROM Entries AS e
          INNER JOIN Qualifications AS q ON q.QuId = e.EnId
          WHERE e.EnTournament=" . StrSafe_DB($TourId) . "
            AND e.EnAthlete=1 AND e.EnStatus<=1 AND e.EnIndFEvent=1 AND q.QuHits>0"
    );
    while ($r = safe_fetch($Rs)) {
        $strings = array();
        for ($d = 1; $d <= $numDist; $d++) {
            $col = 'S' . $d;
            $strings[$d] = (string)$r->$col;
        }
        $rows[(int)$r->EnId] = array('hits' => (int)$r->QuHits, 'strings' => $strings);
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

/** vrai si (score,ors,neufs) $a bat $b. */
function ianselp_Beats($a, $b)
{
    if ($a[0] !== $b[0]) { return $a[0] > $b[0]; }
    if ($a[1] !== $b[1]) { return $a[1] > $b[1]; }
    return $a[2] > $b[2];
}

/**
 * Renumérote les rangs numériques sans trous, en gardant les égalités du
 * classement officiel (les rangs vides ou les codes IRM sont laissés tels quels).
 */
function ianselp_Renumber(array $items)
{
    $pos = 0;
    $prevOld = null;
    $prevNew = null;
    foreach ($items as &$it) {
        if (!is_numeric($it['rank']) || (int)$it['rank'] <= 0) {
            continue;
        }
        $pos++;
        if ($prevOld !== null && (int)$it['rank'] === $prevOld) {
            $it['rank'] = (string)$prevNew;
        } else {
            $prevOld = (int)$it['rank'];
            $prevNew = $pos;
            $it['rank'] = (string)$pos;
        }
    }
    unset($it);
    return $items;
}

/**
 * Logique pure, sans accès à la base.
 *
 * Pour chaque épreuve où des archers en cours suivent des archers déjà
 * terminés d'un départ antérieur, classe tous les archers à la volée N.
 * Les sections concernées reçoivent :
 *   eq       la volée N du classement ;
 *   ends     le nombre total de volées du départ ;
 *   eqTotal  le nombre d'archers classés ;
 *   eqBest   le meilleur score à la volée N ;
 * et chaque item : rank, score, gold, xnine à la volée N, ainsi que
 *   final    le score final, pour un archer terminé (sinon null) ;
 *   end      les volées terminées, pour un archer en cours (sinon null).
 *
 * @param array $sections sections du classement officiel ; chaque item porte
 *                        id, session, hits, score, rank, athlete, club, target, gold, xnine
 * @param array $rows     ianselp_StageRows
 * @param array $plan     ianselp_SessionPlan
 * @param array $chars    ianselp_StageChars
 */
function ianselp_StageLogic(array $sections, array $rows, array $plan, array $chars)
{
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

        // 2. Les archers « suiveurs » : en cours, alors qu'un archer d'un départ
        //    antérieur est déjà terminé dans cette épreuve.
        $minDone = null;
        foreach ($sec['items'] as $it) {
            $s = (int)$it['session'];
            if (!empty($info[(int)$it['id']]['done']) && $s >= 1 && ($minDone === null || $s < $minDone)) {
                $minDone = $s;
            }
        }
        $chasers = array();
        foreach ($sec['items'] as $it) {
            $i = $info[(int)$it['id']];
            if (empty($i['done']) && $minDone !== null && $i['s'] > $minDone) {
                $chasers[(int)$it['id']] = $i;
            }
        }
        if (!$chasers) {
            continue;   // classement officiel, tel quel
        }

        // 3. La volée de comparaison : la plus basse atteinte par les suiveurs
        //    (ceux qui n'ont pas encore fini leur première volée n'en décident pas).
        $N = 0;
        foreach ($chasers as $c) {
            if ($c['n'] >= 1 && ($N === 0 || $c['n'] < $N)) {
                $N = $c['n'];
            }
        }
        if ($N === 0) {
            // Personne n'a fini une volée : rien à comparer, les suiveurs attendent.
            $kept = array();
            foreach ($sec['items'] as $it) {
                if (!isset($chasers[(int)$it['id']])) {
                    $kept[] = $it;
                }
            }
            $sec['items'] = ianselp_Renumber($kept);
            continue;
        }

        // 4. Classement à la volée N.
        $ranked = array();
        $tail   = array();
        foreach ($sec['items'] as $it) {
            $i = $info[(int)$it['id']];
            if (!isset($i['n'])) {
                $it['final'] = null;   // IRM : en fin de liste, tel quel
                $it['end']   = null;
                $tail[] = $it;
                continue;
            }
            $upTo = ($i['n'] >= $N) ? $N : $i['n'];   // un archer en retard n'a pas encore sa volée N
            $at   = $i['c'][$upTo];
            $row  = array(
                'rank'    => '',
                'id'      => $it['id'],
                'session' => $i['s'],
                'athlete' => $it['athlete'],
                'club'    => $it['club'],
                'target'  => $it['target'],
                'score'   => $at[0],
                'gold'    => $at[1],
                'xnine'   => $at[2],
                'hits'    => $it['hits'],
                'final'   => $i['done'] ? (string)$it['score'] : null,
                'end'     => $i['done'] ? null : $i['n'],
            );
            if ($i['n'] >= $N) {
                $row['k'] = $at;
                $ranked[] = $row;
            } else {
                $tail[] = $row;
            }
        }

        usort($ranked, function ($a, $b) {
            if (ianselp_Beats($a['k'], $b['k'])) { return -1; }
            if (ianselp_Beats($b['k'], $a['k'])) { return 1; }
            $c = strcmp($a['athlete'], $b['athlete']);
            return $c !== 0 ? $c : ((int)$a['id'] - (int)$b['id']);
        });
        $rank = 0;
        foreach ($ranked as $pos => $r) {
            if ($pos === 0 || ianselp_Beats($ranked[$pos - 1]['k'], $r['k'])) {
                $rank = $pos + 1;
            }
            $ranked[$pos]['rank'] = (string)$rank;
        }
        foreach ($ranked as $pos => $r) {
            unset($ranked[$pos]['k']);
        }

        $firstChaser = reset($chasers);
        $sec['items']   = array_merge($ranked, $tail);
        $sec['eq']      = $N;
        $sec['ends']    = $plan[$firstChaser['s']]['ends'];
        $sec['eqTotal'] = count($ranked);
        $sec['eqBest']  = $ranked ? $ranked[0]['score'] : null;
    }
    unset($sec);

    return $sections;
}

/**
 * Point d'entrée : applique le classement à volée équivalente quand il le faut.
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
