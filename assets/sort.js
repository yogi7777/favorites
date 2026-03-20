document.addEventListener('DOMContentLoaded', () => {
    // Targets the category-tabs <ul> when in edit mode (data-tab-sortable attribute)
    const sortableContainer = document.querySelector('[data-tab-sortable]');
    if (!sortableContainer) return;

    let draggedItem = null;

    sortableContainer.addEventListener('dragstart', (e) => {
        draggedItem = e.target.closest('.tab-sortable-item');
        if (draggedItem) {
            draggedItem.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', ''); // Required for Firefox
        }
    });

    sortableContainer.addEventListener('dragend', () => {
        if (draggedItem) {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
            saveOrder();
        }
    });

    sortableContainer.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.tab-sortable-item');
        if (target && draggedItem && target !== draggedItem) {
            target.classList.add('dragover');
        }
    });

    sortableContainer.addEventListener('dragleave', (e) => {
        const target = e.target.closest('.tab-sortable-item');
        if (target) target.classList.remove('dragover');
    });

    sortableContainer.addEventListener('drop', (e) => {
        e.preventDefault();
        document.querySelectorAll('.tab-sortable-item.dragover').forEach(el => el.classList.remove('dragover'));

        const target = e.target.closest('.tab-sortable-item');
        if (target && draggedItem && target !== draggedItem) {
            const allItems     = [...sortableContainer.querySelectorAll('.tab-sortable-item')];
            const draggedIndex = allItems.indexOf(draggedItem);
            const targetIndex  = allItems.indexOf(target);

            if (draggedIndex < targetIndex) {
                target.after(draggedItem);
            } else {
                target.before(draggedItem);
            }
            saveOrder();
        }
    });

    function saveOrder() {
        const items = [...sortableContainer.querySelectorAll('.tab-sortable-item[data-category-id]')];
        const order = items.map((item, index) => ({
            id:       item.dataset.categoryId,
            position: index
        }));
        fetch('update_positions.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(order)
        }).catch(err => console.error('Fehler beim Speichern der Reihenfolge:', err));
    }
});
