<?php
/**
 * ianselp — Affichage des scores en direct
 * Page mobile pour les spectateurs : on cherche « son » archer par nom ou par
 * club, et on suit son score. Les archers marqués d'une étoile sont gardés
 * dans le téléphone (localStorage) et remontent en tête à la visite suivante.
 *
 * Page autonome, en lecture seule, sans session IANSEO (comme live.php) :
 * elle interroge data.php, dont la réponse est mise en cache côté serveur.
 *
 * Paramètres :
 *   ?tour=<ToId>  concours à afficher (sinon celui ouvert en session)
 *   ?demo=1       jeu de données factice
 */

require_once(dirname(dirname(__FILE__)) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once(dirname(__FILE__) . '/Lib/Fun_ianselp.php');

$TourId = ianselp_TourId();
if (!$TourId) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<body style="font:16px sans-serif;padding:2em">Aucun concours sélectionné.</body>';
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>Suivre un archer</title>
<style>
:root {
    --bg:      #f3f5f9;
    --card:    #ffffff;
    --fg:      #111a2b;
    --muted:   #5b6b86;
    --line:    #dde3ee;
    --accent:  #9a5b00;
    --flash:   #fff0c2;
    --ok:      #15803d;
    --ko:      #b91c1c;
    --podium1: #a87900;
    --podium2: #6b7280;
    --podium3: #9a5320;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg:      #0b1220;
        --card:    #16223a;
        --fg:      #eef3fb;
        --muted:   #8ea3c4;
        --line:    #24344f;
        --accent:  #ffc043;
        --flash:   #4a3a10;
        --ok:      #4ade80;
        --ko:      #f87171;
        --podium1: #ffd75e;
        --podium2: #cfd8e3;
        --podium3: #d99a5b;
    }
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg);
    color: var(--fg);
    font: 16px/1.4 system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    -webkit-text-size-adjust: 100%;
    min-height: 100vh;
}

.top {
    position: sticky; top: 0; z-index: 2;
    background: var(--bg);
    padding: max(12px, env(safe-area-inset-top)) 16px 10px;
    border-bottom: 1px solid var(--line);
}
.brand { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px;
         font-size: 13px; color: var(--muted); }
