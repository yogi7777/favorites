/**
 * notes.js – Note tiles:
 *   - Free-canvas layout for desktop (>=992px, not "alle" tab) in view + edit mode
 *   - Markdown rendering in view mode
 *   - Live-save note content in edit mode
 *   - Custom resize handle for note tiles in edit mode
 *   - Delete note button in edit mode
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.note-view[data-raw]').forEach((el) => {
        el.innerHTML = renderMarkdown(el.dataset.raw);
    });

    const timers = {};
    document.querySelectorAll('.note-edit-area').forEach((textarea) => {
        textarea.addEventListener('input', () => {
            const tile = textarea.closest('.note-tile');
            if (!tile) return;
            const id = tile.dataset.noteId;
            clearTimeout(timers['c_' + id]);
            timers['c_' + id] = setTimeout(() => saveNoteContent(tile), 800);
        });
    });

    document.querySelectorAll('.delete-note').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            if (!confirm('Are you sure you want to delete this note? This cannot be undone.')) {
                return;
            }

            const tile = btn.closest('.note-tile');
            if (!tile) return;
            const id = tile.dataset.noteId;

            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + encodeURIComponent(id)
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.ok) tile.remove();
                })
                .catch((err) => console.error('Error deleting note:', err));
        });
    });

    const container = document.getElementById('categories');
    if (!container) return;

    const tabSlug = container.dataset.tabSlug || 'alle';
    const tabId = container.dataset.tabId || '';
    const isEdit = document.body.classList.contains('edit-mode');
    const hasStoredPositions = hasStoredCanvasPositions(container);

    if (tabSlug === 'alle') {
        sortTilesByHeaderTitle(container);
    }

    if (window.innerWidth >= 992 && tabSlug !== 'alle' && hasStoredPositions) {
        initFreeCanvasLayout(container, tabId, isEdit);
    }
});

function hasStoredCanvasPositions(container) {
    const items = Array.from(container.querySelectorAll('.category, .note-tile'));
    if (!items.length) return false;

    return items.some((item) => {
        const x = item.dataset.posX;
        const y = item.dataset.posY;
        if (x === '' || y === '' || x === undefined || y === undefined) return false;

        const px = parseInt(x, 10);
        const py = parseInt(y, 10);
        return !Number.isNaN(px) && !Number.isNaN(py);
    });
}

function sortTilesByHeaderTitle(container) {
    const tiles = Array.from(container.querySelectorAll(':scope > .category, :scope > .note-tile'));
    if (!tiles.length) return;

    tiles.sort((a, b) => {
        const aTitle = getTileHeaderTitle(a);
        const bTitle = getTileHeaderTitle(b);
        const aKey = normalizeSortKey(aTitle);
        const bKey = normalizeSortKey(bTitle);

        const keyCmp = aKey.localeCompare(bKey, undefined, { sensitivity: 'base', numeric: true });
        if (keyCmp !== 0) return keyCmp;

        // Stable tie-breaker with original title text.
        return aTitle.localeCompare(bTitle, undefined, { sensitivity: 'base', numeric: true });
    });

    tiles.forEach((tile) => container.appendChild(tile));
}

function getTileHeaderTitle(tile) {
    const titleEl = tile.querySelector('.card-header .card-title, .card-header .note-header-title');
    return (titleEl ? titleEl.textContent : '').trim();
}

function normalizeSortKey(title) {
    if (!title) return '';

    let key = title.normalize('NFKD').trim().toLowerCase();

    // Ignore leading emojis/symbols/punctuation for alphabetic sorting.
    key = key.replace(/^[^\p{L}\p{N}]+/u, '');

    // Normalize internal whitespace.
    key = key.replace(/\s+/g, ' ').trim();

    return key || title.toLowerCase();
}

function initFreeCanvasLayout(container, tabId, isEdit) {
    if (isEdit) {
        container.querySelectorAll('[draggable]').forEach((el) => el.removeAttribute('draggable'));
    }

    container.classList.add('free-canvas');

    const items = Array.from(container.querySelectorAll('.category, .note-tile'));

    items.forEach((item) => {
        if (item.classList.contains('note-tile')) {
            item.style.width = (parseInt(item.dataset.width, 10) || 360) + 'px';
            item.style.height = (parseInt(item.dataset.height, 10) || 200) + 'px';
        }
    });

    const GAP = 15;
    let autoX = GAP;
    let autoY = GAP;
    let rowH = 0;

    items.forEach((item) => {
        const rawX = item.dataset.posX;
        const rawY = item.dataset.posY;
        const posX = rawX !== '' && rawX !== undefined ? parseInt(rawX, 10) : NaN;
        const posY = rawY !== '' && rawY !== undefined ? parseInt(rawY, 10) : NaN;

        if (!Number.isNaN(posX) && !Number.isNaN(posY)) {
            item.style.left = posX + 'px';
            item.style.top = posY + 'px';
        } else {
            const iW = item.offsetWidth || (item.classList.contains('note-tile') ? 360 : 240);
            const availW = container.offsetWidth - GAP * 2;
            if (autoX + iW > availW + GAP && autoX > GAP) {
                autoX = GAP;
                autoY += rowH + GAP;
                rowH = 0;
            }
            item.style.left = autoX + 'px';
            item.style.top = autoY + 'px';
            const iH = item.offsetHeight || (item.classList.contains('note-tile') ? 200 : 260);
            rowH = Math.max(rowH, iH);
            autoX += iW + GAP;
        }
    });

    updateCanvasHeight(container);

    if (isEdit) {
        items.forEach((item) => {
            if (item.classList.contains('note-tile')) {
                addResizeHandle(item, tabId, container);
            }
        });
    }
}

window.updateCanvasHeight = function updateCanvasHeightGlobal(container) {
    let maxBottom = 600;
    container.querySelectorAll('.category, .note-tile').forEach((item) => {
        const bottom = (parseInt(item.style.top, 10) || 0) + item.offsetHeight + 80;
        maxBottom = Math.max(maxBottom, bottom);
    });
    container.style.minHeight = maxBottom + 'px';
};

function updateCanvasHeight(container) {
    window.updateCanvasHeight(container);
}

function addResizeHandle(tile, tabId, container) {
    const handle = document.createElement('div');
    handle.className = 'note-resize-handle';
    tile.appendChild(handle);

    let startX;
    let startY;
    let startW;
    let startH;

    handle.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();

        startX = e.clientX;
        startY = e.clientY;
        startW = tile.offsetWidth;
        startH = tile.offsetHeight;

        function onMove(ev) {
            tile.style.width = Math.max(150, startW + (ev.clientX - startX)) + 'px';
            tile.style.height = Math.max(80, startH + (ev.clientY - startY)) + 'px';
            updateCanvasHeight(container);
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);

            const w = tile.offsetWidth;
            const h = tile.offsetHeight;
            const id = tile.dataset.noteId;
            const tid = tile.dataset.tabId || tabId;

            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:
                    'action=update_position&id=' +
                    encodeURIComponent(id) +
                    '&tab_id=' +
                    encodeURIComponent(tid) +
                    '&width=' +
                    w +
                    '&height=' +
                    h
            }).catch((err) => console.error('Error saving note size:', err));
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
}

function saveNoteContent(tile) {
    const id = tile.dataset.noteId;
    const bodyEl = tile.querySelector('.note-edit-area');
    if (!bodyEl) return;

    fetch('notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:
            'action=update_content' +
            '&id=' +
            encodeURIComponent(id) +
            '&content=' +
            encodeURIComponent(bodyEl.value)
    }).catch((err) => console.error('Error saving note:', err));
}

function renderMarkdown(raw) {
    if (!raw) return '';

    let s = raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    s = s.replace(/```([\s\S]*?)```/g, (_, code) => {
        return '<pre><code>' + code.replace(/^\n/, '').replace(/\n$/, '') + '</code></pre>';
    });
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    s = s.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    s = s.replace(/^# (.+)$/gm, '<h1>$1</h1>');
    s = s.replace(/^---$/gm, '<hr>');
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, text, url) => {
        const u = url.replace(/&amp;/g, '&');
        if (/^https?:\/\//i.test(u)) {
            return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        }
        return text;
    });
    s = s.replace(/^[*-] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>[^\n]*<\/li>\n?)+/g, (m) => '<ul>' + m + '</ul>');

    const blocks = s.split(/\n{2,}/);
    s = blocks
        .map((block) => {
            const t = block.trim();
            if (!t) return '';
            if (/^<(h[1-6]|ul|ol|pre|hr|blockquote)[\s>]/.test(t)) return t;
            return '<p>' + t.replace(/\n/g, '<br>') + '</p>';
        })
        .join('\n');

    return s;
}
