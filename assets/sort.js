document.addEventListener('DOMContentLoaded', () => {
    const sortableContainer = document.querySelector('[data-sortable]');
    if (!sortableContainer) return;

    let draggedItem = null;

    sortableContainer.addEventListener('dragstart', (e) => {
        // Stelle sicher, dass wir nur Kategorien-Elemente ziehen können
        draggedItem = e.target.closest('.category');
        if (draggedItem) {
            draggedItem.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            
            // Wichtig für Firefox
            e.dataTransfer.setData('text/plain', ''); 
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
        
        const target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            // Visuelles Feedback für das Ziel hinzufügen
            target.classList.add('dragover');
        }
    });
    
    sortableContainer.addEventListener('dragleave', (e) => {
        const target = e.target.closest('.category');
        if (target) {
            target.classList.remove('dragover');
        }
    });

    sortableContainer.addEventListener('drop', (e) => {
        e.preventDefault();
        
        // Dragover-Highlight entfernen
        document.querySelectorAll('.category.dragover').forEach(cat => {
            cat.classList.remove('dragover');
        });
        
        const target = e.target.closest('.category');
        if (target && draggedItem && target !== draggedItem) {
            const allItems = [...sortableContainer.querySelectorAll('.category')];
            const draggedIndex = allItems.indexOf(draggedItem);
            const targetIndex = allItems.indexOf(target);

            if (draggedIndex < targetIndex) {
                // Element nach dem Ziel einfügen
                target.after(draggedItem);
            } else {
                // Element vor dem Ziel einfügen
                target.before(draggedItem);
            }
            
            // Speichere die neue Reihenfolge sofort
            saveOrder();
        }
    });

    function saveOrder() {
        const categories = [...sortableContainer.querySelectorAll('.category')];
        const order = categories.map((cat, index) => ({
            id: cat.dataset.categoryId,
            position: index
        }));

        fetch('update_positions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(order)
        }).catch(err => console.error('Fehler beim Speichern der Reihenfolge:', err));
    }
});