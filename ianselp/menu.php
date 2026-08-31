<?php
/**
 * ianselp — Affichage des scores en direct
 * Déclaration des entrées de menu.
 *
 * ATTENTION : ce fichier est inclus dans le template global de TOUTES les pages
 * (Common/Menu.php). Une erreur fatale ici casse l'application entière.
 * On garde donc ce fichier minimal : aucune requête, aucun appel de fonction.
 *
 * $on  = true si un tournoi est ouvert
 * $ret = tableau des menus
 */

if ($on) {
    // Le nom du dossier est déduit, pas codé en dur : le module fonctionne
    // quel que soit le nom sous lequel il a été installé ou cloné.
    $ianselpDir = basename(dirname(__FILE__));

    $ret['MEDI'][] = MENU_DIVIDER;
    $ret['MEDI'][] = 'Scores en direct' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/' . $ianselpDir . '/index.php';
}
