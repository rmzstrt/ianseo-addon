# ianselp — Affichage des scores en direct

Module d'extension IANSEO qui projette le classement de qualification sur un
écran ou un vidéoprojecteur, mis à jour au fil de la saisie.

## Installation

Copier le contenu de ce dépôt dans un sous-dossier de `Modules/Custom/` de
l'installation IANSEO :

    <racine ianseo>/Modules/Custom/ianselp/

Le nom du dossier est libre : le module le déduit à l'exécution
(`basename(dirname(__FILE__))`). Aucune table à créer, aucun fichier du cœur à
modifier. L'entrée apparaît ensuite dans le menu **Sorties → Scores en direct**.

Testé sur IANSEO 2026-03-01 (rev 319), PHP 8.2, MariaDB.

## Fichiers

| Fichier | Rôle |
|---|---|
| `menu.php` | Entrée « Scores en direct » dans le menu **Sorties** |
| `index.php` | Page de pilotage : réglages + lancement de l'affichage |
| `live.php` | Écran plein écran (aucun template IANSEO, aucun menu) |
| `data.php` | Source JSON interrogée en boucle par `live.php` |
| `Lib/Fun_ianselp.php` | Configuration, liste des épreuves, lecture du classement |

## Fonctionnement

Toutes les épreuves sont rendues à la suite dans un **seul flux continu**, qui
défile vers le haut et reboucle sans coupure : le contenu est rendu deux fois,
et quand la première copie sort par le haut, on retranche exactement sa hauteur
au décalage — la seconde occupe alors la même position au pixel près. Le titre
en haut de l'écran suit l'épreuve en cours de passage.

Le défilement est piloté par `setInterval` et non par `requestAnimationFrame` :
rAF est suspendu dès que la fenêtre passe à l'arrière-plan, ce qui figerait un
écran laissé seul. L'avancement se calcule sur le temps réellement écoulé, avec
un tic plafonné à une seconde — les navigateurs ralentissent les minuteries à
1 Hz en arrière-plan, et la vitesse reste juste dans ce régime.

`live.php` interroge `data.php` toutes les *n* secondes. Le classement vient du
moteur officiel d'IANSEO
(`Obj_RankFactory::create('Abs')`, table `Individuals`), que le cœur recalcule à
chaque enregistrement de feuille de marque (`Qualification/UpdateQuals.php`) ou
de flèche ISK (`Qualification/UpdateArrow.php`). Le module ne calcule ni
n'écrit aucun score.

## Rang non calculé

IANSEO stocke le rang dans `Individuals`, mais lit le score directement depuis
`Qualifications`. Des scores arrivés hors du flux normal (import, outil externe,
simulation) donnent donc un score juste avec un **rang à 0**. Dans ce cas :

- l'affichage laisse la colonne rang vide plutôt que de montrer un « 0 » faux ;
- la page de pilotage signale le nombre d'archers concernés ;
- le bouton **Recalculer le classement** relance le calcul officiel
  (`Obj_Rank_Abs::calculate()`, pour la distance 0 et chaque distance), c'est-à-dire
  exactement ce que fait le cœur après l'enregistrement d'une feuille de marque.

## Réglages

Menu **Sorties → Scores en direct**. Ils sont stockés dans `ModulesParameters`
sous le module `ianselp`, par tournoi : `events`, `scrollsec`, `refresh`, `rows`,
`dist`, `title`. Aucune table n'est créée.

## Affichage sur un autre poste

`live.php` accepte `?tour=<ToId>` et fonctionne sans session IANSEO :

    http://<ip-du-serveur>/Modules/Custom/ianselp/live.php?tour=123

Ajouter `&demo=1` pour un jeu de données fictives (réglage du vidéoprojecteur
avant le concours, sans toucher à la base).

Raccourcis clavier : **F** plein écran · **Espace** pause · **← →** épreuve
précédente / suivante.

## Notes

- `live.php` et `data.php` ne demandent pas d'authentification : ce sont des
  résultats publics, et l'écran doit pouvoir tourner sur un poste sans session.
  `index.php`, lui, exige `AclOutput` (lecture, et écriture pour enregistrer).
- Ce dossier est distinct des modules de `loloz3/ianseo-addon` : la mise à jour
  GitHub de l'addon (`Modules/Custom/aide/github_update.php`) ne doit pas
  l'écraser. Ne pas le déplacer dans un des dossiers de l'addon.

## Licence

MIT — voir [LICENSE](LICENSE). Réutilisation, modification et redistribution
libres, y compris pour un autre club, à condition de conserver la notice de
copyright.
