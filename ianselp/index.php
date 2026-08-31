<?php
/**
 * ianselp — Affichage des scores en direct
 * Page de pilotage : réglages de l'affichage et lancement de l'écran.
 */

// Bootstrap : Modules/Custom/config.php relaie jusqu'à htdocs/config.php
require_once(dirname(dirname(__FILE__)) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once(dirname(__FILE__) . '/Lib/Fun_ianselp.php');

CheckTourSession(true);
checkACL(AclOutput, AclReadOnly);

$TourId    = $_SESSION['TourId'];
$Saved     = false;
$Recalc    = null;   // null = pas demandé, true/false = résultat

// ---------------------------------------------------------------------------
// Recalcul du classement (même opération que le cœur après une feuille de marque)
// ---------------------------------------------------------------------------
if (!empty($_POST['recalc'])) {
    checkACL(AclOutput, AclReadWrite);
    $Recalc = ianselp_Recalculate($TourId);
}

// ---------------------------------------------------------------------------
// Enregistrement des réglages
// ---------------------------------------------------------------------------
if (!empty($_POST['save'])) {
    checkACL(AclOutput, AclReadWrite);

    $events = array();
    if (!empty($_POST['events']) && is_array($_POST['events'])) {
        foreach ($_POST['events'] as $code) {
            $events[] = (string)$code;
        }
    }

    ianselp_SaveConfig($TourId, array(
        'events'    => $events,
        'scrollsec' => isset($_POST['scrollsec']) ? (int)$_POST['scrollsec'] : 20,
        'refresh'   => isset($_POST['refresh'])   ? (int)$_POST['refresh']   : 10,
        'rows'      => isset($_POST['rows'])      ? (int)$_POST['rows']      : 12,
        'dist'      => isset($_POST['dist'])      ? (int)$_POST['dist']      : 0,
        'title'     => isset($_POST['title'])     ? trim($_POST['title'])    : '',
    ));
    $Saved = true;
}

$cfg    = ianselp_GetConfig($TourId);
$Events = ianselp_EventList($TourId);
$Stale  = ianselp_StaleCount($TourId);

$Tour = safe_fetch(safe_r_sql(
    "SELECT ToName, ToNumDist FROM Tournament WHERE ToId=" . StrSafe_DB($TourId)
));

// Nom du dossier déduit : le module reste fonctionnel s'il est installé ou
// cloné sous un autre nom.
$liveUrl = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__FILE__)) . '/live.php?tour=' . $TourId;

// Schéma réel : une installation derrière HTTPS (VPS) ou derrière un reverse
// proxy ne doit pas se voir proposer une URL en http://.
$scheme = 'http';
if ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
    $scheme = 'https';
}
$absUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $liveUrl;

// ---------------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------------
$PAGE_TITLE    = 'Scores en direct';
$IncludeJquery = true;
$IncludeFA     = true;

include('Common/Templates/head.php');
?>

<h1>Scores en direct</h1>

<?php if ($Saved) { ?>
<p style="padding:.5em;background:#dff0d8;border:1px solid #b2d8a7;">
    <i class="fa fa-check"></i> Réglages enregistrés.
</p>
<?php } ?>

<?php if ($Recalc === true) { ?>
<p style="padding:.5em;background:#dff0d8;border:1px solid #b2d8a7;">
    <i class="fa fa-check"></i> Classement recalculé.
</p>
<?php } elseif ($Recalc === false) { ?>
<p style="padding:.5em;background:#f2dede;border:1px solid #e0b4b4;">
    <i class="fa fa-times"></i> Le recalcul du classement a échoué.
</p>
<?php } ?>

<?php if ($Stale) { ?>
<div style="padding:.7em;background:#fcf8e3;border-left:4px solid #f0ad4e;margin-bottom:1em;">
    <b><i class="fa fa-exclamation-triangle"></i>
    <?php echo $Stale; ?> archer(s) ont tiré mais n'ont pas de rang calculé.</b><br>
    L'affichage montrera leur score sans numéro de rang. IANSEO recalcule le classement
    à chaque enregistrement de feuille de marque ; si les scores sont arrivés autrement
    (import, outil externe, simulation), lancez le recalcul.
    <form method="post" action="index.php" style="display:inline;">
        <input type="hidden" name="recalc" value="1">
        <input type="submit" value="Recalculer le classement">
    </form>
</div>
<?php } ?>

<div style="display:flex;align-items:center;gap:1.5em;margin:.8em 0;">
    <a class="Link" href="<?php echo $liveUrl; ?>" target="_blank" rel="noopener">
        <i class="fa fa-tv"></i> <b>Ouvrir l'affichage</b>
    </a>
    <a class="Link" href="<?php echo $liveUrl; ?>&amp;demo=1" target="_blank" rel="noopener">
        <i class="fa fa-flask"></i> Aperçu avec des données fictives
    </a>
    <form method="post" action="index.php" style="margin:0;">
        <input type="hidden" name="recalc" value="1">
        <input type="submit" value="Recalculer le classement">
    </form>
