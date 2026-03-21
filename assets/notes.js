/**
 * notes.js – Note-Kacheln:
 *   - Free-Canvas-Layout für Desktop (>=992px, kein "Alle"-Tab) – View + Edit
 *   - Markdown-Rendering im View-Modus
 *   - Live-Speichern von Inhalt (Edit-Modus, debounced)
 *   - Eigener Resize-Handle für Note-Kacheln (Edit-Modus, Free Canvas)
 *   - Delete-Button (Edit-Modus)
 */
document.addEventListener('DOMContentLoaded', () => {

    // Markdown-Rendering für View-Modus
    document.querySelectorAll('.note-view[data-raw]').forEach(el => {
        el.innerHTML = renderMarkdown(el.dataset.raw);
    });

    // Edit-Modus: Live-Speichern (debounced)
    const timers = {};
    document.querySelectorAll('.note-edit-area').forEach(textarea => {
        textarea.addEventListener('input', () => {
            const tile = textarea.closest('.note-tile');
            const id   = tile.dataset.noteId;
            clearTimeout(timers['c_' + id]);
            timers['c_' + id] = setTimeout(() => saveNoteContent(tile), 800);
        });
    });
    document.querySelectorAll('.note-title-input').forEach(input => {
        input.addEventListener('input', () => {
            const tile = input.closest('.note-tile');
            const id   = tile.dataset.noteId;
            clearTimeout(timers['c_' + id]);
            timers['c_' + id] = setTimeout(() => saveNoteContent(tile), 800);
        });
    });

    // Edit-Modus: Note löschen (sofort, kein Reload)
    document.querySelectorAll('.delete-note').forEach(btn => {
        btn.addEventListener('click', () => {
            const tile = btn.closest('.note-tile');
            const id   = tile.dataset.noteId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + encodeURIComponent(id)
            })
            .then(r => r.json())
            .then(data => { if (data.ok) tile.remove(); })
            .catch(err => console.error('Fehler beim Löschen der Note:', err));
        });
    });

    // Free Canvas Layout – View-Modus UND Edit-Modus (Desktop >=992px, kein Alle-Tab)
    const container = document.getElementById('categories');
    if (!container) return;

    const tabSlug = container.dataset.tabSlug || 'alle';
    const tabId   = container.dataset.tabId   || '';
    const isEdit  = document.body.classList.contains('edit-mode');

    if (window.innerWidth >= 992 && tabSlug !== 'alle') {
        initFreeCanvasLayout(container, tabId, isEdit);
    }
});

// Positionierungslogik für den Free Canvas
function initFreeCanvasLayout(container, tabId, isEdit) {
    if (isEdit) {
        container.querySelectorAll('[draggable]').forEach(el => el.removeAttribute('draggable'));
    }

    container.classList.add('free-canvas');

    const items = Array.from(container.querySelectorAll('.category, .note-tile'));

    // Note-Kacheln: gespeicherte Grösse anwenden
    items.forEach(function(item) {
        if (item.classList.contains('note-tile')) {
            item.style.width  = (parseInt(item.dataset.width)  || 360) + 'px';
            item.style.height = (parseInt(item.dataset.height) || 200) + 'px';
        }
    });

    // Positionen setzen (gespeichert oder Auto-Layout)
    var GAP = 15;
    var autoX = GAP, autoY = GAP, rowH = 0;

    items.forEach(function(item) {
        var rawX = item.dataset.posX;
        var rawY = item.dataset.posY;
        var posX = (rawX !== '' && rawX !== undefined) ? parseInt(rawX) : NaN;
        var posY = (rawY !== '' && rawY !== undefined) ? parseInt(rawY) : NaN;

        if (!isNaN(posX) && !isNaN(posY)) {
            item.style.left = posX + 'px';
            item.style.top  = posY + 'px';
        } else {
            var iW     = item.offsetWidth  || (item.classList.contains('note-tile') ? 360 : 240);
            var availW = container.offsetWidth - GAP * 2;
            if (autoX + iW > availW + GAP && autoX > GAP) {
                autoX = GAP;
                autoY += rowH + GAP;
                rowH  = 0;
            }
            item.style.left = autoX + 'px';
            item.style.top  = autoY + 'px';
            var iH  = item.offsetHeight || (item.classList.contains('note-tile') ? 200 : 260);
            rowH    = Math.max(rowH, iH);
            autoX  += iW + GAP;
        }
    });

    updateCanvasHeight(container);

    // Resize-Handles nur im Edit-Modus hinzufügen
    if (isEdit) {
        items.forEach(function(item) {
            if (item.classList.contains('note-tile')) {
                addResizeHandle(item, tabId, container);
            }
        });
    }
}

