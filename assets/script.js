document.addEventListener('DOMContentLoaded', () => {
    const uiZoomStorageKey = 'favorites-ui-zoom';
    const uiZoomDefault = 100;
    const uiZoomMin = 70;
    const uiZoomMax = 150;
    const uiZoomStep = 5;

    function normalizeUiZoom(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return uiZoomDefault;
        const clamped = Math.min(uiZoomMax, Math.max(uiZoomMin, numeric));
        return Math.round(clamped / uiZoomStep) * uiZoomStep;
    }

    function applyUiZoom(value) {
        const zoom = normalizeUiZoom(value);
        document.documentElement.style.setProperty('--ui-zoom', (zoom / 100).toFixed(2));
        return zoom;
    }

    const zoomRange = document.getElementById('uiZoomRange');
    const zoomValue = document.getElementById('uiZoomValue');
    const zoomReset = document.getElementById('uiZoomReset');
    const storedZoom = normalizeUiZoom(localStorage.getItem(uiZoomStorageKey));
    const initialZoom = applyUiZoom(storedZoom);

    if (zoomRange) {
        zoomRange.value = String(initialZoom);
    }

    if (zoomValue) {
        zoomValue.textContent = `${initialZoom}%`;
    }

    if (zoomRange) {
        zoomRange.addEventListener('input', () => {
            const zoom = applyUiZoom(zoomRange.value);
            localStorage.setItem(uiZoomStorageKey, String(zoom));
            if (zoomValue) zoomValue.textContent = `${zoom}%`;
        });
    }

    if (zoomReset) {
        zoomReset.addEventListener('click', () => {
            const zoom = applyUiZoom(uiZoomDefault);
            localStorage.setItem(uiZoomStorageKey, String(zoom));
            if (zoomRange) zoomRange.value = String(zoom);
            if (zoomValue) zoomValue.textContent = `${zoom}%`;
        });
    }

    const favoriteModal = document.getElementById('favoriteModal');
    const favoriteForm = document.getElementById('favoriteForm');
    const editModal = document.getElementById('editFavoriteModal');
    const categoryModal = document.getElementById('editCategoryModal');

    if (favoriteModal) {
        const modal = new bootstrap.Modal(favoriteModal);
        const urlInput = document.getElementById('urlInput');
        const pasteButton = document.getElementById('pasteButton');
        const saveButton = document.getElementById('saveFavorite');
    
        // Sicherstellen, dass alle Elemente existieren
        if (!urlInput || !pasteButton || !saveButton) {
            console.error('Ein oder mehrere Elemente nicht gefunden:', {
                urlInput,
                pasteButton,
                saveButton
            });
            return;
        }
    
        pasteButton.addEventListener('click', () => {
            const url = urlInput.value.trim();
            if (url) {
                document.getElementById('url').value = url;
                fetchTitle(url).then(title => {
                    document.getElementById('title').value = title || '';
                    modal.show();
                }).catch(error => {
                    console.error('Fehler beim Abrufen des Titels:', error);
                    alert('Fehler beim Abrufen des Titels. Bitte überprüfen Sie die URL.');
                });
            }
        });
    
        saveButton.addEventListener('click', () => {
            const titleInput = document.getElementById('title');
            const urlInputModal = document.getElementById('url'); // Verstecktes Feld im Modal
            const categoryInput = document.getElementById('category');
            const faviconUrlInput = document.getElementById('favicon_url');
    
            const title = titleInput.value.trim();
            const url = urlInputModal.value.trim();
            const category = categoryInput.value.trim();
            const favicon_url = faviconUrlInput.value.trim();
    
            // Validierung
            const urlPattern = /^https?:\/\//i;
            let isValid = true;
            let errorMessage = '';
    
            // Fehlerstatus zurücksetzen
            titleInput.classList.remove('is-invalid');
            categoryInput.classList.remove('is-invalid');
    
            if (!title || !url || !category) {
                isValid = false;
                errorMessage = 'Bitte füllen Sie alle erforderlichen Felder aus.';
                if (!title) titleInput.classList.add('is-invalid');
                if (!category) categoryInput.classList.add('is-invalid');
                // Keine is-invalid für url, da es ein verstecktes Feld ist
            } else if (urlPattern.test(title)) {
                isValid = false;
                errorMessage = 'Der Titel darf keine HTTP-Adresse sein. Bitte geben Sie einen gültigen Titel ein.';
                titleInput.classList.add('is-invalid');
                titleInput.focus();
            }
    
            if (!isValid) {
                alert(errorMessage);
                return;
            }
    
            console.log('Validierung erfolgreich:', { title, url, category, favicon_url });
    
            fetch('save_favorite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `title=${encodeURIComponent(title)}&category=${encodeURIComponent(category)}&url=${encodeURIComponent(url)}&favicon_url=${encodeURIComponent(favicon_url)}`
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP-Fehler: ${response.status}`);
                    }
                    return response.text(); // Oder response.json(), je nach Serverantwort
                })
                .then(() => {
                    modal.hide();
                    location.reload();
                })
                .catch(error => {
                    console.error('Fehler beim Speichern:', error);
                    alert('Fehler beim Speichern des Favoriten. Bitte versuchen Sie es erneut.');
                });
        });
    }

    if (editModal) {
        const modal = new bootstrap.Modal(editModal);
        
        // Edit-Buttons für Favoriten
        document.querySelectorAll('.edit-favorite').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const favoriteDiv = btn.closest('.favorite');
                const id = favoriteDiv.dataset.id;
                const title = favoriteDiv.dataset.title;
                const url = favoriteDiv.querySelector('a').getAttribute('href');
                const favicon = favoriteDiv.querySelector('img').getAttribute('src');
                
                // Direkt die Werte aus dem DOM setzen, anstatt eine API abzufragen
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_url').value = url;
                // Aktuelle Kategorie finden
                const categoryId = favoriteDiv.closest('.category').dataset.categoryId;
                document.getElementById('edit_category').value = categoryId;
                document.getElementById('edit_favicon_url').value = favicon;
                
                modal.show();
            });
        });

        // Delete-Buttons für Favoriten
        document.querySelectorAll('.delete-favorite').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                if (confirm('Are you sure you want to delete this favorite?')) {
                    const favoriteDiv = btn.closest('.favorite');
                    const id = favoriteDiv.dataset.id;
                    
                    fetch('delete_favorite.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `id=${id}`
                    })
                    .then(response => {
                        // Keine JSON-Prüfung, einfach annehmen, dass es funktioniert hat
                        favoriteDiv.remove();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while deleting');
                    });
                }
            });
        });

        // Update Favorite Button
        const updateButton = document.getElementById('updateFavorite');
        if (updateButton) {
            updateButton.addEventListener('click', () => {
                const idInput = document.getElementById('edit_id');
                const titleInput = document.getElementById('edit_title');
                const urlInput = document.getElementById('edit_url');
                const categoryInput = document.getElementById('edit_category');
                const faviconUrlInput = document.getElementById('edit_favicon_url');
    
                // Prüfen, ob alle Eingabefelder existieren
                if (!idInput || !titleInput || !urlInput || !categoryInput || !faviconUrlInput) {
                    console.error('Ein oder mehrere Formularfelder fehlen:', {
                        idInput,
                        titleInput,
                        urlInput,
                        categoryInput,
                        faviconUrlInput
                    });
                    alert('Ein Fehler ist aufgetreten. Bitte überprüfen Sie das Formular.');
                    return;
                }
    
                const id = idInput.value.trim();
                const title = titleInput.value.trim();
                const url = urlInput.value.trim();
                const category = categoryInput.value.trim();
                const favicon_url = faviconUrlInput.value.trim();
    
                // Validierung
                const urlPattern = /^https?:\/\//i;
                let isValid = true;
                let errorMessage = '';
    
                // Fehlerstatus zurücksetzen
                titleInput.classList.remove('is-invalid');
                urlInput.classList.remove('is-invalid');
                categoryInput.classList.remove('is-invalid');
    
                if (!id || !title || !url || !category) {
                    isValid = false;
                    errorMessage = 'Bitte füllen Sie alle erforderlichen Felder aus.';
                    if (!title) titleInput.classList.add('is-invalid');
                    if (!url) urlInput.classList.add('is-invalid');
                    if (!category) categoryInput.classList.add('is-invalid');
                } else if (urlPattern.test(title)) {
                    isValid = false;
                    errorMessage = 'Der Titel darf keine HTTP-Adresse sein. Bitte geben Sie einen gültigen Titel ein.';
                    titleInput.classList.add('is-invalid');
                    titleInput.focus();
                } else if (!urlPattern.test(url)) {
                    isValid = false;
                    errorMessage = 'Bitte geben Sie eine gültige URL ein (beginnend mit http:// oder https://).';
                    urlInput.classList.add('is-invalid');
                    urlInput.focus();
                }
    
                if (!isValid) {
                    alert(errorMessage);
                    return;
                }
    
                console.log('Validierung erfolgreich:', { id, title, url, category, favicon_url });
    
                fetch('edit_favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${encodeURIComponent(id)}&title=${encodeURIComponent(title)}&category=${encodeURIComponent(category)}&url=${encodeURIComponent(url)}&favicon_url=${encodeURIComponent(favicon_url)}`
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Serverfehler: ' + response.status);
                        }
                        return response.json(); // Erwarte JSON-Antwort vom Server
                    })
                    .then(data => {
                        if (data.error) {
                            alert('Fehler: ' + data.error);
                        } else {
                            modal.hide();
                            location.reload();
                        }
                    })
                    .catch(error => {
                        console.error('Fehler:', error);
                        alert('Fehler beim Aktualisieren des Favoriten: ' + error.message);
                    });
            });
        } else {
            console.error('Update-Button mit ID "updateFavorite" nicht gefunden!');
        }
    }

    if (categoryModal) {
        const modal = new bootstrap.Modal(categoryModal);
        document.querySelectorAll('.edit-category').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('edit_category_id').value = btn.dataset.id;
                document.getElementById('edit_category_name').value = btn.dataset.name;
                modal.show();
            });
        });

        document.querySelectorAll('.delete-category').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Are you sure you want to delete this category and all its favorites?')) {
                    const id = btn.dataset.id;
                    fetch('delete_category.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `id=${id}`
                    }).then(() => location.reload());
                }
            });
        });
    }

    // Drag-and-Drop auf Kacheln
    window.allowDrop = function(event) {
        event.preventDefault();
        
        // Visuelles Feedback für Dragover
        const category = event.target.closest('.category');
        if (category) {
            category.classList.add('dragover');
        }
    };

    window.drop = function(event) {
        event.preventDefault();
        const url = event.dataTransfer.getData('text');
        const categoryId = event.target.closest('.category').dataset.categoryId;
        
        // Dragover-Highlight entfernen
        const category = event.target.closest('.category');
        if (category) {
            category.classList.remove('dragover');
        }

        if (url && categoryId) {
            document.getElementById('url').value = url;
            document.getElementById('category').value = categoryId;
            fetchTitle(url).then(title => {
                document.getElementById('title').value = title || '';
                const modal = new bootstrap.Modal(document.getElementById('favoriteModal'));
                modal.show();
            });
        }
    };

    // Titel aus URL holen
    async function fetchTitle(url) {
        try {
            const response = await fetch('get_title.php?url=' + encodeURIComponent(url));
            const data = await response.json();
            return data.title || url;
        } catch (error) {
            console.error('Fehler beim Abrufen des Titels:', error);
            return url;
        }
    }

    // Visuelles Feedback für Dragover/Dragleave
    document.querySelectorAll('.category').forEach(cat => {
        cat.addEventListener('dragover', () => cat.classList.add('dragover'));
        cat.addEventListener('dragleave', () => cat.classList.remove('dragover'));
        cat.addEventListener('drop', () => cat.classList.remove('dragover'));
    });

    // Suche
    const searchInput = document.getElementById('search');
    const searchResults = document.getElementById('searchResults');
    const categoriesContainer = document.getElementById('categories');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();

            // Suchfeld leer → alles zurücksetzen
            if (query === '') {
                if (searchResults) {
                    searchResults.classList.add('d-none');
                    searchResults.innerHTML = '';
                }
                if (categoriesContainer) {
                    categoriesContainer.classList.remove('d-none');
                }

                // DOM-Kacheln zurücksetzen (für DOM-basierte Suche)
                document.querySelectorAll('.category').forEach(cat => {
                    cat.style.display = '';
                    cat.querySelectorAll('.favorite').forEach(fav => { fav.style.display = ''; });
                });
                document.querySelectorAll('.category-row').forEach(row => { row.style.display = ''; });
                document.querySelectorAll('.user-row').forEach(row => { row.style.display = ''; });
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            // Cross-tab search: favorites + notes data from PHP
            if (window.favSearchData && searchResults && categoriesContainer) {
                const html = [];
                window.favSearchData.forEach(cat => {
                    const catMatch = cat.name.toLowerCase().includes(query);
                    const matchingFavs = cat.favorites.filter(f => f.title.toLowerCase().includes(query));

                    if (!catMatch && matchingFavs.length === 0) return;

                    const favsToShow = catMatch ? cat.favorites : matchingFavs;
                    if (favsToShow.length === 0 && !catMatch) return;

                    html.push('<div class="category search-result-card">');
                    html.push('<div class="card category-card">');
                    html.push('<div class="card-header"><h5 class="card-title">' + escapeHtml(cat.name) + '</h5></div>');
                    html.push('<div class="card-body">');
                    (catMatch ? cat.favorites : matchingFavs).forEach(fav => {
                        html.push('<div class="favorite" data-title="' + escapeHtml(fav.title) + '">');
                        html.push('<img src="' + escapeHtml(fav.favicon_url) + '" alt="Favicon" class="favicon">');
                        html.push('<a href="' + escapeHtml(fav.url) + '" target="_blank">' + escapeHtml(fav.title) + '</a>');
                        html.push('</div>');
                    });
                    html.push('</div></div></div>');
                });

                const noteHits = (window.noteSearchData || []).filter(note => {
                    const title = (note.title || '').toLowerCase();
                    const content = (note.content || '').toLowerCase();
                    return title.includes(query) || content.includes(query);
                });

                noteHits.forEach(note => {
                    const safeTitle = escapeHtml(note.title || 'Untitled note');
                    const rawContent = String(note.content || '');
                    const plainContent = rawContent
                        .replace(/```[\s\S]*?```/g, ' ')
                        .replace(/`([^`\n]+)`/g, '$1')
                        .replace(/[#*_>\-\[\]()]/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();
                    const snippet = plainContent.length > 180 ? plainContent.slice(0, 180) + '...' : plainContent;

                    html.push('<div class="note-tile search-result-card">');
                    html.push('<div class="card note-card">');
                    html.push('<div class="card-header"><h5 class="note-header-title">' + safeTitle + '</h5></div>');
                    html.push('<div class="card-body">');
                    html.push('<div class="note-view">' + (snippet ? escapeHtml(snippet) : '<span class="text-muted">No content</span>') + '</div>');
                    html.push('</div></div></div>');
                });

                if (html.length > 0) {
                    searchResults.innerHTML = html.join('');
                } else {
                    searchResults.innerHTML = '<p class="text-muted mt-3">No results for "' + escapeHtml(query) + '"</p>';
                }
                searchResults.classList.remove('d-none');
                categoriesContainer.classList.add('d-none');
                return;
            }

            // Fallback: DOM-basierte Suche (categories-Modus / admin)
            document.querySelectorAll('.category').forEach(category => {
                const title = category.querySelector('.card-title').textContent.toLowerCase();
                const favs = category.querySelectorAll('.favorite');
                if (title.includes(query)) {
                    category.style.display = '';
                    favs.forEach(fav => { fav.style.display = ''; });
                } else {
                    let hasMatch = false;
                    favs.forEach(fav => {
                        if (fav.dataset.title.toLowerCase().includes(query)) {
                            fav.style.display = '';
                            hasMatch = true;
                        } else {
                            fav.style.display = 'none';
                        }
                    });
                    category.style.display = hasMatch ? '' : 'none';
                }
            });
            document.querySelectorAll('.category-row').forEach(row => {
                row.style.display = row.dataset.name.toLowerCase().includes(query) ? '' : 'none';
            });
            document.querySelectorAll('.user-row').forEach(row => {
                row.style.display = row.dataset.username.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
});