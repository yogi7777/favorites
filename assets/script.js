document.addEventListener('DOMContentLoaded', () => {
    const favoriteModal = document.getElementById('favoriteModal');
    const editModal     = document.getElementById('editFavoriteModal');
    const tabModal = document.getElementById('editCategoryModal');

    // ── Restore last active tab ──────────────────────────────────────────
    const savedTab = localStorage.getItem('activeCategoryTab');
    if (savedTab) {
        const tabEl = document.querySelector(`[data-bs-target="${savedTab}"]`);
        if (tabEl) new bootstrap.Tab(tabEl).show();
    }
    document.querySelectorAll('#tabTabs [data-bs-toggle="tab"]').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', (e) => {
            localStorage.setItem('activeCategoryTab', e.target.dataset.bsTarget);
        });
    });

    // ── ADD FAVORITE ─────────────────────────────────────────────────────
    if (favoriteModal) {
        const modal       = new bootstrap.Modal(favoriteModal);
        const urlInput    = document.getElementById('urlInput');
        const pasteButton = document.getElementById('pasteButton');
        const saveButton  = document.getElementById('saveFavorite');

        if (!urlInput || !pasteButton || !saveButton) {
            console.error('Add-Favorite: Elemente nicht gefunden.', { urlInput, pasteButton, saveButton });
            return;
        }

        pasteButton.addEventListener('click', () => {
            const url = urlInput.value.trim();
            if (!url) return;
            document.getElementById('url').value = url;

            // Pre-select active tab tab (if not "Alle")
            const activePane       = document.querySelector('.tab-pane.show.active');
            const activeCategoryId = activePane?.dataset?.tabId || null;
            document.querySelectorAll('#add-tab-checkboxes input[type=checkbox]').forEach(cb => {
                cb.checked = activeCategoryId ? (cb.value === String(activeCategoryId)) : false;
            });

            fetchTitle(url)
                .then(title => { document.getElementById('title').value = title || ''; modal.show(); })
                .catch(() => alert('Fehler beim Abrufen des Titels. Bitte URL prüfen.'));
        });

        saveButton.addEventListener('click', () => {
            const titleInput    = document.getElementById('title');
            const urlInputModal = document.getElementById('url');
            const faviconInput  = document.getElementById('favicon_url');
            const selectedCats  = [...document.querySelectorAll('#add-tab-checkboxes input[type=checkbox]:checked')].map(cb => cb.value);
            const title         = titleInput.value.trim();
            const url           = urlInputModal.value.trim();
            const favicon_url   = faviconInput.value.trim();
            const urlPattern    = /^https?:\/\//i;

            titleInput.classList.remove('is-invalid');
            if (!title || !url || selectedCats.length === 0) {
                if (!title) titleInput.classList.add('is-invalid');
                alert('Bitte füllen Sie alle Felder aus und wählen Sie mindestens eine Kategorie.');
                return;
            }
            if (urlPattern.test(title)) {
                titleInput.classList.add('is-invalid');
                titleInput.focus();
                alert('Der Titel darf keine HTTP-Adresse sein.');
                return;
            }

            const params = new URLSearchParams({ title, url, favicon_url });
            selectedCats.forEach(c => params.append('tab_ids[]', c));

            fetch('save_favorite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(response => { if (!response.ok) throw new Error(`HTTP-Fehler: ${response.status}`); return response.text(); })
            .then(() => { modal.hide(); location.reload(); })
            .catch(error => { console.error('Fehler:', error); alert('Fehler beim Speichern des Favoriten.'); });
        });
    }

    // ── EDIT / DELETE FAVORITE ───────────────────────────────────────────
    if (editModal) {
        const modal = new bootstrap.Modal(editModal);

        document.querySelectorAll('.edit-favorite').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const fav         = btn.closest('.favorite');
                const id          = fav.dataset.id;
                const title       = fav.dataset.title;
                const url         = fav.querySelector('a').getAttribute('href');
                const favicon     = fav.querySelector('img').getAttribute('src');
                const tabIds = (fav.dataset.tabIds || '').split(',').filter(Boolean);

                document.getElementById('edit_id').value          = id;
                document.getElementById('edit_title').value       = title;
                document.getElementById('edit_url').value         = url;
                document.getElementById('edit_favicon_url').value = favicon;
                document.querySelectorAll('#edit-tab-checkboxes input[type=checkbox]').forEach(cb => {
                    cb.checked = tabIds.includes(cb.value);
                });
                modal.show();
            });
        });

        document.querySelectorAll('.delete-favorite').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!confirm('Are you sure you want to delete this favorite?')) return;
                const fav = btn.closest('.favorite');
                const id  = fav.dataset.id;
                fetch('delete_favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${id}`
                })
                .then(() => {
                    document.querySelectorAll(`.favorite[data-id="${id}"]`).forEach(el => el.remove());
                })
                .catch(error => { console.error('Error:', error); alert('An error occurred while deleting.'); });
            });
        });

        const updateButton = document.getElementById('updateFavorite');
        if (updateButton) {
            updateButton.addEventListener('click', () => {
                const idInput      = document.getElementById('edit_id');
                const titleInput   = document.getElementById('edit_title');
                const urlInput     = document.getElementById('edit_url');
                const faviconInput = document.getElementById('edit_favicon_url');
                const selectedCats = [...document.querySelectorAll('#edit-tab-checkboxes input[type=checkbox]:checked')].map(cb => cb.value);

                if (!idInput || !titleInput || !urlInput || !faviconInput) {
                    alert('Ein Fehler ist aufgetreten. Bitte das Formular prüfen.');
                    return;
                }

                const id          = idInput.value.trim();
                const title       = titleInput.value.trim();
                const url         = urlInput.value.trim();
                const favicon_url = faviconInput.value.trim();
                const urlPattern  = /^https?:\/\//i;

                titleInput.classList.remove('is-invalid');
                urlInput.classList.remove('is-invalid');

                if (!id || !title || !url || selectedCats.length === 0) {
                    if (!title) titleInput.classList.add('is-invalid');
                    if (!url)   urlInput.classList.add('is-invalid');
                    alert('Bitte füllen Sie alle Felder aus und wählen Sie mindestens eine Kategorie.');
                    return;
                }
                if (urlPattern.test(title)) {
                    titleInput.classList.add('is-invalid');
                    titleInput.focus();
                    alert('Der Titel darf keine HTTP-Adresse sein.');
                    return;
                }
                if (!urlPattern.test(url)) {
                    urlInput.classList.add('is-invalid');
                    urlInput.focus();
                    alert('Bitte eine gültige URL eingeben (beginnend mit http:// oder https://).');
                    return;
                }

                const params = new URLSearchParams({ id, title, url, favicon_url });
                selectedCats.forEach(c => params.append('tab_ids[]', c));

                fetch('edit_favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(response => { if (!response.ok) throw new Error('Serverfehler: ' + response.status); return response.json(); })
                .then(data => { if (data.error) { alert('Fehler: ' + data.error); } else { modal.hide(); location.reload(); } })
                .catch(error => { console.error('Fehler:', error); alert('Fehler beim Aktualisieren: ' + error.message); });
            });
        }
    }

    // ── CATEGORY EDIT MODAL ──────────────────────────────────────────────
    if (tabModal) {
        const modal = new bootstrap.Modal(tabModal);
        document.querySelectorAll('.edit-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('edit_tab_id').value   = btn.dataset.id;
                document.getElementById('edit_tab_name').value = btn.dataset.name;
                modal.show();
            });
        });
    }

    // ── DRAG & DROP (external URL onto a tab tile) ──────────────────
    window.allowDrop = function(event) {
        event.preventDefault();
        const target = event.target.closest('.tab') || event.target.closest('.single-tab-panel');
        if (target) target.classList.add('dragover');
    };

    window.drop = function(event) {
        event.preventDefault();
        const url   = event.dataTransfer.getData('text');
        const catEl = event.target.closest('.tab') || event.target.closest('.single-tab-panel');
        const catId = catEl?.dataset?.tabId || null;
        if (catEl) catEl.classList.remove('dragover');
        if (url) {
            document.getElementById('url').value = url;
            document.querySelectorAll('#add-tab-checkboxes input[type=checkbox]').forEach(cb => {
                cb.checked = catId ? (cb.value === String(catId)) : false;
            });
            fetchTitle(url).then(title => {
                document.getElementById('title').value = title || '';
                new bootstrap.Modal(document.getElementById('favoriteModal')).show();
            });
        }
    };

    // ── HELPER: fetch page title ─────────────────────────────────────────
    async function fetchTitle(url) {
        try {
            const response = await fetch('get_title.php?url=' + encodeURIComponent(url));
            const data = await response.json();
            return data.title || url;
        } catch {
            return url;
        }
    }

    // ── SEARCH ───────────────────────────────────────────────────────────
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase();

            if (query !== '') {
                const allTabBtn = document.getElementById('tab-all-btn');
                if (allTabBtn && !allTabBtn.classList.contains('active')) {
                    new bootstrap.Tab(allTabBtn).show();
                }
            }

            if (query === '') {
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.style.display = '';
                    tab.querySelectorAll('.favorite').forEach(fav => { fav.style.display = ''; });
                });
                document.querySelectorAll('.tab-row').forEach(row => { row.style.display = ''; });
                document.querySelectorAll('.user-row').forEach(row => { row.style.display = ''; });
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            document.querySelectorAll('.tab').forEach(tab => {
                const titleEl  = tab.querySelector('.card-title');
                const catTitle = titleEl ? titleEl.textContent.toLowerCase() : '';
                const favs     = tab.querySelectorAll('.favorite');
                if (catTitle.includes(query)) {
                    tab.style.display = '';
                    favs.forEach(fav => { fav.style.display = ''; });
                } else {
                    let hasVisible = false;
                    favs.forEach(fav => {
                        const match = fav.dataset.title.toLowerCase().includes(query);
                        fav.style.display = match ? '' : 'none';
                        if (match) hasVisible = true;
                    });
                    tab.style.display = hasVisible ? '' : 'none';
                }
            });

            document.querySelectorAll('.tab-row').forEach(row => {
                row.style.display = row.dataset.name.toLowerCase().includes(query) ? '' : 'none';
            });
            document.querySelectorAll('.user-row').forEach(row => {
                row.style.display = row.dataset.username?.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    // ── TOOLTIPS ─────────────────────────────────────────────────────────
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});
