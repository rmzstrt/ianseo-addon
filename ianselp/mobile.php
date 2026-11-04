<?php
/**
 * ianselp — Affichage des scores en direct
 * Page mobile pour les spectateurs : on cherche « son » archer par nom ou par
 * club, et la page affiche le classement de toute sa catégorie, l'archer en
 * évidence : un score seul ne dit pas où se situe l'archer face à ses
 * concurrents. Les archers marqués d'une étoile sont gardés dans le téléphone
 * (localStorage) : à la visite suivante, le classement de leur catégorie
 * s'affiche directement.
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
    --hl:      rgba(154, 91, 0, .09);
    --sel:     rgba(154, 91, 0, .20);
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
        --hl:      rgba(255, 192, 67, .10);
        --sel:     rgba(255, 192, 67, .22);
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

/* Un classement : l'en-tête (catégorie) puis une ligne par archer. */
.cat { margin: 12px 16px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
.cat-head { padding: 12px 14px 10px; border-bottom: 1px solid var(--line); }
.cat-head h2 { font-size: 17px; line-height: 1.25; }
.cat-head p { font-size: 13px; color: var(--muted); }
.cat-head p.note { margin-top: 6px; }
.back { display: inline-block; min-height: 36px; margin: -6px 0 2px -6px; padding: 6px 10px; font: inherit; font-size: 15px;
        color: var(--accent); background: none; border: 0; cursor: pointer; }

.row { display: flex; align-items: stretch; border-top: 1px solid var(--line); }
.cat-head + .row { border-top: 0; }
.row.hl  { background: var(--hl);  box-shadow: inset 4px 0 0 var(--accent); }
.row.sel { background: var(--sel); box-shadow: inset 4px 0 0 var(--accent); }
.row.flash { animation: flash 3s ease-out; }
@keyframes flash { from { background: var(--flash); } }
@media (prefers-reduced-motion: reduce) { .row.flash { animation: none; } }

.pick { flex: 1; min-width: 0; display: grid; grid-template-columns: 40px minmax(0, 1fr) auto; gap: 0 10px;
        padding: 10px 2px 10px 12px; }
.pick[role="button"] { cursor: pointer; }
.pick:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }

.rk { font-size: 22px; font-weight: 800; text-align: center; font-variant-numeric: tabular-nums; }
.rk.none { color: var(--muted); font-weight: 400; }
.rk.virt { font-size: 18px; font-style: italic; padding-top: 2px; }
.row.p1 .rk { color: var(--podium1); }
.row.p2 .rk { color: var(--podium2); }
.row.p3 .rk { color: var(--podium3); }

