<?php
/**
 * addon_source.php - Origine des mises a jour de l'addon.
 *
 * Une seule valeur a changer pour pointer la mise a jour vers un autre depot
 * GitHub : ADDON_REPO ci-dessous.
 *
 * Ce fichier fait partie des fichiers proteges lors de la mise a jour
 * (voir $protectedFiles dans github_update.php) : votre reglage est conserve
 * a chaque « Mettre a jour l'Addon ».
 *
 * Le depot doit etre PUBLIC : le telechargement se fait sans authentification.
 */

// Depot GitHub, au format "compte/depot".
define('ADDON_REPO', 'rmzstrt/ianseo-addon');

// Branche a telecharger.
define('ADDON_BRANCH', 'main');

/** URL de la page du depot (bouton « Voir sur GitHub »). */
function AddonRepoUrl() {
    return 'https://github.com/' . ADDON_REPO;
}

/** URL de l'archive ZIP de la branche configuree. */
function AddonZipUrl() {
    return 'https://github.com/' . ADDON_REPO . '/archive/' . ADDON_BRANCH . '.zip';
}
