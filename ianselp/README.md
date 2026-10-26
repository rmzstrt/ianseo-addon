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
| `data.php` | Source JSON interrogée en boucle par `live.php` et `mobile.php` (cache de 4 s) |
| `mobile.php` | Page mobile des spectateurs : recherche d'un archer, suivi de son score |
| `Lib/Fun_ianselp.php` | Configuration, liste des épreuves, lecture du classement |
| `Lib/Fun_ianselp_web.php` | Adresse publique, QR code (SVG), cache de `data.php` |
| `Lib/Fun_ianselp_departs.php` | Plusieurs départs : archers terminés / en cours, comparaison à volée équivalente |

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
`dist`, `title`, `baseurl`, `showqr`. Aucune table n'est créée.

## Plusieurs départs

Quand le 2e départ commence, les archers du 1er sont terminés (60 flèches) et
ceux du 2e en cours (6 flèches). Les classer ensemble relègue les seconds tout
en bas. Avec plusieurs départs de qualification, le module sépare donc :

- **le classement** : les archers terminés, avec une pastille de départ
  (`D1`, `D2`) ; les rangs sont renumérotés sans trous, en gardant les égalités
  officielles ;
- **le bloc « En cours »** : un archer y passe dès qu'un archer d'un départ
  *antérieur* est déjà terminé dans la même épreuve. On y lit sa volée (« Volée
  3/20 »), son score après cette volée, et le rang qu'il aurait si tous les
  archers de l'épreuve s'étaient arrêtés à la même volée (« 3e/14 · 1er : 87
  (−3) »). Les tirs hors classement (`EnIndFEvent=0`, même licence + arme + classe
  qu'un archer qui compte) y figurent aussi, comparés à leur propre départ qui
  compte (« D1 : 58 (−1) »). Sur l'écran, seuls les tirs en cours sont affichés ;
  la page mobile retrouve aussi les tirs terminés.

Le score à la volée N est relu dans les arrowstrings (`QuD<n>Arrowstring`, une
lettre par flèche). Seules les volées complètes comptent, les distances
s'enchaînent, et la comparaison classe par score, puis ors, puis neufs. Les
volées par distance viennent de `DistanceInformation`.

Limites : le rang à volée équivalente est une indication, pas le classement
officiel ; un archer dont les flèches n'ont pas été saisies une à une (total
par distance) n'a pas de comparaison, et l'affichage reste inchangé pour un
concours à un seul départ ou pour le classement d'une distance.

## Page mobile pour les spectateurs

Un QR code s'affiche en bas de l'écran (touche **Q** : en grand). Il ouvre
`mobile.php?tour=<ToId>` sur le téléphone : on tape un nom d'archer ou de club
(sans accent ni majuscule, plusieurs mots dans n'importe quel ordre), et la
page affiche le rang, le score, la cible et l'épreuve. L'étoile garde l'archer
dans le téléphone (`localStorage`, par concours) : il remonte en tête à la
visite suivante. Les scores se rafraîchissent seuls toutes les 10 s au plus et
la ligne d'un archer clignote quand son score change.

- **Adresse.** Un téléphone ne peut pas atteindre `localhost`. Le réglage
  *Adresse pour les spectateurs* (`baseurl`, ex. `http://192.168.1.20`) donne
  l'adresse à encoder ; vide, le module prend celle de la requête, ou, si
  l'écran est ouvert via `localhost`, l'adresse réseau de la machine
  (`gethostbyname(gethostname())`). Elle peut désigner une mauvaise carte réseau
  (VPN, WSL) : vérifier l'adresse affichée dans la page de pilotage. Sans
  adresse utilisable, aucun QR code n'est affiché et la page de pilotage le
  signale.
- **Réseau.** Les téléphones doivent être sur le même Wi-Fi que le poste, ou le
  serveur doit être joignable depuis Internet. Sous Windows, le pare-feu doit
  autoriser Apache.
- **Charge.** `data.php` met sa réponse en cache 4 secondes dans le dossier
  temporaire (`sys_get_temp_dir()`) : cent téléphones déclenchent un seul calcul
  du classement. Le cache est vidé à l'enregistrement des réglages et au
  recalcul.
- **Qui apparaît.** Les archers du classement officiel (`EnIndFEvent=1`), dès
  leur première flèche, et avec plusieurs départs les archers en cours et les
  tirs hors classement (voir ci-dessus).

## Affichage sur un autre poste

`live.php` accepte `?tour=<ToId>` et fonctionne sans session IANSEO :

    http://<ip-du-serveur>/Modules/Custom/ianselp/live.php?tour=123

Ajouter `&demo=1` pour un jeu de données fictives (réglage du vidéoprojecteur
avant le concours, sans toucher à la base).

Raccourcis clavier : **F** plein écran · **Espace** pause · **← →** épreuve
précédente / suivante · **Q** QR code en grand.

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
