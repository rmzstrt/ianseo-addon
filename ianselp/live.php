<?php
/**
 * ianselp — Affichage des scores en direct
 * Écran plein écran destiné à un vidéoprojecteur ou une TV.
 *
 * Toutes les épreuves sont rendues à la suite dans un seul flux, qui défile
 * en continu et reboucle sans coupure.
 *
 * Page autonome : elle n'inclut pas le template IANSEO (ni menu, ni CSS du
 * logiciel) et interroge data.php en boucle.
 *
 * Paramètres :
 *   ?tour=<ToId>  concours à afficher (sinon celui ouvert en session)
 *   ?demo=1       jeu de données factice, pour régler l'écran avant le concours
 */

require_once(dirname(dirname(__FILE__)) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once(dirname(__FILE__) . '/Lib/Fun_ianselp.php');

$TourId = ianselp_TourId();
if (!$TourId) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px sans-serif;padding:2em">'
       . 'Aucun concours sélectionné. Ouvrez un concours dans IANSEO, ou appelez cette page '
       . 'avec <code>?tour=&lt;numéro du concours&gt;</code>.</body>';
    exit;
}

$demo    = !empty($_REQUEST['demo']) ? 1 : 0;
$dataUrl = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__FILE__))
         . '/data.php?tour=' . $TourId . ($demo ? '&demo=1' : '');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scores en direct</title>
<style>
:root {
    --bg:      #0b1220;
    --bg-alt:  #131d31;
    --panel:   #16223a;
    --line:    #24344f;
    --fg:      #eef3fb;
    --muted:   #8ea3c4;
    --accent:  #ffc043;
    --podium1: #ffd75e;
    --podium2: #cfd8e3;
    --podium3: #d99a5b;
    --ok:      #4ade80;
    --ko:      #f87171;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
    background: var(--bg);
    color: var(--fg);
    font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    cursor: none;
}

