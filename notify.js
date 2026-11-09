// www/js/notify.js
// Pretty pop-up messages for the whole app.
//
//  - Replaces the plain browser alert() with a styled dialog (success / error / warning / info).
//  - Adds showConfirm() as a styled replacement for confirm().
//  - Works on every page: just add  <script src="js/notify.js"></script>  in the <head>.
//
//  alert("...")        -> shows the dialog (returns a Promise, so `await alert(...)` waits for OK)
//  showAlert(msg, {type, title, buttonText})
//  showConfirm(msg, {title, confirmText, cancelText, type}) -> Promise<true|false>
(function () {
    if (window.__notifyReady) return;
    window.__notifyReady = true;

    var nativeAlert = window.alert ? window.alert.bind(window) : null;

    var SVG = {
        success: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
        error:   '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        warning: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><circle cx="12" cy="16.5" r="0.6" fill="currentColor"/><path d="M10.3 3.9L2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
        info:    '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 11v6"/><circle cx="12" cy="7.5" r="0.7" fill="currentColor"/><circle cx="12" cy="12" r="9.5"/></svg>',
        confirm: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.2.9-1.2 1.7"/><circle cx="12" cy="16.8" r="0.6" fill="currentColor"/></svg>'
    };

    var THEME = {
        success: { fg: '#10b981', bg: '#ecfdf5', ring: '#d1fae5', b1: '#10b981', b2: '#059669', sh: 'rgba(16,185,129,.32)', title: 'Success' },
        error:   { fg: '#ef4444', bg: '#fef2f2', ring: '#fee2e2', b1: '#ef4444', b2: '#dc2626', sh: 'rgba(239,68,68,.30)',  title: 'Something went wrong' },
        warning: { fg: '#f59e0b', bg: '#fffbeb', ring: '#fef3c7', b1: '#f59e0b', b2: '#d97706', sh: 'rgba(245,158,11,.32)', title: 'Please check' },
        info:    { fg: '#0ea5e9', bg: '#e0f2fe', ring: '#bae6fd', b1: '#0ea5e9', b2: '#0284c7', sh: 'rgba(14,165,233,.32)', title: 'Notice' },
        confirm: { fg: '#0ea5e9', bg: '#e0f2fe', ring: '#bae6fd', b1: '#0ea5e9', b2: '#0284c7', sh: 'rgba(14,165,233,.32)', title: 'Are you sure?' },
        danger:  { fg: '#ef4444', bg: '#fef2f2', ring: '#fee2e2', b1: '#ef4444', b2: '#dc2626', sh: 'rgba(239,68,68,.30)',  title: 'Are you sure?' }
    };

    var CSS = '' +
        '.nf-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:24px;' +
            'background:rgba(23,32,51,.55);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);animation:nfFade .2s ease both;}' +
        '.nf-overlay.nf-out{animation:nfFadeOut .16s ease both;}' +
        '.nf-card{width:100%;max-width:340px;box-sizing:border-box;background:#fff;border-radius:28px;padding:30px 22px 20px;text-align:center;color:#172033;' +
            'font-family:"Inter",system-ui,-apple-system,"Segoe UI",sans-serif;box-shadow:0 24px 60px rgba(23,32,51,.28);animation:nfPop .3s cubic-bezier(.2,.9,.3,1.15) both;}' +
        '.nf-out .nf-card{animation:nfPopOut .16s ease both;}' +
        '.nf-icon{width:72px;height:72px;border-radius:50%;margin:0 auto 18px;display:flex;align-items:center;justify-content:center;}' +
        '.nf-title{margin:0 0 8px;font-size:20px;font-weight:700;letter-spacing:-.3px;line-height:1.25;}' +
        '.nf-msg{margin:0;font-size:14px;line-height:1.55;color:#64748b;white-space:pre-wrap;word-break:break-word;max-height:38vh;overflow:auto;}' +
        '.nf-msg.nf-tech{text-align:left;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;color:#475569;background:#f4f6fa;border-radius:14px;padding:12px;}' +
        '.nf-actions{display:flex;gap:10px;margin-top:24px;}' +
        '.nf-btn{flex:1;border:none;border-radius:999px;padding:14px 12px;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;transition:transform .15s;}' +
        '.nf-btn:active{transform:scale(.97);}' +
        '.nf-btn.nf-ghost{background:#f1f5f9;color:#475569;}' +
        '@keyframes nfFade{from{opacity:0}to{opacity:1}}' +
        '@keyframes nfFadeOut{from{opacity:1}to{opacity:0}}' +
        '@keyframes nfPop{from{opacity:0;transform:translateY(16px) scale(.94)}to{opacity:1;transform:none}}' +
        '@keyframes nfPopOut{from{opacity:1;transform:none}to{opacity:0;transform:translateY(8px) scale(.97)}}' +
        '@media (prefers-reduced-motion:reduce){.nf-overlay,.nf-card{animation:none!important}}';

    function injectCss() {
        if (document.getElementById('nf-style')) return;
        var s = document.createElement('style');
        s.id = 'nf-style';
        s.textContent = CSS;
        (document.head || document.documentElement).appendChild(s);
    }

    // ---- decide the look from the message itself ----
    function detectType(raw) {
        var t = String(raw).trim();
        var l = t.toLowerCase();
        if (/^🎉|^✅/.test(t)) return 'success';
        if (/^❌|^⛔|^🚫/.test(t)) return 'error';
        if (/attempting|found \d+ saved|syncing/.test(l)) return 'info';
        if (/error|failed|crash|denied|could not|couldn't|cannot|unable|invalid|incorrect/.test(l)) return 'error';
        if (/^⚠/.test(t)) return 'warning';
        if (/success|saved|synced|updated|created|submitted|welcome/.test(l)) return 'success';
        if (/please|must|required|first/.test(l)) return 'warning';
        return 'info';
    }

    function cleanMessage(raw) {
        return String(raw)
            .replace(/^[\s🎉✅⚠️❌⛔🚫ℹ️]+/u, '')
            .replace(/^success!?\s*/i, '')
            .trim();
    }

    // ---- one dialog at a time ----
    var queue = [];
    var busy = false;

    function enqueue(job) {
        return new Promise(function (resolve) {
            job.resolve = resolve;
            queue.push(job);
            pump();
        });
    }

    function pump() {
        if (busy || !queue.length) return;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', pump, { once: true });
            return;
        }
        busy = true;
        render(queue.shift());
    }

    function render(job) {
        injectCss();
        var theme = THEME[job.theme] || THEME.info;

        var overlay = document.createElement('div');
        overlay.className = 'nf-overlay';
        overlay.setAttribute('role', job.isConfirm ? 'alertdialog' : 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        var card = document.createElement('div');
        card.className = 'nf-card';

        var icon = document.createElement('div');
        icon.className = 'nf-icon';
        icon.style.background = theme.bg;
        icon.style.color = theme.fg;
        icon.style.boxShadow = '0 0 0 8px ' + theme.ring;
        icon.innerHTML = SVG[job.icon] || SVG.info;

        var title = document.createElement('h3');
        title.className = 'nf-title';
        title.textContent = job.title || theme.title;

        var msg = document.createElement('p');
        msg.className = 'nf-msg';
        msg.textContent = job.message;   // textContent: server errors containing HTML are shown safely as text
        if (job.message.length > 160 || /php|<\/?[a-z]+/i.test(job.message)) msg.classList.add('nf-tech');

        var actions = document.createElement('div');
        actions.className = 'nf-actions';

        function closeWith(value) {
            if (overlay.__closed) return;
            overlay.__closed = true;
            document.removeEventListener('keydown', onKey, true);
            overlay.classList.add('nf-out');
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                busy = false;
                job.resolve(value);
                pump();
            }, 150);
        }

        function makeBtn(label, ghost, value) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'nf-btn' + (ghost ? ' nf-ghost' : '');
            b.textContent = label;
            if (!ghost) {
                b.style.color = '#fff';
                b.style.background = 'linear-gradient(135deg,' + theme.b1 + ',' + theme.b2 + ')';
                b.style.boxShadow = '0 8px 18px ' + theme.sh;
            }
            b.addEventListener('click', function () { closeWith(value); });
            return b;
        }

        var okBtn;
        if (job.isConfirm) {
            actions.appendChild(makeBtn(job.cancelText, true, false));
            okBtn = makeBtn(job.confirmText, false, true);
            actions.appendChild(okBtn);
        } else {
            okBtn = makeBtn(job.buttonText, false, true);
            actions.appendChild(okBtn);
        }

        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); closeWith(job.isConfirm ? false : true); }
        }
        document.addEventListener('keydown', onKey, true);

        // tapping the dark area dismisses (cancels, for confirmations)
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeWith(job.isConfirm ? false : true);
        });

        card.appendChild(icon);
        card.appendChild(title);
        card.appendChild(msg);
        card.appendChild(actions);
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        try { okBtn.focus({ preventScroll: true }); } catch (e) {}
    }

    // ---- public API ----
    window.showAlert = function (message, opts) {
        opts = opts || {};
        var raw = (message === undefined || message === null) ? '' : String(message);
        var type = opts.type || detectType(raw);
        return enqueue({
            isConfirm: false,
            theme: type,
            icon: type,
            title: opts.title,
            message: cleanMessage(raw),
            buttonText: opts.buttonText || 'OK'
        });
    };

    window.showConfirm = function (message, opts) {
        opts = opts || {};
        var danger = opts.type === 'danger';
        return enqueue({
            isConfirm: true,
            theme: danger ? 'danger' : 'confirm',
            icon: 'confirm',
            title: opts.title,
            message: cleanMessage(message),
            confirmText: opts.confirmText || 'Yes',
            cancelText: opts.cancelText || 'Cancel'
        });
    };

    // Every existing alert("...") in the app now uses the new design automatically.
    window.alert = function (message) { return window.showAlert(message); };
    window.__nativeAlert = nativeAlert;
})();
