/**
 * notes.js – Note-Kacheln: Markdown-Rendering, Live-Speichern, Resize-Tracking
 */
document.addEventListener('DOMContentLoaded', () => {

    // ----------------------------------------------------------------
    // Markdown-Rendering für View-Modus
    // ----------------------------------------------------------------
    document.querySelectorAll('.note-view[data-raw]').forEach(el => {
        el.innerHTML = renderMarkdown(el.dataset.raw);
    });

    // ----------------------------------------------------------------
    // Edit-Modus: Live-Speichern (Titel + Inhalt, debounced)
    // ----------------------------------------------------------------
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

    // ----------------------------------------------------------------
    // Edit-Modus: Note im Edit-Modus (inline ohne Reload) löschen
    // ----------------------------------------------------------------
    document.querySelectorAll('.delete-note').forEach(btn => {
        btn.addEventListener('click', () => {
            const tile = btn.closest('.note-tile');
            const id   = tile.dataset.noteId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=delete&id=${encodeURIComponent(id)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) tile.remove();
            })
            .catch(err => console.error('Fehler beim Löschen der Note:', err));
        });
    });

    // ----------------------------------------------------------------
    // Resize-Observer: Größenänderung von Note-Kacheln speichern
    // Nur im Free-Canvas-Modus sinnvoll (resize: both aktiv)
    // ----------------------------------------------------------------
    if (typeof ResizeObserver !== 'undefined') {
        const resizeTimers = {};
        let initialSet = new Set();

        const ro = new ResizeObserver(entries => {
            for (const entry of entries) {
                const tile = entry.target;
                if (!tile.classList.contains('note-tile')) continue;

                // Erste Benachrichtigung (initial render) ignorieren
                if (!initialSet.has(tile)) {
                    initialSet.add(tile);
                    continue;
                }

                const id = tile.dataset.noteId;
                clearTimeout(resizeTimers[id]);
                resizeTimers[id] = setTimeout(() => {
                    const w     = tile.offsetWidth;
                    const h     = tile.offsetHeight;
                    const tabId = tile.dataset.tabId;
                    if (w && h && tabId) {
                        fetch('notes.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=update_position&id=${encodeURIComponent(id)}&tab_id=${encodeURIComponent(tabId)}&width=${w}&height=${h}`
                        }).catch(err => console.error('Fehler beim Speichern der Note-Größe:', err));
                    }
                }, 500);
            }
        });

        document.querySelectorAll('.note-tile').forEach(tile => {
            ro.observe(tile);
        });
    }
});

// ----------------------------------------------------------------
// Note-Inhalt per AJAX speichern
// ----------------------------------------------------------------
function saveNoteContent(tile) {
    const id      = tile.dataset.noteId;
    const titleEl = tile.querySelector('.note-title-input');
    const bodyEl  = tile.querySelector('.note-edit-area');
    if (!titleEl || !bodyEl) return;

    const title   = titleEl.value.trim();
    const content = bodyEl.value;
    if (!title) return;

    fetch('notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_content`
            + `&id=${encodeURIComponent(id)}`
            + `&title=${encodeURIComponent(title)}`
            + `&content=${encodeURIComponent(content)}`
    }).catch(err => console.error('Fehler beim Speichern der Note:', err));
}

// ----------------------------------------------------------------
// Einfacher Markdown → HTML Renderer
// Unterstützt: Headings, Bold, Italic, Code, Code-Blocks,
//              Listen, Links, Horizontale Trennlinie, Absätze
// XSS-sicher: HTML wird zuerst escaped, Links nur https?://
// ----------------------------------------------------------------
function renderMarkdown(raw) {
    if (!raw) return '';

    // HTML escapen (Sicherheit)
    let s = raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    // Fenced Code Blocks: ```...```
    s = s.replace(/```([\s\S]*?)```/g, (_, code) =>
        '<pre><code>' + code.replace(/^\n/, '').replace(/\n$/, '') + '</code></pre>');

    // Inline Code: `...`
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');

    // Headings
    s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    s = s.replace(/^## (.+)$/gm,  '<h2>$1</h2>');
    s = s.replace(/^# (.+)$/gm,   '<h1>$1</h1>');

    // Horizontale Linie
    s = s.replace(/^---$/gm, '<hr>');

    // Bold
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Italic
    s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // Links – nur https?:// erlaubt (XSS-Schutz)
    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, text, url) => {
        const decodedUrl = url.replace(/&amp;/g, '&');
        if (/^https?:\/\//i.test(decodedUrl)) {
            return '<a href="' + decodedUrl + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        }
        return text;
    });

    // Unordered list items: - item
    s = s.replace(/^[*-] (.+)$/gm, '<li>$1</li>');
    // Aufeinanderfolgende <li> in <ul> wrappen
    s = s.replace(/(<li>[^\n]*<\/li>\n?)+/g, m => '<ul>' + m + '</ul>');

    // Absätze: Doppelter Zeilenumbruch = Absatzgrenze
    const blocks = s.split(/\n{2,}/);
    s = blocks.map(block => {
        const t = block.trim();
        if (!t) return '';
        // Block-Elemente nicht in <p> wrappen
        if (/^<(h[1-6]|ul|ol|pre|hr|blockquote)[\s>]/.test(t)) return t;
        return '<p>' + t.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');

    return s;
}