header {
    display: flex;
    align-items: center;
    gap: 1.5vw;
    padding: 1.2vh 2vw;
    background: var(--panel);
    border-bottom: 0.3vh solid var(--line);
    flex: none;
}
.title { font-size: 2.4vh; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; white-space: nowrap; }
.event { flex: 1; font-size: 3.6vh; font-weight: 700; color: var(--accent); text-align: center;
         overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta  { display: flex; align-items: center; gap: 1vw; font-size: 2.4vh; color: var(--muted); white-space: nowrap; }
.dot   { width: 1.2vh; height: 1.2vh; border-radius: 50%; background: var(--ok); }
.dot.stale { background: var(--ko); }
.badge { font-size: 1.8vh; padding: .3vh .8vh; border-radius: .5vh; background: var(--ko); color: #fff; letter-spacing: .05em; }

/* Fenêtre de défilement : le contenu déborde, on le translate. */
main { flex: 1; position: relative; overflow: hidden; padding: 0 2vw; min-height: 0; }
#scroller { position: absolute; left: 2vw; right: 2vw; top: 0; will-change: transform; }

section { padding-bottom: var(--gap); }
h2 {
    font-size: calc(var(--font-row) * 1.15);
    color: var(--accent);
    font-weight: 700;
    padding: calc(var(--row-h) * .35) 0 calc(var(--row-h) * .15);
    border-bottom: .3vh solid var(--line);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

table { width: 100%; border-collapse: collapse; table-layout: fixed; }
tbody td { padding: 0 .8vw; border-bottom: .1vh solid var(--line); white-space: nowrap;
           overflow: hidden; text-overflow: ellipsis; }
tbody tr { height: var(--row-h); }
tbody tr:nth-child(even) { background: var(--bg-alt); }

.c-rank    { width: 8%;  text-align: center; font-weight: 700; }
.c-ath     { width: 40%; font-weight: 600; }
.c-club    { width: 26%; color: var(--muted); }
.c-score   { width: 12%; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.c-g, .c-x { width: 7%;  text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }

tbody td.c-rank, tbody td.c-ath, tbody td.c-club { font-size: var(--font-row); }
tbody td.c-score { font-size: calc(var(--font-row) * 1.05); }
tbody td.c-g, tbody td.c-x { font-size: calc(var(--font-row) * 0.85); }

tr.p1 .c-rank { color: var(--podium1); }
tr.p2 .c-rank { color: var(--podium2); }
tr.p3 .c-rank { color: var(--podium3); }

/* Rappel des colonnes, figé sous l'en-tête. */
.cols {
    display: flex; flex: none;
    padding: .6vh 2vw; background: var(--panel);
    font-size: 2vh; text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
    border-bottom: .1vh solid var(--line);
}
.cols span { padding: 0 .8vw; }
.cols .c-ath, .cols .c-club { text-align: left; }

footer {
    display: flex; align-items: center; justify-content: space-between; gap: 1.5vw;
    padding: 1vh 2vw; background: var(--panel); border-top: .3vh solid var(--line);
    font-size: 2vh; color: var(--muted); flex: none;
}

.empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
         font-size: 4vh; color: var(--muted); text-align: center; }
</style>
</head>
<body>

<header>
    <div class="title" id="title">&nbsp;</div>
    <div class="event" id="event">Chargement…</div>
    <div class="meta">
        <span class="badge" id="demoBadge" hidden>DÉMO</span>
        <span class="dot" id="dot"></span>
        <span id="clock">--:--</span>
    </div>
</header>

<div class="cols">
    <span class="c-rank">#</span>
    <span class="c-ath">Archer</span>
    <span class="c-club">Club</span>
    <span class="c-score">Score</span>
    <span class="c-g" id="colGold">10</span>
    <span class="c-x" id="colXnine">9</span>
</div>

<main id="main"><div id="scroller"></div></main>

<footer>
    <span id="summary">&nbsp;</span>
    <span>Espace : pause &middot; &larr; &rarr; : épreuve &middot; F : plein écran</span>
</footer>

<script>
(function () {
    'use strict';

    var DATA_URL = <?php echo json_encode($dataUrl); ?>;

    var cfg     = { scrollsec: 20, refresh: 10, rows: 12 };
    var labels  = { gold: '10+', xnine: 'X' };
    var paused  = false;
    var offset  = 0;      // décalage vertical courant, en pixels
    var loopH   = 0;      // hauteur d'une copie du contenu
    var lastOk  = 0;      // date de la dernière réponse valide
    var lastTs  = 0;      // horodatage de la dernière frame
    var anchors = [];     // position verticale de chaque titre d'épreuve
    var refreshTimer = null;

    var $main     = document.getElementById('main');
    var $scroller = document.getElementById('scroller');
    var $event    = document.getElementById('event');

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function tickClock() {
        var d = new Date();
        document.getElementById('clock').textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // La taille du texte est bornée par la hauteur de ligne voulue ET par la
    // largeur : sans la seconde borne, les noms et les scores seraient tronqués.
    function fitRows() {
        var h = Math.max(24, Math.floor($main.clientHeight / Math.max(cfg.rows, 1)));
        var f = Math.max(12, Math.min(Math.floor(h * 0.52),
                                      Math.floor($main.clientWidth * 0.028)));
        $main.style.setProperty('--row-h', h + 'px');
        $main.style.setProperty('--font-row', f + 'px');
        $main.style.setProperty('--gap', Math.floor(h * 0.6) + 'px');
    }

    function sectionHtml(section) {
        var html = '<section data-descr="' + esc(section.descr) + '">'
                 + '<h2>' + esc(section.descr) + '</h2><table><tbody>';
        section.items.forEach(function (it) {
            var r = parseInt(it.rank, 10);
            var cls = (r === 1 ? 'p1' : r === 2 ? 'p2' : r === 3 ? 'p3' : '');
            html += '<tr class="' + cls + '">'
                 +  '<td class="c-rank">'  + esc(it.rank)    + '</td>'
                 +  '<td class="c-ath">'   + esc(it.athlete) + '</td>'
                 +  '<td class="c-club">'  + esc(it.club)    + '</td>'
                 +  '<td class="c-score">' + esc(it.score)   + '</td>'
                 +  '<td class="c-g">'     + esc(it.gold)    + '</td>'
                 +  '<td class="c-x">'     + esc(it.xnine)   + '</td>'
                 +  '</tr>';
        });
        return html + '</tbody></table></section>';
    }

    function render(sections) {
        if (!sections.length) {
            $scroller.innerHTML = '';
            $main.querySelector('.empty') ||
                $main.insertAdjacentHTML('beforeend',
                    '<div class="empty">En attente des premiers scores…</div>');
            $event.textContent = 'Scores en direct';
            document.getElementById('summary').textContent = '';
            loopH = 0;
            return;
        }
        var empty = $main.querySelector('.empty');
        if (empty) { empty.remove(); }

        var body = sections.map(sectionHtml).join('');

        // Le contenu est rendu deux fois : quand la première copie est sortie
        // par le haut, la seconde occupe déjà l'écran, et on ramène le décalage
        // d'une hauteur de copie. La boucle est invisible.
        $scroller.innerHTML = '<div class="copy">' + body + '</div>'
                            + '<div class="copy" aria-hidden="true">' + body + '</div>';

        fitRows();
        measure();

        var total = sections.reduce(function (n, s) { return n + s.items.length; }, 0);
        document.getElementById('summary').textContent =
            sections.length + ' épreuve' + (sections.length > 1 ? 's' : '') +
            ' · ' + total + ' archer' + (total > 1 ? 's' : '');
    }

    // Hauteur d'une copie + position de chaque épreuve, pour la boucle et pour
    // le titre affiché en haut.
    function measure() {
        var copy = $scroller.querySelector('.copy');
        loopH = copy ? copy.offsetHeight : 0;

        anchors = [];
        var sections = copy ? copy.querySelectorAll('section') : [];
        for (var i = 0; i < sections.length; i++) {
            anchors.push({
                top:   sections[i].offsetTop,
                descr: sections[i].getAttribute('data-descr')
            });
        }
        if (offset > loopH && loopH > 0) { offset = offset % loopH; }
    }

    // Épreuve actuellement en haut de l'écran.
    function currentDescr() {
        if (!anchors.length) { return ''; }
        var y = loopH > 0 ? (offset % loopH) : offset;
        var descr = anchors[anchors.length - 1].descr;
        for (var i = 0; i < anchors.length; i++) {
            if (anchors[i].top > y + 4) { descr = i > 0 ? anchors[i - 1].descr : anchors[0].descr; break; }
            if (i === anchors.length - 1) { descr = anchors[i].descr; }
        }
        return descr;
    }

    // Le défilement est piloté par un setInterval et non par
    // requestAnimationFrame : rAF est suspendu dès que la fenêtre passe à
    // l'arrière-plan ou est masquée, ce qui figerait l'écran d'un poste laissé
    // seul. L'avancement est calculé à partir du temps réellement écoulé, donc
    // la vitesse reste juste même si les tics sont ralentis.
    function frame() {
        var now = (window.performance && performance.now) ? performance.now() : Date.now();
        var dt  = lastTs ? (now - lastTs) / 1000 : 0;
        lastTs  = now;

        // Un tic retardé est plafonné à une seconde, pas ignoré. Les
        // navigateurs ralentissent les minuteries à 1 Hz quand l'onglet n'est
        // pas au premier plan : plafonner plus bas ferait trainer le
        // défilement, l'ignorer le figerait, et ne pas plafonner du tout
        // ferait sauter l'écran après une longue mise en veille.
        if (dt > 1) { dt = 1; }

        // Rien à faire si tout tient à l'écran : on n'anime pas pour rien.
        var scrollable = loopH > $main.clientHeight + 2;

        if (!paused && scrollable && dt > 0) {
            offset += ($main.clientHeight / cfg.scrollsec) * dt;
            while (offset >= loopH) { offset -= loopH; }
            $scroller.style.transform = 'translateY(' + (-offset) + 'px)';
        } else if (!scrollable && offset !== 0) {
            offset = 0;
            $scroller.style.transform = 'translateY(0)';
        }

        var d = currentDescr();
        if (d && $event.textContent !== d) { $event.textContent = d; }
    }

    function armRefresh() {
        if (refreshTimer) { clearInterval(refreshTimer); }
        refreshTimer = setInterval(load, cfg.refresh * 1000);
    }

    function load() {
        var sep = DATA_URL.indexOf('?') >= 0 ? '&' : '?';
        fetch(DATA_URL + sep + '_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.error) { throw new Error(json.error); }

                var oldRefresh = cfg.refresh;
                cfg.scrollsec = json.scrollsec || cfg.scrollsec;
                cfg.refresh   = json.refresh   || cfg.refresh;
                cfg.rows      = json.rows      || cfg.rows;
                if (cfg.refresh !== oldRefresh) { armRefresh(); }

                labels.gold  = json.goldLabel  || labels.gold;
                labels.xnine = json.xnineLabel || labels.xnine;
                document.getElementById('colGold').textContent  = labels.gold;
                document.getElementById('colXnine').textContent = labels.xnine;

                document.getElementById('title').textContent = json.title || '';
                document.getElementById('demoBadge').hidden  = !json.demo;

                render(json.sections || []);

                lastOk = Date.now();
                document.getElementById('dot').classList.remove('stale');
            })
            .catch(function () {
                document.getElementById('dot').classList.add('stale');
            });
    }

    // Saut à l'épreuve précédente / suivante.
    function jump(step) {
        if (!anchors.length || !loopH) { return; }
        var y = offset % loopH;
        var i = 0;
        while (i < anchors.length - 1 && anchors[i + 1].top <= y + 4) { i++; }
        i = (i + step + anchors.length) % anchors.length;
        offset = anchors[i].top;
        $scroller.style.transform = 'translateY(' + (-offset) + 'px)';
    }

    setInterval(function () {
        tickClock();
        if (lastOk && Date.now() - lastOk > cfg.refresh * 3000) {
            document.getElementById('dot').classList.add('stale');
        }
    }, 1000);

    document.addEventListener('keydown', function (e) {
        if (e.key === ' ' || e.key === 'Spacebar' || e.code === 'Space') {
            paused = !paused;
            e.preventDefault();
        } else if (e.key === 'ArrowRight') {
            jump(1);
        } else if (e.key === 'ArrowLeft') {
            jump(-1);
        } else if (e.key === 'f' || e.key === 'F') {
            if (document.fullscreenElement) { document.exitFullscreen(); }
            else { document.documentElement.requestFullscreen(); }
        }
    });

    window.addEventListener('resize', function () { fitRows(); measure(); });

    tickClock();
    load();
    armRefresh();
    setInterval(frame, 33);   // ~30 images/s, suffisant pour un défilement lent
})();
</script>
</body>
</html>
