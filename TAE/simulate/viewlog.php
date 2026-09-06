<?php
/**
 * TAE - Simple log viewer for debugging
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadWrite);

$logFile = dirname(__FILE__) . '/debug.log';

// Clear log if requested
if (isset($_GET['clear'])) {
    @unlink($logFile);
    header('Location: viewlog.php');
    exit;
}

$content = file_exists($logFile) ? file_get_contents($logFile) : '(no log file yet - trigger an action first)';

// Show only last 200 lines
$lines = explode("\n", $content);
$lines = array_slice($lines, -200);
$content = implode("\n", $lines);

?>
<!DOCTYPE html>
<html>
<head>
    <title>TAE Debug Log</title>
    <meta http-equiv="refresh" content="5">
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #fff; font-family: Arial; }
        pre { background: #000; padding: 15px; border-radius: 5px; white-space: pre-wrap; word-wrap: break-word; }
        a { color: #4ec9b0; }
        .error { color: #f48771; }
    </style>
</head>
<body>
    <h1>🔍 TAE Debug Log (auto-refresh 5s)</h1>
    <p><a href="?clear=1">🗑️ Effacer le log</a> | <a href="viewlog.php">🔄 Rafraîchir</a></p>
    <pre><?php
        $escapedContent = htmlspecialchars($content);
        // Highlight errors
        $escapedContent = preg_replace('/(FATAL ERROR:.*|EXCEPTION CAUGHT:.*)/', '<span class="error">$1</span>', $escapedContent);
        echo $escapedContent;
    ?></pre>
</body>
</html>
