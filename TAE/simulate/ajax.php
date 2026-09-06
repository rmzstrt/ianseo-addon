<?php
/**
 * TAE - Backend de simulation
 * Gestion des actions AJAX (ajout de flèches, reset, complétion)
 */

// Log every request to a file for debugging (defined FIRST, before anything can fail)
function taeLog($msg) {
    $logFile = dirname(__FILE__) . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}
taeLog("=== NEW REQUEST === action=" . ($_POST['action'] ?? 'NONE') . " | POST=" . json_encode($_POST));

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        taeLog("FATAL ERROR: " . $error['message'] . " in " . $error['file'] . " line " . $error['line']);
    }
});

// Remontée jusqu'à Modules/Custom/config.php (3 dirname depuis TAE/simulate/)
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
taeLog("config.php loaded OK");
require_once('Common/Fun_Various.inc.php');
taeLog("Fun_Various.inc.php loaded OK");

// Session + ACL
CheckTourSession(true);
taeLog("CheckTourSession OK");
checkACL(AclParticipants, AclReadWrite);
taeLog("checkACL OK");

// En-tête JSON
header('Content-Type: application/json; charset=utf-8');

$TourId = isset($_POST['TourId']) ? (int)$_POST['TourId'] : $_SESSION['TourId'];
taeLog("TourId=$TourId");

/**
 * Détecte le type de tournoi (INDOOR = 3 flèches, OUTDOOR = 6 flèches)
 */
function getTournamentTypeForSimulation($TourId) {
    $TourId = (int)$TourId;
    $query = "SELECT ToTypeName, ToType FROM Tournament WHERE ToId = $TourId";
    $rs = safe_r_sql($query);

    if (!$rs) {
        return 'outdoor';
    }

    $row = safe_fetch($rs);
    if (!$row) {
        return 'outdoor';
    }

    // Chercher simplement "Indoor" ou "18" dans ToTypeName
    $toTypeName = $row->ToTypeName;

    // Case insensitive search
    if (stripos($toTypeName, 'Indoor') !== false) {
        return 'indoor';
    }
    if (stripos($toTypeName, 'Salle') !== false) {
        return 'indoor';
    }
    if (stripos($toTypeName, '18') !== false) {
        return 'indoor';
    }

    return 'outdoor';
}

function letterToScore($letter) {
    $conversion = array(
        'A' => 0, 'M' => 0,
        'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4,
        'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9,
        'K' => 10, 'L' => 10, 'X' => 10
    );
    $letter = strtoupper($letter);
    return isset($conversion[$letter]) ? $conversion[$letter] : 0;
}

function calculateStats($arrowString) {
    $hits = 0;
    $gold = 0;
    $xnine = 0;

    if (empty($arrowString)) {
        return array('hits' => 0, 'gold' => 0, 'xnine' => 0);
    }

    for ($i = 0; $i < strlen($arrowString); $i++) {
        $letter = strtoupper($arrowString[$i]);
        $hits++;

        if ($letter == 'K' || $letter == 'L' || $letter == 'X') {
            $gold++;
        } elseif ($letter == 'J') {
            $xnine++;
        }
    }

    return array('hits' => $hits, 'gold' => $gold, 'xnine' => $xnine);
}

function calculateScore($str) {
    if (empty($str)) return 0;
    $total = 0;
    for ($i = 0; $i < strlen($str); $i++) {
        $total += letterToScore($str[$i]);
    }
    return $total;
}

function getArcherTypeSimple($division) {
    $keywords = array('Compound', 'CO', 'Cmp', 'COMPOUND');
    foreach ($keywords as $kw) {
        if (stripos($division, $kw) !== false) return 'co';
    }
    return 'spot';
}

