/**
 * sort.js (edit mode only) – drag behaviour:
 *   - Free Canvas (>=992px, not "Alle" tab): mouse drag; layout handled by notes.js.
 *   - Grid Sort (mobile / "Alle" tab): drag-to-swap, order saved via AJAX.
 */
document.addEventListener('DOMContentLoaded', function() {
    var container = document.querySelector('[data-sortable]');
    if (!container) return;

    var tabSlug    = container.dataset.tabSlug || 'alle';
    var tabId      = container.dataset.tabId   || '';
    var isFreeCanvas = window.innerWidth >= 992 && tabSlug !== 'alle';

    if (isFreeCanvas) {
        attachFreeDrag(container, tabId);
    } else {
        initGridSort(container, tabSlug);
    }
});

// FREE CANVAS – nur Drag-Handler (Layout macht notes.js)
// FREE CANVAS – drag handler only (layout handled by notes.js)
function attachFreeDrag(container, tabId) {
    var dragged = null;
    var offsetX = 0;
    var offsetY = 0;

    container.addEventListener('mousedown', function(e) {
        if (['TEXTAREA', 'INPUT', 'BUTTON', 'A', 'SELECT'].indexOf(e.target.tagName) !== -1) return;
        if (e.target.classList.contains('note-resize-handle')) return;

        var tile = e.target.closest('.category, .note-tile');
        if (!tile) return;

        dragged = tile;
        var rect          = tile.getBoundingClientRect();
        var containerRect = container.getBoundingClientRect();
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;

        dragged.classList.add('dragging');
        dragged.style.zIndex = '1000';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (!dragged) return;
        var cr = container.getBoundingClientRect();
        dragged.style.left = Math.max(0, e.clientX - cr.left  - offsetX) + 'px';
        dragged.style.top  = Math.max(0, e.clientY - cr.top   - offsetY) + 'px';
    });

    document.addEventListener('mouseup', function() {
        if (!dragged) return;
        dragged.classList.remove('dragging');
        dragged.style.zIndex = '';

        var x = Math.max(0, parseInt(dragged.style.left) || 0);
        var y = Math.max(0, parseInt(dragged.style.top)  || 0);

        if (dragged.classList.contains('note-tile')) {
            var noteId = dragged.dataset.noteId;
            var tid    = dragged.dataset.tabId || tabId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_position&id=' + encodeURIComponent(noteId) +
                      '&tab_id=' + encodeURIComponent(tid) +
                      '&pos_x=' + x + '&pos_y=' + y
            }).catch(function(err) { console.error('Error saving note position:', err); });
                    }).catch(function(err) { console.error('Error saving note position:', err); });
        } else {
            var catId = dragged.dataset.categoryId;
            fetch('notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_cat_position&cat_id=' + encodeURIComponent(catId) +
                      '&tab_id=' + encodeURIComponent(tabId) +
                      '&pos_x=' + x + '&pos_y=' + y
            }).catch(function(err) { console.error('Error saving category position:', err); });
                    }).catch(function(err) { console.error('Error saving category position:', err); });
        }

        dragged = null;
        if (window.updateCanvasHeight) window.updateCanvasHeight(container);
    });
}

// GRID SORT MODE – Mobile / "Alle"-Tab
// GRID SORT MODE – mobile / "Alle" tab
function initGridSort(container, tabSlug) {
    var draggedItem = null;

    container.addEventListener('dragstart', function(e) {
        draggedItem = e.target.closest('.category');
        if (draggedItem) {
            draggedItem.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', ''); // Firefox
        }
    });

    container.addEventListener('dragend', function() {
        if (draggedItem) {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
            saveCategoryOrder(container, tabSlug);
        }
    });

    container.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            target.classList.add('dragover');
        }
    });

    container.addEventListener('dragleave', function(e) {
        var target = e.target.closest('.category');
        if (target) target.classList.remove('dragover');
    });

    container.addEventListener('drop', function(e) {
        e.preventDefault();
        document.querySelectorAll('.category.dragover').forEach(function(c) { c.classList.remove('dragover'); });
        var target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            var all      = Array.from(container.querySelectorAll('.category'));
            var dragIdx  = all.indexOf(draggedItem);
            var targetIdx = all.indexOf(target);
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
    var order = Array.from(container.querySelectorAll('.category')).map(function(cat, idx) {
        return { id: cat.dataset.categoryId, position: idx, tab: tabSlug };
    });
    fetch('update_positions.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(order)
    }).catch(function(err) { console.error('Error saving order:', err); });
}