// Canvas-Mindesthöhe nachführen (global, auch von sort.js genutzt)
window.updateCanvasHeight = function(container) {
    var maxBottom = 600;
    container.querySelectorAll('.category, .note-tile').forEach(function(item) {
        var b = (parseInt(item.style.top) || 0) + item.offsetHeight + 80;
        maxBottom = Math.max(maxBottom, b);
    });
    container.style.minHeight = maxBottom + 'px';
};

function updateCanvasHeight(c) { window.updateCanvasHeight(c); }

// Eigener Resize-Handle für Note-Kacheln
function addResizeHandle(tile, tabId, container) {
    var handle = document.createElement('div');
    handle.className = 'note-resize-handle';
    tile.appendChild(handle);

    var startX, startY, startW, startH;

    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();

        startX = e.clientX;
        startY = e.clientY;
        startW = tile.offsetWidth;
        startH = tile.offsetHeight;

        function onMove(ev) {
            tile.style.width  = Math.max(150, startW + (ev.clientX - startX)) + 'px';
            tile.style.height = Math.max(80,  startH + (ev.clientY - startY)) + 'px';
            updateCanvasHeight(container);
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup',   onUp);
            var w   = tile.offsetWidth;
            var h   = tile.offsetHeight;
            var id  = tile.dataset.noteId;
            var tid = tile.dataset.tabId || tabId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_position&id=' + encodeURIComponent(id) +
                      '&tab_id=' + encodeURIComponent(tid) +
                      '&width=' + w + '&height=' + h
            }).catch(function(err) { console.error('Fehler beim Speichern der Note-Grösse:', err); });
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup',   onUp);
    });
}

// Note-Inhalt per AJAX speichern
function saveNoteContent(tile) {
    var id      = tile.dataset.noteId;
    var titleEl = tile.querySelector('.note-title-input');
    var bodyEl  = tile.querySelector('.note-edit-area');
    if (!titleEl || !bodyEl) return;
    var title = titleEl.value.trim();
    if (!title) return;
    fetch('notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_content' +
              '&id=' + encodeURIComponent(id) +
              '&title=' + encodeURIComponent(title) +
              '&content=' + encodeURIComponent(bodyEl.value)
    }).catch(function(err) { console.error('Fehler beim Speichern der Note:', err); });
}

// Einfacher Markdown > HTML Renderer (XSS-sicher)
function renderMarkdown(raw) {
    if (!raw) return '';
    var s = raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    s = s.replace(/```([\s\S]*?)```/g, function(_, code) {
        return '<pre><code>' + code.replace(/^\n/, '').replace(/\n$/, '') + '</code></pre>';
    });
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    s = s.replace(/^## (.+)$/gm,  '<h2>$1</h2>');
    s = s.replace(/^# (.+)$/gm,   '<h1>$1</h1>');
    s = s.replace(/^---$/gm, '<hr>');
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g,     '<em>$1</em>');
    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(_, text, url) {
        var u = url.replace(/&amp;/g, '&');
        if (/^https?:\/\//i.test(u)) {
            return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        }
        return text;
    });
    s = s.replace(/^[*-] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>[^\n]*<\/li>\n?)+/g, function(m) { return '<ul>' + m + '</ul>'; });

    var blocks = s.split(/\n{2,}/);
    s = blocks.map(function(block) {
        var t = block.trim();
        if (!t) return '';
        if (/^<(h[1-6]|ul|ol|pre|hr|blockquote)[\s>]/.test(t)) return t;
        return '<p>' + t.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');
    return s;
}
