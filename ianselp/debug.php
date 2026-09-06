<?php
/**
 * PAGE DE DIAGNOSTIC — Debug du module ianselp
 * Affiche exactement quelles catégories sont retournées pour ce tournoi
 */

require_once(dirname(dirname(__FILE__)) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once(dirname(__FILE__) . '/Lib/Fun_ianselp.php');

CheckTourSession(true);
checkACL(AclOutput, AclReadOnly);

$TourId = $_SESSION['TourId'];

// Récupérer les infos du tournoi
$Tour = safe_fetch(safe_r_sql(
    "SELECT ToId, ToName, ToCode, ToNumDist FROM Tournament WHERE ToId=" . StrSafe_DB($TourId)
));

// Récupérer les Events
$Events = ianselp_EventList($TourId);

// Requête RAW pour voir ce que retourne directement la DB
$RawEvents = safe_r_sql(
    "SELECT e.EvCode, e.EvEventName, e.EvProgr, e.EvTournament,
            COUNT(i.IndId) as Nb
       FROM Events AS e
       LEFT JOIN Individuals AS i ON i.IndEvent = e.EvCode
                                 AND i.IndTournament = e.EvTournament
      WHERE e.EvTournament=" . StrSafe_DB($TourId) . "
        AND e.EvTeamEvent=0
      GROUP BY e.EvCode, e.EvEventName, e.EvProgr, e.EvTournament
      ORDER BY e.EvProgr, e.EvCode"
);

// Classes configurées
$Classes = safe_r_sql(
    "SELECT ClId, ClDescription FROM Classes
     WHERE ClTournament=" . StrSafe_DB($TourId) . "
     ORDER BY ClViewOrder"
);

$PAGE_TITLE = 'Diagnostic ianselp';
include('Common/Templates/head.php');
?>

<style>
.diagnostic {
    background: #f5f5f5;
    padding: 20px;
    border-radius: 5px;
    margin: 20px 0;
    border-left: 5px solid #2196F3;
}
.diagnostic h3 {
    margin-top: 0;
    color: #1976D2;
}
.diagnostic table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    margin-top: 10px;
}
.diagnostic th, .diagnostic td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}
.diagnostic th {
    background: #e3f2fd;
    font-weight: bold;
}
.diagnostic tr:hover {
    background: #f0f0f0;
}
.diagnostic .empty {
    color: #999;
    font-style: italic;
}
.diagnostic .warning {
    background: #fff3cd;
    border-left: 5px solid #ffc107;
}
</style>

<h1>Diagnostic du module Scores en direct (ianselp)</h1>

<div class="diagnostic">
    <h3>📋 Tournoi actif</h3>
    <table>
        <tr>
            <th>ToId</th>
            <th>Nom</th>
            <th>Code</th>
            <th>Distances</th>
        </tr>
        <tr>
            <td><?php echo $Tour->ToId; ?></td>
            <td><?php echo htmlspecialchars($Tour->ToName); ?></td>
            <td><?php echo htmlspecialchars($Tour->ToCode); ?></td>
            <td><?php echo $Tour->ToNumDist; ?></td>
        </tr>
    </table>
</div>

<div class="diagnostic">
    <h3>📦 Classes CONFIGURÉES pour ce tournoi</h3>
    <table>
        <tr>
            <th>ClId</th>
            <th>Description</th>
        </tr>
<?php
$classCount = 0;
while ($row = safe_fetch($Classes)) {
    $classCount++;
    echo "<tr><td>" . htmlspecialchars($row->ClId) . "</td>";
    echo "<td>" . htmlspecialchars($row->ClDescription) . "</td></tr>";
}
if ($classCount == 0) {
    echo '<tr><td colspan="2" class="empty">❌ Aucune classe configurée</td></tr>';
}
?>
    </table>
</div>

<div class="diagnostic">
    <h3>⚙️ Events RETOURNÉES par ianselp_EventList()</h3>
    <table>
        <tr>
            <th>EvCode</th>
            <th>Nom</th>
            <th>Prog</th>
            <th>Archers classés</th>
        </tr>
<?php
if (empty($Events)) {
    echo '<tr><td colspan="4" class="empty">❌ Aucun événement retourné</td></tr>';
} else {
    foreach ($Events as $code => $ev) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($code) . "</strong></td>";
        echo "<td>" . htmlspecialchars($ev['name']) . "</td>";
        echo "<td>" . $ev['progr'] . "</td>";
        echo "<td>" . $ev['nb'] . "</td>";
        echo "</tr>";
    }
}
?>
    </table>
</div>

<div class="diagnostic">
    <h3>🔍 Events BRUTS dans la base (requête directe)</h3>
    <table>
        <tr>
            <th>EvCode</th>
            <th>EvEventName</th>
            <th>EvTournament</th>
            <th>EvProgr</th>
            <th>Nb Individuals</th>
        </tr>
<?php
$rawCount = 0;
while ($row = safe_fetch($RawEvents)) {
    $rawCount++;
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($row->EvCode) . "</strong></td>";
    echo "<td>" . htmlspecialchars($row->EvEventName) . "</td>";
    echo "<td>" . $row->EvTournament . "</td>";
    echo "<td>" . $row->EvProgr . "</td>";
    echo "<td>" . $row->Nb . "</td>";
    echo "</tr>";
}
if ($rawCount == 0) {
    echo '<tr><td colspan="5" class="empty">❌ Aucun événement trouvé pour ce tournoi</td></tr>';
}
?>
    </table>
</div>

<div class="diagnostic warning">
    <h3>⚠️ Diagnostic</h3>
<?php
if ($classCount > 0 && $rawCount == 0) {
    echo "<p><strong>PROBLÈME IDENTIFIÉ :</strong> Vous avez configuré <strong>$classCount classe(s)</strong>, ";
    echo "mais <strong>aucune Event</strong> n'existe dans la table Events pour ce tournoi.</p>";
    echo "<p>Cela peut arriver si :</p>";
    echo "<ul>";
    echo "<li>Les Events n'ont pas été créées quand vous avez créé le tournoi</li>";
    echo "<li>Elles ont été supprimées accidentellement</li>";
    echo "<li>Il y a un problème de synchronisation entre Classes et Events</li>";
    echo "</ul>";
} else if ($classCount > 0 && $rawCount > 0) {
    echo "<p><strong>✓ OK :</strong> Les Events existent et sont retournées correctement.</p>";
    if (count($Events) != $rawCount) {
        echo "<p><strong>⚠️ ATTENTION :</strong> Le nombre d'Events bruts ($rawCount) diffère de celui retourné par la fonction (" . count($Events) . ").</p>";
    }
} else {
    echo "<p><strong>❌ ERREUR :</strong> Aucune classe configurée pour ce tournoi.</p>";
}
?>
</div>

<?php
$POST_TAIL = '<script>
console.log("TourId: ' . $TourId . '");
console.log("Classes: ' . $classCount . '");
console.log("Events raw: ' . $rawCount . '");
console.log("Events returned: ' . count($Events) . '");
</script>';

include('Common/Templates/tail.php');
?>