function generateRandomLetter($archerType) {
    if ($archerType === 'co') {
        $letters = array('F', 'G', 'H', 'I', 'J', 'L', 'K', 'A');
    } else {
        $letters = array('B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'A');
    }
    return $letters[array_rand($letters)];
}

function generateVolley($archerType, $arrowType, $arrowsPerEnd = 3) {
    $volley = '';
    if ($arrowType === 'all_10') {
        $volley = str_repeat('X', $arrowsPerEnd);
    } elseif ($arrowType === 'all_9') {
        $volley = str_repeat('J', $arrowsPerEnd);
    } elseif ($arrowType === 'all_8') {
        $volley = str_repeat('I', $arrowsPerEnd);
    } else {
        // Aléatoire
        for ($i = 0; $i < $arrowsPerEnd; $i++) {
            $volley .= generateRandomLetter($archerType);
        }
    }
    return $volley;
}

try {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // Déterminer le type de tournoi
    $tournamentType = getTournamentTypeForSimulation($TourId);
    $arrowsPerEnd = ($tournamentType === 'indoor') ? 3 : 6;
    $maxArrowsPerDistance = ($tournamentType === 'indoor') ? 30 : 36;
    $totalArrowsMax = $maxArrowsPerDistance * 2;

    // Charger tous les archers et leurs qualifications
    $query = "
        SELECT
            e.EnId, e.EnFirstName, e.EnName, e.EnDivision,
            q.QuId, q.QuSession, q.QuTarget, q.QuLetter,
            q.QuD1Arrowstring, q.QuD2Arrowstring,
            q.QuD1Score, q.QuD2Score,
            q.QuD1Hits, q.QuD1Gold, q.QuD1Xnine,
            q.QuD2Hits, q.QuD2Gold, q.QuD2Xnine,
            q.QuConfirm
        FROM Entries e
        INNER JOIN Qualifications q ON e.EnId = q.QuId
        WHERE e.EnTournament = " . StrSafe_DB($TourId) . " AND e.EnStatus = 'A'
        ORDER BY q.QuSession, q.QuTarget, q.QuLetter
    ";

    $rs = safe_r_sql($query);
    if (!$rs) {
        throw new Exception("Erreur requête: impossible de charger les archers");
    }

    $archers = array();
    while ($row = safe_fetch($rs)) {
        $archers[] = array(
            'id' => $row->EnId,
            'session' => $row->QuSession,
            'type' => getArcherTypeSimple($row->EnDivision),
            'd1_str' => $row->QuD1Arrowstring,
            'd2_str' => $row->QuD2Arrowstring,
            'd1_score' => (int)$row->QuD1Score,
            'd2_score' => (int)$row->QuD2Score,
            'firstname' => $row->EnFirstName,
            'lastname' => $row->EnName,
            'target' => $row->QuTarget,
            'letter' => $row->QuLetter
        );
    }

    // ACTION: get_data
    if ($action === 'get_data') {
        $list = array();
        $totalArrows = 0;
        $totalScore = 0;

        foreach ($archers as $a) {
            $d1 = strlen($a['d1_str']);
            $d2 = strlen($a['d2_str']);
            $totalArrows += $d1 + $d2;
            $totalScore += $a['d1_score'] + $a['d2_score'];

            // Dernière volée (dernières N flèches selon le type)
            $lastVolleySize = $arrowsPerEnd;
            $last1 = ($d1 >= $lastVolleySize) ? substr($a['d1_str'], -$lastVolleySize) : $a['d1_str'];
            $last2 = ($d2 >= $lastVolleySize) ? substr($a['d2_str'], -$lastVolleySize) : $a['d2_str'];

            $list[] = array(
                'id' => $a['id'],
                'session' => $a['session'],
                'target' => $a['target'],
                'targetLetter' => $a['letter'],
                'firstName' => $a['firstname'],
                'lastName' => $a['lastname'],
                'archerType' => ($a['type'] === 'co') ? 'CO' : 'Spot',
                'arrowsD1' => $d1,
                'arrowsD2' => $d2,
                'scoreD1' => $a['d1_score'],
                'scoreD2' => $a['d2_score'],
                'lastVolleyD1' => $last1,
                'lastVolleyD2' => $last2
            );
        }

        echo json_encode(array(
            'success' => true,
            'archers' => $list,
            'tournamentType' => $tournamentType,
            'arrowsPerEnd' => $arrowsPerEnd,
            'maxArrowsPerDistance' => $maxArrowsPerDistance,
            'totalArrowsMax' => $totalArrowsMax,
            'stats' => array(
                'total_archers' => count($list),
                'total_arrows' => $totalArrows,
                'total_score' => $totalScore
            )
        ));
        exit;
    }

    // ACTION: add_arrows
    if ($action === 'add_arrows') {
        taeLog("Entering add_arrows, " . count($archers) . " archers loaded");
        $numVolleys = isset($_POST['num_volleys']) ? (int)$_POST['num_volleys'] : 1;
        $group = isset($_POST['archer_group']) ? $_POST['archer_group'] : 'all';
        $arrowType = isset($_POST['arrow_type']) ? $_POST['arrow_type'] : 'random';
        $targetDistance = isset($_POST['target_distance']) ? $_POST['target_distance'] : 'both';

        $affected = 0;
        $totalArrowsAdded = 0;

        foreach ($archers as &$a) {
            // Filtrer par groupe
            $skip = false;
            if ($group === 'co_only' && $a['type'] !== 'co') $skip = true;
            if ($group === 'spot_only' && $a['type'] !== 'spot') $skip = true;
            if ($group === 'session1' && $a['session'] != 1) $skip = true;
            if ($group === 'session2' && $a['session'] != 2) $skip = true;

            if ($skip) continue;
            $affected++;

            // Ajouter aux distances sélectionnées
            if ($targetDistance === 'd1' || $targetDistance === 'both') {
                for ($i = 0; $i < $numVolleys; $i++) {
                    $currentLen = strlen($a['d1_str']);
                    if ($currentLen + $arrowsPerEnd <= $maxArrowsPerDistance) {
                        $volley = generateVolley($a['type'], $arrowType, $arrowsPerEnd);
                        $a['d1_str'] .= $volley;
                        $totalArrowsAdded += $arrowsPerEnd;
                    } else {
                        $remaining = $maxArrowsPerDistance - $currentLen;
                        if ($remaining > 0) {
                            $volley = generateVolley($a['type'], $arrowType, $remaining);
                            $a['d1_str'] .= $volley;
                            $totalArrowsAdded += $remaining;
                        }
                    }
                }
            }

            if ($targetDistance === 'd2' || $targetDistance === 'both') {
                for ($i = 0; $i < $numVolleys; $i++) {
                    $currentLen = strlen($a['d2_str']);
                    if ($currentLen + $arrowsPerEnd <= $maxArrowsPerDistance) {
                        $volley = generateVolley($a['type'], $arrowType, $arrowsPerEnd);
                        $a['d2_str'] .= $volley;
                        $totalArrowsAdded += $arrowsPerEnd;
                    } else {
                        $remaining = $maxArrowsPerDistance - $currentLen;
                        if ($remaining > 0) {
                            $volley = generateVolley($a['type'], $arrowType, $remaining);
                            $a['d2_str'] .= $volley;
                            $totalArrowsAdded += $remaining;
                        }
                    }
                }
            }

            // Limiter au max
            $a['d1_str'] = substr($a['d1_str'], 0, $maxArrowsPerDistance);
            $a['d2_str'] = substr($a['d2_str'], 0, $maxArrowsPerDistance);

            // Recalculer les scores
            $newScoreD1 = calculateScore($a['d1_str']);
            $newScoreD2 = calculateScore($a['d2_str']);
            $statsD1 = calculateStats($a['d1_str']);
            $statsD2 = calculateStats($a['d2_str']);

            $totalScore = $newScoreD1 + $newScoreD2;
            $totalHits = $statsD1['hits'] + $statsD2['hits'];
            $totalGold = $statsD1['gold'] + $statsD2['gold'];
            $totalXnine = $statsD1['xnine'] + $statsD2['xnine'];

            // UPDATE
            $updateQuery = "UPDATE Qualifications SET
                QuD1Arrowstring = " . StrSafe_DB($a['d1_str']) . ",
                QuD2Arrowstring = " . StrSafe_DB($a['d2_str']) . ",
                QuD1Score = " . (int)$newScoreD1 . ",
                QuD2Score = " . (int)$newScoreD2 . ",
                QuD1Hits = " . (int)$statsD1['hits'] . ",
                QuD1Gold = " . (int)$statsD1['gold'] . ",
                QuD1Xnine = " . (int)$statsD1['xnine'] . ",
                QuD2Hits = " . (int)$statsD2['hits'] . ",
                QuD2Gold = " . (int)$statsD2['gold'] . ",
                QuD2Xnine = " . (int)$statsD2['xnine'] . ",
                QuScore = " . (int)$totalScore . ",
                QuHits = " . (int)$totalHits . ",
                QuGold = " . (int)$totalGold . ",
                QuXnine = " . (int)$totalXnine . "
                WHERE QuId = " . (int)$a['id'];

            safe_w_sql($updateQuery);
        }

        $distanceText = ($targetDistance === 'd1') ? 'D1' : (($targetDistance === 'd2') ? 'D2' : 'D1 et D2');
        $arrowText = ($arrowsPerEnd === 3) ? '3 flèches' : '6 flèches';

        taeLog("add_arrows completed: affected=$affected, totalArrowsAdded=$totalArrowsAdded");

        echo json_encode(array(
            'success' => true,
            'message' => $numVolleys . " volée(s) de " . $arrowText . " ajoutée(s) sur " . $distanceText . " pour " . $affected . " archers (" . $totalArrowsAdded . " flèches)"
        ));
        exit;
    }

    // ACTION: reset_arrows
    if ($action === 'reset_arrows') {
        $group = isset($_POST['archer_group']) ? $_POST['archer_group'] : 'all';
        $targetDistance = isset($_POST['target_distance']) ? $_POST['target_distance'] : 'both';
        $affected = 0;

        foreach ($archers as $a) {
            $skip = false;
            if ($group === 'co_only' && $a['type'] !== 'co') $skip = true;
            if ($group === 'spot_only' && $a['type'] !== 'spot') $skip = true;
            if ($group === 'session1' && $a['session'] != 1) $skip = true;
            if ($group === 'session2' && $a['session'] != 2) $skip = true;

            if ($skip) continue;
            $affected++;

            $setClauses = array();

            if ($targetDistance === 'd1' || $targetDistance === 'both') {
                $setClauses[] = "QuD1Arrowstring = ''";
                $setClauses[] = "QuD1Score = 0";
                $setClauses[] = "QuD1Hits = 0";
                $setClauses[] = "QuD1Gold = 0";
                $setClauses[] = "QuD1Xnine = 0";
            }

            if ($targetDistance === 'd2' || $targetDistance === 'both') {
                $setClauses[] = "QuD2Arrowstring = ''";
                $setClauses[] = "QuD2Score = 0";
                $setClauses[] = "QuD2Hits = 0";
                $setClauses[] = "QuD2Gold = 0";
                $setClauses[] = "QuD2Xnine = 0";
            }

            if ($targetDistance === 'both') {
                $setClauses[] = "QuScore = 0";
                $setClauses[] = "QuHits = 0";
                $setClauses[] = "QuGold = 0";
                $setClauses[] = "QuXnine = 0";
                $setClauses[] = "QuClRank = 0";
            }

            $updateQuery = "UPDATE Qualifications SET " . implode(", ", $setClauses) . " WHERE QuId = " . (int)$a['id'];
            safe_w_sql($updateQuery);
        }

        $distanceText = ($targetDistance === 'd1') ? 'D1' : (($targetDistance === 'd2') ? 'D2' : 'D1 et D2');

        echo json_encode(array(
            'success' => true,
            'message' => "Flèches réinitialisées sur " . $distanceText . " pour " . $affected . " archers"
        ));
        exit;
    }

    // ACTION: complete_session
    if ($action === 'complete_session') {
        $group = isset($_POST['archer_group']) ? $_POST['archer_group'] : 'all';
        $arrowType = isset($_POST['arrow_type']) ? $_POST['arrow_type'] : 'random';
        $targetDistance = isset($_POST['target_distance']) ? $_POST['target_distance'] : 'both';
        $affected = 0;
        $totalArrowsAdded = 0;

        foreach ($archers as &$a) {
            $skip = false;
            if ($group === 'co_only' && $a['type'] !== 'co') $skip = true;
            if ($group === 'spot_only' && $a['type'] !== 'spot') $skip = true;
            if ($group === 'session1' && $a['session'] != 1) $skip = true;
            if ($group === 'session2' && $a['session'] != 2) $skip = true;

            if ($skip) continue;
            $affected++;

            // Compléter D1
            if ($targetDistance === 'd1' || $targetDistance === 'both') {
                $currentLen = strlen($a['d1_str']);
                $needed = max(0, $maxArrowsPerDistance - $currentLen);

                while ($needed > 0) {
                    $arrowsToAdd = min($arrowsPerEnd, $needed);
                    $volley = generateVolley($a['type'], $arrowType, $arrowsToAdd);
                    $a['d1_str'] .= $volley;
                    $totalArrowsAdded += $arrowsToAdd;
                    $needed -= $arrowsToAdd;
                }
                $a['d1_str'] = substr($a['d1_str'], 0, $maxArrowsPerDistance);
            }

            // Compléter D2
            if ($targetDistance === 'd2' || $targetDistance === 'both') {
                $currentLen = strlen($a['d2_str']);
                $needed = max(0, $maxArrowsPerDistance - $currentLen);

                while ($needed > 0) {
                    $arrowsToAdd = min($arrowsPerEnd, $needed);
                    $volley = generateVolley($a['type'], $arrowType, $arrowsToAdd);
                    $a['d2_str'] .= $volley;
                    $totalArrowsAdded += $arrowsToAdd;
                    $needed -= $arrowsToAdd;
                }
                $a['d2_str'] = substr($a['d2_str'], 0, $maxArrowsPerDistance);
            }

            // Recalculer les scores
            $newScoreD1 = calculateScore($a['d1_str']);
            $newScoreD2 = calculateScore($a['d2_str']);
            $statsD1 = calculateStats($a['d1_str']);
            $statsD2 = calculateStats($a['d2_str']);

            $totalScore = $newScoreD1 + $newScoreD2;
            $totalHits = $statsD1['hits'] + $statsD2['hits'];
            $totalGold = $statsD1['gold'] + $statsD2['gold'];
            $totalXnine = $statsD1['xnine'] + $statsD2['xnine'];

            // UPDATE
            $updateQuery = "UPDATE Qualifications SET
                QuD1Arrowstring = " . StrSafe_DB($a['d1_str']) . ",
                QuD2Arrowstring = " . StrSafe_DB($a['d2_str']) . ",
                QuD1Score = " . (int)$newScoreD1 . ",
                QuD2Score = " . (int)$newScoreD2 . ",
                QuD1Hits = " . (int)$statsD1['hits'] . ",
                QuD1Gold = " . (int)$statsD1['gold'] . ",
                QuD1Xnine = " . (int)$statsD1['xnine'] . ",
                QuD2Hits = " . (int)$statsD2['hits'] . ",
                QuD2Gold = " . (int)$statsD2['gold'] . ",
                QuD2Xnine = " . (int)$statsD2['xnine'] . ",
                QuScore = " . (int)$totalScore . ",
                QuHits = " . (int)$totalHits . ",
                QuGold = " . (int)$totalGold . ",
                QuXnine = " . (int)$totalXnine . "
                WHERE QuId = " . (int)$a['id'];

            safe_w_sql($updateQuery);
        }

        $distanceText = ($targetDistance === 'd1') ? 'D1' : (($targetDistance === 'd2') ? 'D2' : 'D1 et D2');
        $arrowText = ($arrowsPerEnd === 3) ? '3 flèches' : '6 flèches';

        echo json_encode(array(
            'success' => true,
            'message' => "Session complétée sur " . $distanceText . " pour " . $affected . " archers (" . $totalArrowsAdded . " flèches ajoutées) - " . $arrowText . "/volée, max " . $maxArrowsPerDistance . " par distance"
        ));
        exit;
    }

    // Action inconnue
    taeLog("Unknown action: $action");
    echo json_encode(array('success' => false, 'message' => "Action non reconnue: " . htmlspecialchars($action)));

} catch (Exception $e) {
    taeLog("EXCEPTION CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
    echo json_encode(array('success' => false, 'message' => "Erreur: " . $e->getMessage()));
}
taeLog("=== END OF SCRIPT ===");
?>
