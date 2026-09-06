<?php
/**
 * Module TAE - Simulation / Débogage
 * Enregistrement dans le menu principal
 */

if ($on) {  // $on = true si un tournoi est actuellement ouvert
    $ret['MODS'][] = MENU_DIVIDER;
    $ret['MODS'][] = 'Simulation / Débogage' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/TAE/simulate/';
}
?>
