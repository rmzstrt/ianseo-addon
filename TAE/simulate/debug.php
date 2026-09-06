<?php
/**
 * TAE - Debug page for tournament type detection
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadWrite);

$TourId = $_SESSION['TourId'];

$query = "SELECT ToId, ToCode, ToTypeName, ToType FROM Tournament WHERE ToId = " . (int)$TourId;
$rs = safe_r_sql($query);
$row = safe_fetch($rs);

?>

<!DOCTYPE html>
<html>
<head>
    <title>TAE Debug - Tournament Type</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .box { background: #f0f0f0; border: 1px solid #ccc; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .value { font-family: monospace; background: #fff; padding: 10px; border: 1px solid #ddd; margin: 5px 0; }
        .label { font-weight: bold; color: #333; }
        .indent { margin-left: 20px; }
    </style>
</head>
<body>

<h1>🔍 TAE Debug - Tournament Type Detection</h1>

<div class="box">
    <div class="label">Tournament ID:</div>
    <div class="value"><?php echo (int)$TourId; ?></div>
</div>

<div class="box">
    <div class="label">Tournament Code:</div>
    <div class="value"><?php echo htmlspecialchars($row->ToCode); ?></div>
</div>

<div class="box">
    <div class="label">ToTypeName (RAW):</div>
    <div class="value"><?php echo htmlspecialchars($row->ToTypeName); ?></div>
    <div class="indent" style="color: #666; font-size: 12px;">
        Length: <?php echo strlen($row->ToTypeName); ?> chars<br>
        Lowercase: <?php echo strtolower($row->ToTypeName); ?><br>
        Trimmed: <?php echo strtolower(trim($row->ToTypeName)); ?>
    </div>
</div>

<div class="box">
    <div class="label">ToType (RAW):</div>
    <div class="value"><?php echo htmlspecialchars($row->ToType ?? 'NULL'); ?></div>
    <div class="indent" style="color: #666; font-size: 12px;">
        Length: <?php echo strlen($row->ToType ?? ''); ?> chars
    </div>
</div>

<div class="box">
    <div class="label">Detection Result:</div>
    <div class="value">
        <?php
        $toTypeName = strtolower(trim($row->ToTypeName));
        $indoorKeywords = array('indoor', '18m', 'salle', 'type_indoor');
        $found = false;

        foreach ($indoorKeywords as $keyword) {
            if (stripos($toTypeName, $keyword) !== false) {
                echo "✅ INDOOR (matched: '$keyword')";
                $found = true;
                break;
            }
        }

        if (!$found) {
            echo "❌ OUTDOOR (no match)";
        }
        ?>
    </div>
</div>

<div style="margin-top: 30px; padding: 15px; background: #e8f4f8; border-left: 4px solid #0099cc;">
    <strong>💡 Tip:</strong> Si le résultat est incorrect, veuillez montrer-moi exactement la valeur de <strong>ToTypeName</strong>
</div>

</body>
</html>
