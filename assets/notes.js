/**
 * notes.js – Note tiles:
 *   - Free-Canvas layout for desktop (>=992px, not "Alle" tab) – View + Edit mode
 *   - Markdown rendering in view mode
 *   - Live-save content (edit mode, debounced)
 *   - Custom resize handle for note tiles (edit mode, free canvas)
 *   - Delete button (edit mode)
 */
document.addEventListener('DOMContentLoaded', () => {

    // Markdown rendering for view mode
    document.querySelectorAll('.note-view[data-raw]').forEach(el => {
        el.innerHTML = renderMarkdown(el.dataset.raw);
    });

    // Edit mode: live-save (debounced)
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
    // Edit mode: delete note (instant, no reload)
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
            .catch(err => console.error('Error deleting note:', err));
        });
    });

    // Free Canvas Layout – View-Modus UND Edit-Modus (Desktop >=992px, kein Alle-Tab)
        // Free Canvas layout – view mode AND edit mode (desktop >=992px, not Alle tab)
    const container = document.getElementById('categories');
    if (!container) return;

    const tabSlug = container.dataset.tabSlug || 'alle';
    const tabId   = container.dataset.tabId   || '';
    const isEdit  = document.body.classList.contains('edit-mode');

    if (window.innerWidth >= 992 && tabSlug !== 'alle') {
        initFreeCanvasLayout(container, tabId, isEdit);
    }
});

// Positioning logic for the free canvas
function initFreeCanvasLayout(container, tabId, isEdit) {
    if (isEdit) {
        container.querySelectorAll('[draggable]').forEach(el => el.removeAttribute('draggable'));
    }

    container.classList.add('free-canvas');

    const items = Array.from(container.querySelectorAll('.category, .note-tile'));

    // Note tiles: apply saved size
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

    // Add resize handles in edit mode only
    if (isEdit) {
        items.forEach(function(item) {
            if (item.classList.contains('note-tile')) {
                addResizeHandle(item, tabId, container);
            }
        });
    }
}

// Update canvas min-height (global, also used by sort.js)
window.updateCanvasHeight = function(container) {
    var maxBottom = 600;
    container.querySelectorAll('.category, .note-tile').forEach(function(item) {
        var b = (parseInt(item.style.top) || 0) + item.offsetHeight + 80;
        maxBottom = Math.max(maxBottom, b);
    });
    container.style.minHeight = maxBottom + 'px';
};

function updateCanvasHeight(c) { window.updateCanvasHeight(c); }

// Custom resize handle for note tiles
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
            }).catch(function(err) { console.error('Error saving note size:', err); });
            }).catch(function(err) { console.error('Error saving note size:', err); });
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup',   onUp);
    });
}

// Save note content via AJAX
function saveNoteContent(tile) {
    var id     = tile.dataset.noteId;
    var bodyEl = tile.querySelector('.note-edit-area');
    if (!bodyEl) return;
    fetch('notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_content' +
              '&id=' + encodeURIComponent(id) +
              '&content=' + encodeURIComponent(bodyEl.value)
    }).catch(function(err) { console.error('Error saving note:', err); });
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