.who { min-width: 0; }
.who b { display: block; font-size: 16px; line-height: 1.25; overflow-wrap: anywhere; }
.who .club, .who .sub { display: block; font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
.who .sub { font-size: 12px; margin-top: 2px; }
.who .live { color: var(--accent); }

.sc { text-align: right; }
.sc b { display: block; font-size: 24px; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
.sc small { display: block; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

.star {
    align-self: center; width: 44px; height: 44px; font-size: 24px; line-height: 1;
    background: none; border: 0; color: var(--muted); cursor: pointer; border-radius: 10px;
}
.star[aria-pressed="true"] { color: var(--accent); }
.star:focus-visible { outline: 2px solid var(--accent); }

.row.wait .who b { font-weight: 600; }

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

    var all      = [];                       // une entrée par archer et par catégorie
    var cats     = {};                       // code d'épreuve -> catégorie et son classement
    var labels   = { gold: '10+', xnine: 'X' };
    var refresh  = 15;
    var loaded   = false;
    var failed   = false;
    var multi    = false;                    // plusieurs départs : on précise « Départ 2 »
    var lastOk   = 0;
    var query    = '';
    var view     = null;                     // archer ouvert depuis la liste : { event, key }
    var lastSel  = null;                     // archer sur lequel on a déjà fait défiler l'écran
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
        return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function plural(n, word) { return n + ' ' + word + (n > 1 ? 's' : ''); }

    // Aplatit les sections en une liste d'archers (pour la recherche) et un
    // classement par catégorie (pour l'affichage).
    function flatten(sections) {
        var list = [], byEvent = {};
        sections.forEach(function (sec) {
            var cat = {
                event:   sec.event,
                descr:   sec.descr,
                // Classement à la volée N (voir Lib/Fun_ianselp_departs.php) : 0 = classement officiel.
                eq:      sec.eq || 0,
                eqEnds:  sec.ends || 0,
                rows:    []
            };
            sec.items.forEach(function (it) {
                var a = {
                    key:     (it.id !== undefined && it.id !== null && it.id !== '') ? String(it.id) : sec.event + '|' + it.athlete,
                    event:   sec.event,
                    descr:   sec.descr,
                    rank:    it.rank,
                    athlete: it.athlete,
                    club:    it.club,
                    target:  it.target,
                    score:   it.score,
                    gold:    it.gold,
                    xnine:   it.xnine,
                    session: it.session,
                    eq:      cat.eq,
                    eqEnds:  cat.eqEnds,
                    final:   (it.final === undefined ? null : it.final),   // score final d'un archer terminé
                    end:     (it.end === undefined ? null : it.end),       // volées d'un archer en cours
                    hay:     norm(it.athlete + ' ' + it.club)
                };
                cat.rows.push(a);
                list.push(a);
            });
            byEvent[sec.event] = cat;
        });
        return { list: list, cats: byEvent };
    }

    function starHtml(key, name, on) {
        return '<button class="star" type="button" data-key="' + esc(key) + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
             + ' aria-label="' + (on ? 'Ne plus suivre ' : 'Suivre ') + esc(name) + '">' + (on ? '&#9733;' : '&#9734;') + '</button>';
    }

    // Une ligne : rang, archer, score. En liste de résultats (o.open), toucher la ligne
    // ouvre le classement de la catégorie ; o.cat y ajoute le nom de la catégorie.
    function rowHtml(a, o) {
        var eq = a.eq > 0;
        var r = parseInt(a.rank, 10);
        var cls = 'row' + (r === 1 ? ' p1' : r === 2 ? ' p2' : r === 3 ? ' p3' : '')
                + (fav[a.key] ? ' hl' : '') + (o.sel ? ' sel' : '') + (flashKeys[a.key] ? ' flash' : '');

        // Rang officiel ; à la volée N, en italique pour ne pas le confondre.
        var rk = a.rank === '' ? '&ndash;' : esc(a.rank);
        var rkCls = a.rank === '' ? ' none' : (eq ? ' virt' : '');

        var sub = [];
        if (o.cat) { sub.push(esc(a.descr)); }
        if (multi && a.session) { sub.push('Départ ' + esc(a.session)); }
        if (a.target) { sub.push('Cible ' + esc(a.target)); }

        var state = '';
        if (eq && a.end !== null) { state = '<span class="sub live">En cours · volée ' + esc(a.end) + '/' + esc(a.eqEnds) + '</span>'; }
        else if (eq && a.final !== null) { state = '<span class="sub">Score final ' + esc(a.final) + '</span>'; }

        var det = /^\d+$/.test(String(a.score))
            ? esc(a.gold) + '&times;' + esc(labels.gold) + ' &middot; ' + esc(a.xnine) + '&times;' + esc(labels.xnine)
            : '';

        var inner = '<div class="rk' + rkCls + '">' + rk + '</div>'
                  + '<div class="who"><b>' + esc(a.athlete) + '</b><span class="club">' + esc(a.club) + '</span>'
                  +   (sub.length ? '<span class="sub">' + sub.join(' &middot; ') + '</span>' : '') + state + '</div>'
                  + '<div class="sc"><b>' + esc(a.score) + '</b><small>' + det + '</small></div>';

        var pick = o.open
            ? '<div class="pick" role="button" tabindex="0" data-event="' + esc(a.event) + '" data-key="' + esc(a.key) + '">' + inner + '</div>'
            : '<div class="pick">' + inner + '</div>';
        return '<div class="' + cls + '">' + pick + starHtml(a.key, a.athlete, !!fav[a.key]) + '</div>';
    }

    // Le classement d'une catégorie ; o.sel met un archer en évidence, o.back ajoute « Retour ».
    function catHtml(cat, o) {
        var head = '<div class="cat-head">'
                 + (o.back ? '<button class="back" type="button">&lsaquo; Retour</button>' : '')
                 + '<h2>' + esc(cat.descr) + '</h2>'
                 + '<p>' + plural(cat.rows.length, 'archer') + (cat.eq ? ' &middot; classement à la volée ' + esc(cat.eq) + '/' + esc(cat.eqEnds) : '') + '</p>'
                 + (cat.eq ? '<p class="note">Les archers du départ précédent sont comptés après ' + plural(cat.eq, 'volée')
                           + ', comme s\'ils tiraient en même temps que ceux du départ en cours.</p>' : '')
                 + '</div>';
        return '<section class="cat">' + head
             + cat.rows.map(function (a) { return rowHtml(a, { sel: a.key === o.sel }); }).join('') + '</section>';
    }

    // Archer suivi mais pas (encore) dans le classement.
    function waitHtml(waiting) {
        return '<section class="cat"><div class="cat-head"><h2>En attente de score</h2></div>'
             + waiting.map(function (w) {
                 return '<div class="row wait"><div class="pick"><div class="rk none">&ndash;</div>'
                      + '<div class="who"><b>' + esc(w.name) + '</b><span class="club">Pas encore de score</span></div><div class="sc"></div></div>'
                      + starHtml(w.key, w.name, true) + '</div>';
             }).join('') + '</section>';
    }

    function msg(title, text) {
        return '<div class="msg"><b>' + esc(title) + '</b>' + esc(text) + '</div>';
    }

    function render() {
        var q = norm(query).trim();
        var html = '', count = '', sel = null;

        if (!loaded) {
            html = failed ? msg('Connexion impossible', 'Nouvel essai automatique dans quelques secondes.')
                          : msg('Chargement…', '');
        } else if (view && cats[view.event]) {
            html = catHtml(cats[view.event], { sel: view.key, back: true });
            sel = view.key;
        } else if (q.length >= 2) {
            var tokens = q.split(/\s+/);
            var hits = all.filter(function (a) {
                return tokens.every(function (t) { return a.hay.indexOf(t) !== -1; });
            });
            hits.sort(function (a, b) { return (fav[b.key] ? 1 : 0) - (fav[a.key] ? 1 : 0); });   // tri stable : l'ordre du classement est conservé
            if (!hits.length) {
                html = msg('Aucun résultat pour « ' + query.trim() + ' »',
                           'Un archer apparaît dès que ses premières flèches sont enregistrées. Vérifiez l\'orthographe ou réessayez dans quelques minutes.');
            } else if (hits.length === 1) {
                // Un seul archer : on va droit au classement de sa catégorie.
                html = catHtml(cats[hits[0].event], { sel: hits[0].key });
                sel = hits[0].key;
            } else {
                count = hits.length + ' archers trouvés'
                      + (hits.length > MAX_SHOWN ? ' — les ' + MAX_SHOWN + ' premiers sont affichés, précisez la recherche' : '')
                      + ' · touchez un archer pour voir le classement de sa catégorie';
                html = '<section class="cat">' + hits.slice(0, MAX_SHOWN).map(function (a) {
                    return rowHtml(a, { open: true, cat: true });
                }).join('') + '</section>';
            }
        } else if (q.length === 1) {
            html = msg('Encore une lettre…', 'Tapez au moins deux caractères.');
        } else if (Object.keys(fav).length) {
            // Mes archers : le classement de chaque catégorie suivie, les archers suivis en évidence.
            var seen = {}, order = [], present = {}, waiting = [];
            all.forEach(function (a) { present[a.key] = true; });
            Object.keys(fav).forEach(function (k) {
                if (!present[k]) { waiting.push({ key: k, name: fav[k] }); return; }
                all.forEach(function (a) {
                    if (a.key === k && !seen[a.event]) { seen[a.event] = true; order.push(a.event); }
                });
            });
            count = 'Mes archers';
            html = order.map(function (ev) { return catHtml(cats[ev], {}); }).join('') + (waiting.length ? waitHtml(waiting) : '');
        } else if (!all.length) {
            html = msg('En attente des premiers scores…', 'La liste se remplit au fil du tir.');
        } else {
            html = msg('Qui voulez-vous suivre ?',
                       'Tapez un nom ou un club : vous verrez le classement de la catégorie de l\'archer. Touchez l\'étoile pour le garder sous la main. '
                       + plural(all.length, 'archer') + ' classé' + (all.length > 1 ? 's' : '') + ' pour l\'instant.');
        }

        $count.textContent = count;
        if (html !== lastHtml) {    // évite de reconstruire (et de perdre le focus) quand rien n'a changé
            $list.innerHTML = html;
            lastHtml = html;
        }
        flashKeys = {};

        // On amène l'archer choisi au milieu de l'écran, une seule fois (pas à chaque rafraîchissement).
        if (sel !== lastSel) {
            var el = sel ? $list.querySelector('.row.sel') : null;
            if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center' }); }
            lastSel = sel;
        }
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
                    'Scores provisoires, actualisés toutes les ' + Math.max(refresh, MIN_REFRESH) + ' secondes. Un archer apparaît dès que ses premières flèches sont enregistrées.';

                multi = !!json.multi;
                var flat = flatten(json.sections || []);
                all  = flat.list;
                cats = flat.cats;
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

    function open(el) {
        view = { event: el.getAttribute('data-event'), key: el.getAttribute('data-key') };
        lastHtml = null;
        render();
    }

    $q.addEventListener('input', function () {
        query = $q.value;
        view = null;
        lastSel = null;
        render();
    });

    $list.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target : null;
        if (!t) { return; }

        var star = t.closest('.star');
        if (star) {
            var key = star.getAttribute('data-key');
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
            return;
        }

        if (t.closest('.back')) {
            view = null;
            lastSel = null;
            lastHtml = null;
            render();
            window.scrollTo(0, 0);
            return;
        }

        var pick = t.closest('.pick[data-key]');
        if (pick) { open(pick); }
    });

    $list.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.pick[data-key]')) {
            e.preventDefault();
            open(e.target);
        }
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