</div>

<p style="color:#555;">
    Sur le poste relié au vidéoprojecteur, ouvrez&nbsp;:
    <code><?php echo htmlspecialchars($absUrl); ?></code><br>
    L'écran fonctionne sans session IANSEO. Touche <b>F</b> pour le plein écran,
    <b>Espace</b> pour figer la rotation, <b>&larr; &rarr;</b> pour changer d'écran.
</p>

<form method="post" action="index.php">
<input type="hidden" name="save" value="1">

<table class="Tabella">
    <tr class="Titolo"><th colspan="2">Réglages de l'affichage</th></tr>

    <tr class="Modifica">
        <td style="width:35%;">Titre affiché en haut à gauche</td>
        <td>
            <input type="text" name="title" size="50" maxlength="80"
                   value="<?php echo htmlspecialchars($cfg['title']); ?>"
                   placeholder="<?php echo htmlspecialchars($Tour->ToName); ?>">
            <span style="color:#777;">(vide = nom du concours)</span>
        </td>
    </tr>

    <tr>
        <td>Classement affiché</td>
        <td>
            <select name="dist">
                <option value="0"<?php echo $cfg['dist'] == 0 ? ' selected' : ''; ?>>Total du concours</option>
<?php for ($d = 1; $d <= (int)$Tour->ToNumDist; $d++) { ?>
                <option value="<?php echo $d; ?>"<?php echo $cfg['dist'] == $d ? ' selected' : ''; ?>>
                    Distance / série <?php echo $d; ?>
                </option>
<?php } ?>
            </select>
        </td>
    </tr>

    <tr class="Modifica">
        <td>Lignes visibles à l'écran</td>
        <td>
            <input type="number" name="rows" min="4" max="40" value="<?php echo (int)$cfg['rows']; ?>">
            <span style="color:#777;">(pilote la taille du texte : moins de lignes = plus gros)</span>
        </td>
    </tr>

    <tr>
        <td>Vitesse de défilement</td>
        <td>
            <input type="number" name="scrollsec" min="5" max="120" value="<?php echo (int)$cfg['scrollsec']; ?>">
            <span style="color:#777;">secondes pour faire défiler une hauteur d'écran — plus grand = plus lent</span>
        </td>
    </tr>

    <tr class="Modifica">
        <td>Rafraîchissement des scores (secondes)</td>
        <td><input type="number" name="refresh" min="3" max="300" value="<?php echo (int)$cfg['refresh']; ?>"></td>
    </tr>
</table>

<br>

<table class="Tabella">
    <tr class="Titolo">
        <th colspan="3">
            Épreuves affichées
            <span style="font-weight:normal;">— aucune cochée = toutes</span>
        </th>
    </tr>
    <tr>
        <th style="width:5%;"></th>
        <th>Épreuve</th>
        <th style="width:20%;">Archers classés</th>
    </tr>
<?php if (empty($Events)) { ?>
    <tr><td colspan="3">Aucune épreuve individuelle définie pour ce concours.</td></tr>
<?php } else {
    $i = 0;
    foreach ($Events as $ev) {
        $i++;
        $checked = in_array($ev['code'], $cfg['events'], true); ?>
    <tr class="<?php echo ($i % 2 ? 'Modifica' : ''); ?>">
        <td align="center">
            <input type="checkbox" name="events[]" id="ev_<?php echo htmlspecialchars($ev['code']); ?>"
                   value="<?php echo htmlspecialchars($ev['code']); ?>"<?php echo $checked ? ' checked' : ''; ?>>
        </td>
        <td>
            <label for="ev_<?php echo htmlspecialchars($ev['code']); ?>">
                <?php echo htmlspecialchars($ev['name']); ?>
                <span style="color:#777;">(<?php echo htmlspecialchars($ev['code']); ?>)</span>
            </label>
        </td>
        <td align="center"<?php echo $ev['nb'] ? '' : ' style="color:#999;"'; ?>><?php echo $ev['nb']; ?></td>
    </tr>
<?php } } ?>
    <tr class="Titolo">
        <td colspan="3" align="center">
            <a href="#" id="selNone">tout décocher</a> &nbsp;|&nbsp;
            <a href="#" id="selAll">tout cocher</a>
        </td>
    </tr>
</table>

<br>
<div align="center"><input type="submit" value="Enregistrer les réglages"></div>
</form>

<?php
$POST_TAIL = '<script>
$(function () {
    $("#selAll").on("click", function (e) { e.preventDefault(); $("input[name=\'events[]\']").prop("checked", true); });
    $("#selNone").on("click", function (e) { e.preventDefault(); $("input[name=\'events[]\']").prop("checked", false); });
});
</script>';

include('Common/Templates/tail.php');