.brand #title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                text-transform: uppercase; letter-spacing: .06em; }
.brand .st { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.dot { width: 9px; height: 9px; border-radius: 50%; background: var(--ok); }
.dot.stale { background: var(--ko); }

.search { position: relative; display: block; }
.search svg { position: absolute; left: 14px; top: 50%; width: 20px; height: 20px; margin-top: -10px;
              stroke: var(--muted); fill: none; stroke-width: 2; stroke-linecap: round; pointer-events: none; }
.search input {
    width: 100%; height: 50px; padding: 0 14px 0 44px;
    font: inherit; font-size: 17px; color: var(--fg);
    background: var(--card); border: 1px solid var(--line); border-radius: 12px;
    appearance: none; -webkit-appearance: none;
}
.search input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

.count { padding: 10px 16px 0; font-size: 13px; color: var(--muted); min-height: 1.4em; }
.offline { margin: 10px 16px 0; padding: 8px 12px; border-radius: 10px; font-size: 14px;
           color: var(--ko); border: 1px solid var(--ko); }
.offline[hidden] { display: none; }

.msg { padding: 32px 24px; text-align: center; color: var(--muted); }
.msg b { display: block; margin-bottom: 6px; color: var(--fg); font-size: 18px; }

.card {
    display: grid; grid-template-columns: 40px 1fr auto; gap: 4px 12px;
    margin: 10px 16px; padding: 12px 8px 12px 14px;
    background: var(--card); border: 1px solid var(--line); border-radius: 14px;
}
.card.flash { animation: flash 3s ease-out; }
@keyframes flash { from { background: var(--flash); } to { background: var(--card); } }
@media (prefers-reduced-motion: reduce) { .card.flash { animation: none; } }

.rk { font-size: 26px; font-weight: 800; text-align: center; padding-top: 2px; font-variant-numeric: tabular-nums; }
.rk.none { color: var(--muted); font-weight: 400; }
.card.p1 .rk { color: var(--podium1); }
.card.p2 .rk { color: var(--podium2); }
.card.p3 .rk { color: var(--podium3); }

.who { min-width: 0; }
.who h3 { font-size: 17px; line-height: 1.25; overflow-wrap: anywhere; }
.who .club { color: var(--muted); font-size: 14px; overflow-wrap: anywhere; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.chip { font-size: 12px; padding: 2px 8px; border-radius: 99px; border: 1px solid var(--line); color: var(--muted); }
.chip.tgt { color: var(--accent); border-color: var(--accent); }

.side { display: flex; flex-direction: column; align-items: flex-end; justify-content: space-between; }
.score { font-size: 30px; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
.det { font-size: 12px; color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }
.star {
    width: 44px; height: 44px; margin-top: 2px; font-size: 26px; line-height: 1;
    background: none; border: 0; color: var(--muted); cursor: pointer; border-radius: 10px;
}
.star[aria-pressed="true"] { color: var(--accent); }
.star:focus-visible { outline: 2px solid var(--accent); }

.card.wait { opacity: .75; }
.card.wait .score { font-size: 14px; font-weight: 400; color: var(--muted); }

footer { padding: 18px 16px calc(24px + env(safe-area-inset-bottom)); font-size: 12px; color: var(--muted); text-align: center; }
</style>
</head>
<body>

<header class="top">
    <div class="brand">
        <span id="title">&nbsp;</span>
        <span class="st"><span class="dot stale" id="dot"></span><span id="upd">…</span></span>
    </div>
    <label class="search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input id="q" type="search" placeholder="Nom d'un archer ou d'un club" aria-label="Rechercher un archer ou un club"
               autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="search">
    </label>
</header>

<div class="offline" id="offline" hidden>Connexion perdue : les scores affichés ne sont plus à jour.</div>
<div class="count" id="count" aria-live="polite"></div>
<main id="list"></main>

<footer id="foot">Scores provisoires, mis à jour automatiquement.</footer>

<script>
(function () {
    'use strict';

    var DATA_URL = <?php echo json_encode($dataUrl); ?>;
    var FAV_KEY  = <?php echo json_encode('ianselp.fav.' . $TourId); ?>;
    var MAX_SHOWN = 40;
    var MIN_REFRESH = 10;   // les téléphones n'ont pas besoin d'aller plus vite que ça

    var all      = [];                       // archers aplatis
    var labels   = { gold: '10+', xnine: 'X' };
    var refresh  = 15;
    var loaded   = false;
    var failed   = false;
    var lastOk   = 0;
    var query    = '';
    var fav      = loadFav();                // clé -> nom
    var prevScore = {};                      // clé -> dernier score vu
    var flashKeys = {};
    var lastHtml = null;
    var timer    = null;

    var $q = document.getElementById('q');
    var $list = document.getElementById('list');
    var $count = document.getElementById('count');

    function loadFav() {
        try {
            var v = JSON.parse(localStorage.getItem(FAV_KEY) || '{}');
            return (v && typeof v === 'object') ? v : {};
        } catch (e) { return {}; }
    }
    function saveFav() {
        try { localStorage.setItem(FAV_KEY, JSON.stringify(fav)); } catch (e) {}
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function norm(s) {
        s = String(s === null || s === undefined ? '' : s).toLowerCase();
        return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function flatten(sections) {
        var out = [];
        sections.forEach(function (sec) {
            sec.items.forEach(function (it) {
                out.push({
                    key:     (it.id !== undefined && it.id !== null && it.id !== '') ? String(it.id) : sec.event + '|' + it.athlete,
                    descr:   sec.descr,
                    rank:    it.rank,
                    athlete: it.athlete,
                    club:    it.club,
                    target:  it.target,
                    score:   it.score,
                    gold:    it.gold,
                    xnine:   it.xnine,
                    hits:    it.hits,
                    hay:     norm(it.athlete + ' ' + it.club)
                });
            });
        });
        return out;
    }

    function cardHtml(a) {
        var r = parseInt(a.rank, 10);
        var cls = 'card' + (r === 1 ? ' p1' : r === 2 ? ' p2' : r === 3 ? ' p3' : '') + (flashKeys[a.key] ? ' flash' : '');
        var isNum = /^\d+$/.test(String(a.score));
        var det = isNum
            ? esc(a.gold) + ' &times; ' + esc(labels.gold) + ' &middot; ' + esc(a.xnine) + ' &times; ' + esc(labels.xnine)
              + '<br>' + esc(a.hits) + ' flèche' + (parseInt(a.hits, 10) > 1 ? 's' : '')
            : '';
        var on = !!fav[a.key];
        return '<article class="' + cls + '">'
             +   '<div class="rk' + (a.rank === '' ? ' none' : '') + '">' + (a.rank === '' ? '&ndash;' : esc(a.rank)) + '</div>'
             +   '<div class="who"><h3>' + esc(a.athlete) + '</h3>'
             +     '<div class="club">' + esc(a.club) + '</div>'
             +     '<div class="chips"><span class="chip">' + esc(a.descr) + '</span>'
             +       (a.target ? '<span class="chip tgt">Cible ' + esc(a.target) + '</span>' : '') + '</div></div>'
             +   '<div class="side"><div><div class="score">' + esc(a.score) + '</div><div class="det">' + det + '</div></div>'
             +     starHtml(a.key, a.athlete, on) + '</div>'
             + '</article>';
    }

    function starHtml(key, name, on) {
        return '<button class="star" type="button" data-key="' + esc(key) + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
             + ' aria-label="' + (on ? 'Ne plus suivre ' : 'Suivre ') + esc(name) + '">' + (on ? '&#9733;' : '&#9734;') + '</button>';
    }

    // Archer suivi mais pas (encore) dans le classement.
    function waitHtml(key, name) {
        return '<article class="card wait"><div class="rk none">&ndash;</div>'
             + '<div class="who"><h3>' + esc(name) + '</h3><div class="club">Pas encore de score</div></div>'
             + '<div class="side"><div></div>' + starHtml(key, name, true) + '</div></article>';
    }

    function msg(title, text) {
        return '<div class="msg"><b>' + esc(title) + '</b>' + esc(text) + '</div>';
    }

    function render() {
        var q = norm(query).trim();
        var html = '', count = '';

        if (!loaded) {
            html = failed ? msg('Connexion impossible', 'Nouvel essai automatique dans quelques secondes.')
                          : msg('Chargement…', '');
        } else if (q.length >= 2) {
            var tokens = q.split(/\s+/);
            var hits = all.filter(function (a) {
                return tokens.every(function (t) { return a.hay.indexOf(t) !== -1; });
            });
            hits.sort(function (a, b) { return (fav[b.key] ? 1 : 0) - (fav[a.key] ? 1 : 0); });   // tri stable : l'ordre du classement est conservé
            if (!hits.length) {
                html = msg('Aucun résultat pour « ' + query.trim() + ' »',
                           'Un archer apparaît dès sa première flèche enregistrée. Vérifiez l\'orthographe ou réessayez dans quelques minutes.');
            } else {
                count = hits.length + ' archer' + (hits.length > 1 ? 's' : '') + ' trouvé' + (hits.length > 1 ? 's' : '')
                      + (hits.length > MAX_SHOWN ? ' — les ' + MAX_SHOWN + ' premiers sont affichés, précisez la recherche' : '');
                html = hits.slice(0, MAX_SHOWN).map(cardHtml).join('');
            }
        } else if (q.length === 1) {
            html = msg('Encore une lettre…', 'Tapez au moins deux caractères.');
        } else {
            var keys = Object.keys(fav);
            if (keys.length) {
                var present = {};
                all.forEach(function (a) { present[a.key] = a; });
                count = 'Mes archers';
                html = keys.map(function (k) { return present[k] ? cardHtml(present[k]) : waitHtml(k, fav[k]); }).join('');
            } else if (!all.length) {
                html = msg('En attente des premiers scores…', 'La liste se remplit au fil du tir.');
            } else {
                var n = all.length;
                html = msg('Qui voulez-vous suivre ?',
                           'Tapez un nom ou un club, puis touchez l\'étoile pour garder l\'archer sous la main. ' + n + ' archer' + (n > 1 ? 's' : '') + ' classé' + (n > 1 ? 's' : '') + ' pour l\'instant.');
            }
        }

        $count.textContent = count;
        if (html !== lastHtml) {    // évite de reconstruire (et de perdre le focus) quand rien n'a changé
            $list.innerHTML = html;
            lastHtml = html;
        }
        flashKeys = {};
    }

    function setStatus(ok) {
        document.getElementById('dot').classList.toggle('stale', !ok);
        document.getElementById('offline').hidden = ok;
        if (ok) {
            var d = new Date();
            document.getElementById('upd').textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(load, Math.max(refresh, MIN_REFRESH) * 1000);
    }

    function load() {
        clearTimeout(timer);
        if (document.hidden) { schedule(); return; }

        var ctl = window.AbortController ? new AbortController() : null;
        var abort = setTimeout(function () { if (ctl) { ctl.abort(); } }, 15000);
        var sep = DATA_URL.indexOf('?') >= 0 ? '&' : '?';

        fetch(DATA_URL + sep + '_=' + Date.now(), { cache: 'no-store', signal: ctl ? ctl.signal : undefined })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.error) { throw new Error(json.error); }

                refresh = json.refresh || refresh;
                labels.gold  = json.goldLabel  || labels.gold;
                labels.xnine = json.xnineLabel || labels.xnine;
                document.getElementById('title').textContent = json.title || '';
                document.title = json.title ? json.title + ' — suivre un archer' : 'Suivre un archer';
                document.getElementById('foot').textContent =
                    'Scores provisoires, actualisés toutes les ' + Math.max(refresh, MIN_REFRESH) + ' secondes. Un archer apparaît dès sa première flèche.';

                all = flatten(json.sections || []);
                all.forEach(function (a) {
                    if (prevScore[a.key] !== undefined && prevScore[a.key] !== String(a.score)) { flashKeys[a.key] = true; }
                    prevScore[a.key] = String(a.score);
                    if (fav[a.key] && fav[a.key] !== a.athlete) { fav[a.key] = a.athlete; }
                });
                loaded = true;
                lastOk = Date.now();
                setStatus(true);
                render();
            })
            .catch(function () {
                if (lastOk) { setStatus(false); }
                if (!loaded) { failed = true; render(); }
            })
            .then(function () {
                clearTimeout(abort);
                schedule();
            });
    }

    $q.addEventListener('input', function () { query = $q.value; render(); });

    $list.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.star') : null;
        if (!b) { return; }
        var key = b.getAttribute('data-key');
        if (fav[key]) {
            delete fav[key];
        } else {
            var name = key;
            all.forEach(function (a) { if (a.key === key) { name = a.athlete; } });
            fav[key] = name;
        }
        saveFav();
        lastHtml = null;
        render();
    });

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });

    setInterval(function () {
        if (lastOk && Date.now() - lastOk > Math.max(refresh, MIN_REFRESH) * 3000) { setStatus(false); }
    }, 5000);

    render();
    load();
})();
</script>
</body>
</html>
