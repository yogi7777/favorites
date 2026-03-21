/**
 * sort.js – Dual-Mode-Drag:
 *   • Free-Canvas  (≥992px, Edit-Modus, kein "Alle"-Tab):
 *       Absolute Positionierung + Mouse-Drag für Kategorien & Note-Kacheln.
 *       Positionen werden per AJAX in notes.php gespeichert.
 *   • Grid-Sort    (Mobile / "Alle"-Tab):
 *       Drag-to-Swap wie bisher; Reihenfolge wird per AJAX gespeichert.
 */
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('[data-sortable]');
    if (!container) return;

    const tabSlug = container.dataset.tabSlug || 'alle';
    const tabId   = container.dataset.tabId   || '';

    // Free-Canvas nur auf Desktop (≥992px) und nicht auf dem "Alle"-Tab
    const isFreeCanvas = window.innerWidth >= 992 && tabSlug !== 'alle';

    if (isFreeCanvas) {
        initFreeCanvas(container, tabId);
    } else {
        initGridSort(container, tabSlug);
    }
});

/* ================================================================
   FREE CANVAS MODE
   ================================================================ */

function initFreeCanvas(container, tabId) {
    // HTML5-Draggable deaktivieren (interferiert mit Mouse-Events)
    container.querySelectorAll('[draggable]').forEach(el => el.removeAttribute('draggable'));

    container.classList.add('free-canvas');

    const items = [...container.querySelectorAll('.category, .note-tile')];

    // 1. Note-Kacheln: gespeicherte Größe anwenden
    items.forEach(item => {
        if (item.classList.contains('note-tile')) {
            item.style.width  = (parseInt(item.dataset.width)  || 360) + 'px';
            item.style.height = (parseInt(item.dataset.height) || 200) + 'px';
        }
    });

    // 2. Positionen setzen (gespeichert oder Auto-Layout)
    //    offsetHeight lesen erzwingt Reflow → Höhen bekannt
    const GAP = 15;
    let autoX = GAP, autoY = GAP, curRowH = 0;

    items.forEach(item => {
        const posX = item.dataset.posX !== '' ? parseInt(item.dataset.posX) : NaN;
        const posY = item.dataset.posY !== '' ? parseInt(item.dataset.posY) : NaN;

        if (!isNaN(posX) && !isNaN(posY)) {
            item.style.left = posX + 'px';
            item.style.top  = posY + 'px';
        } else {
            // Auto-Layout: Zeilen-basiertes Fließen
            const itemW  = item.offsetWidth  || (item.classList.contains('note-tile') ? 360 : 240);
            const availW = container.offsetWidth - GAP * 2;
            if (autoX + itemW > availW + GAP && autoX > GAP) {
                autoX   = GAP;
                autoY  += curRowH + GAP;
                curRowH = 0;
            }
            item.style.left = autoX + 'px';
            item.style.top  = autoY + 'px';
            const itemH = item.offsetHeight || (item.classList.contains('note-tile') ? 200 : 260);
            curRowH = Math.max(curRowH, itemH);
            autoX  += itemW + GAP;
        }
    });

    updateCanvasHeight(container);

    // 3. Mouse-Drag
    let dragged = null;
    let offsetX = 0;
    let offsetY = 0;

    container.addEventListener('mousedown', e => {
        // Interaktive Elemente im Inneren nicht blockieren
        if (['TEXTAREA', 'INPUT', 'BUTTON', 'A', 'SELECT'].includes(e.target.tagName)) return;

        const tile = e.target.closest('.category, .note-tile');
        if (!tile) return;

        dragged = tile;
        const rect          = tile.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;

        dragged.classList.add('dragging');
        dragged.style.zIndex = 1000;
        e.preventDefault();
    });

    document.addEventListener('mousemove', e => {
        if (!dragged) return;
        const containerRect = container.getBoundingClientRect();
        const x = Math.max(0, e.clientX - containerRect.left - offsetX);
        const y = Math.max(0, e.clientY - containerRect.top  - offsetY);
        dragged.style.left = x + 'px';
        dragged.style.top  = y + 'px';
    });

    document.addEventListener('mouseup', () => {
        if (!dragged) return;

        dragged.classList.remove('dragging');
        dragged.style.zIndex = '';

        const x = Math.max(0, parseInt(dragged.style.left) || 0);
        const y = Math.max(0, parseInt(dragged.style.top)  || 0);

        if (dragged.classList.contains('note-tile')) {
            const noteId = dragged.dataset.noteId;
            const tid    = dragged.dataset.tabId || tabId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_position&id=${encodeURIComponent(noteId)}&tab_id=${encodeURIComponent(tid)}&pos_x=${x}&pos_y=${y}`
            }).catch(err => console.error('Fehler beim Speichern der Note-Position:', err));
        } else {
            const catId = dragged.dataset.categoryId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_cat_position&cat_id=${encodeURIComponent(catId)}&tab_id=${encodeURIComponent(tabId)}&pos_x=${x}&pos_y=${y}`
            }).catch(err => console.error('Fehler beim Speichern der Kategorie-Position:', err));
        }

        dragged = null;
        updateCanvasHeight(container);
    });
}

function updateCanvasHeight(container) {
    let maxBottom = 600;
    container.querySelectorAll('.category, .note-tile').forEach(item => {
        const bottom = (parseInt(item.style.top) || 0) + item.offsetHeight + 80;
        maxBottom = Math.max(maxBottom, bottom);
    });
    container.style.minHeight = maxBottom + 'px';
}

/* ================================================================
   GRID SORT MODE (Mobile / "Alle"-Tab)
   ================================================================ */

function initGridSort(container, tabSlug) {
    let draggedItem = null;

    container.addEventListener('dragstart', e => {
        draggedItem = e.target.closest('.category');
        if (draggedItem) {
            draggedItem.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            // Wichtig für Firefox
            e.dataTransfer.setData('text/plain', '');
        }
    });

    container.addEventListener('dragend', () => {
        if (draggedItem) {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
            saveCategoryOrder(container, tabSlug);
        }
    });

    container.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            target.classList.add('dragover');
        }
    });

    container.addEventListener('dragleave', e => {
        const target = e.target.closest('.category');
        if (target) target.classList.remove('dragover');
    });

    container.addEventListener('drop', e => {
        e.preventDefault();
        document.querySelectorAll('.category.dragover').forEach(c => c.classList.remove('dragover'));

        const target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            const all      = [...container.querySelectorAll('.category')];
            const dragIdx  = all.indexOf(draggedItem);
            const targetIdx = all.indexOf(target);

            if (dragIdx < targetIdx) {
                target.after(draggedItem);
            } else {
                target.before(draggedItem);
            }
            saveCategoryOrder(container, tabSlug);
        }
    });
}

function saveCategoryOrder(container, tabSlug) {
    const order = [...container.querySelectorAll('.category')].map((cat, idx) => ({
        id:       cat.dataset.categoryId,
        position: idx,
        tab:      tabSlug
    }));

    fetch('update_positions.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(order)
    }).catch(err => console.error('Fehler beim Speichern der Reihenfolge:', err));
}