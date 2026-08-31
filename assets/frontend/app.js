/**
 * TeleDrive Cloud File Manager Application Controller
 * Handles Navigation, Client-Side File Chunking, Previews, CRUD & Drag-Drop.
 */

document.addEventListener('DOMContentLoaded', () => {
    // State
    const state = {
        currentFolderId: 'root',
        folderPath: [{ id: 'root', name: 'My Drive' }],
        items: [],
        viewMode: 'grid', // 'grid' | 'list'
        chunkSize: 10 * 1024 * 1024 // 10MB Chunks for rapid streaming upload
    };

    // DOM Elements
    const gridView = document.getElementById('td-grid-view');
    const listView = document.getElementById('td-list-view');
    const tableBody = document.getElementById('td-table-body');
    const emptyState = document.getElementById('td-empty-state');
    const breadcrumbs = document.getElementById('td-breadcrumbs');
    const itemCounter = document.getElementById('td-item-counter');
    const searchInput = document.getElementById('td-search-input');
    const refreshBtn = document.getElementById('td-btn-refresh');
    const logoutBtn = document.getElementById('td-btn-logout');
    const newFolderBtn = document.getElementById('td-btn-new-folder');
    const uploadTrigger = document.getElementById('td-btn-upload-trigger');
    const fileInput = document.getElementById('td-file-input');
    const dropzone = document.getElementById('td-dropzone-container');
    const dragOverlay = document.getElementById('td-drag-overlay');
    const btnViewGrid = document.getElementById('td-btn-view-grid');
    const btnViewList = document.getElementById('td-btn-view-list');
    const previewModal = document.getElementById('td-preview-modal');
    const previewName = document.getElementById('td-preview-name');
    const previewBody = document.getElementById('td-preview-body');
    const previewDownload = document.getElementById('td-preview-download');
    const previewClose = document.getElementById('td-preview-close');
    const uploadQueue = document.getElementById('td-upload-queue');
    const queueItems = document.getElementById('td-queue-items');
    const queueClose = document.getElementById('td-queue-close');

    // 1. Initial Load & Routing
    loadFolder('root');

    // 2. View Mode Toggle
    btnViewGrid.onclick = () => {
        state.viewMode = 'grid';
        btnViewGrid.classList.add('active');
        btnViewList.classList.remove('active');
        gridView.style.display = 'grid';
        listView.style.display = 'none';
    };

    btnViewList.onclick = () => {
        state.viewMode = 'list';
        btnViewList.classList.add('active');
        btnViewGrid.classList.remove('active');
        gridView.style.display = 'none';
        listView.style.display = 'block';
    };

    // 3. Search & Refresh
    let searchDebounce = null;
    searchInput.oninput = (e) => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => {
            loadFolder(state.currentFolderId, e.target.value.trim());
        }, 300);
    };

    refreshBtn.onclick = () => loadFolder(state.currentFolderId);

    // 4. Logout Action
    logoutBtn.onclick = async () => {
        const confirmed = await TeleDrive.confirm({
            title: 'Sign Out',
            message: 'Are you sure you want to sign out of TeleDrive?',
            confirmText: 'Sign Out',
            isDanger: true
        });
        if (confirmed) {
            await fetch('api/index.php?action=auth.logout');
            window.location.reload();
        }
    };

    // 5. Load Folder Items with Skeleton Buffer
    async function loadFolder(folderId, search = '') {
        state.currentFolderId = folderId;
        
        // Show instant skeleton buffer to prevent empty flash
        renderSkeletonBuffer();
        renderBreadcrumbs();

        try {
            const url = `api/index.php?action=files.list&parent_id=${encodeURIComponent(folderId)}&search=${encodeURIComponent(search)}`;
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                state.items = data.items || [];
                renderItems();
            } else {
                TeleDrive.toast(data.error || 'Failed to load files.', 'error');
            }
        } catch (err) {
            console.error('Error loading files:', err);
            TeleDrive.toast(`Error loading files: ${err.message || err}`, 'error');
        }
    }

    function renderSkeletonBuffer() {
        emptyState.style.display = 'none';
        if (state.viewMode === 'grid') {
            gridView.style.display = 'grid';
            listView.style.display = 'none';
            gridView.innerHTML = Array(6).fill(0).map(() => `
                <div class="td-skeleton-card td-skeleton-shimmer">
                    <div class="td-skeleton-preview"></div>
                    <div class="td-skeleton-line"></div>
                    <div class="td-skeleton-line-sm"></div>
                </div>
            `).join('');
        }
    }

    // 6. Render Breadcrumbs
    function renderBreadcrumbs() {
        breadcrumbs.innerHTML = '';
        state.folderPath.forEach((crumb, idx) => {
            const isLast = idx === state.folderPath.length - 1;
            const span = document.createElement('span');
            span.className = `td-breadcrumb-item ${isLast ? 'active' : ''}`;
            span.textContent = crumb.name;
            span.onclick = () => {
                if (!isLast) {
                    state.folderPath = state.folderPath.slice(0, idx + 1);
                    loadFolder(crumb.id);
                }
            };
            breadcrumbs.appendChild(span);

            if (!isLast) {
                const sep = document.createElement('span');
                sep.className = 'td-breadcrumb-separator';
                sep.textContent = '/';
                breadcrumbs.appendChild(sep);
            }
        });
    }

    // 7. Render Items (Grid & List)
    function renderItems() {
        itemCounter.textContent = `${state.items.length} item${state.items.length === 1 ? '' : 's'}`;

        if (state.items.length === 0) {
            emptyState.style.display = 'flex';
            gridView.style.display = 'none';
            listView.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        if (state.viewMode === 'grid') {
            gridView.style.display = 'grid';
            listView.style.display = 'none';
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
        }

        // Render Grid
        gridView.innerHTML = '';
        state.items.forEach(item => {
            const card = document.createElement('div');
            card.className = 'td-grid-card';
            const icon = getFileIcon(item);

            card.innerHTML = `
                <div class="td-grid-card-preview">
                    <span class="td-grid-card-icon">${icon}</span>
                </div>
                <div class="td-grid-card-info">
                    <div class="td-grid-card-name" title="${item.name}">${escapeHtml(item.name)}</div>
                    <div class="td-grid-card-meta">
                        <span>${item.type === 'folder' ? 'Folder' : formatBytes(item.size)}</span>
                        <span>${formatDate(item.updated_at || item.created_at)}</span>
                    </div>
                </div>
                <div class="td-grid-card-actions">
                    <button class="td-btn-icon td-btn-rename" title="Rename">✏️</button>
                    <button class="td-btn-icon td-btn-delete" title="Delete">🗑️</button>
                </div>
            `;

            // Open Folder or Preview
            card.onclick = (e) => {
                if (e.target.closest('.td-grid-card-actions')) return;
                handleItemClick(item);
            };

            // Actions
            card.querySelector('.td-btn-rename').onclick = (e) => {
                e.stopPropagation();
                handleRename(item);
            };

            card.querySelector('.td-btn-delete').onclick = (e) => {
                e.stopPropagation();
                handleDelete(item);
            };

            gridView.appendChild(card);
        });

        // Render List Table
        tableBody.innerHTML = '';
        state.items.forEach(item => {
            const tr = document.createElement('tr');
            tr.className = 'td-table-row';
            const icon = getFileIcon(item);

            tr.innerHTML = `
                <td>
                    <div class="td-table-name-cell">
                        <span>${icon}</span>
                        <span title="${item.name}">${escapeHtml(item.name)}</span>
                    </div>
                </td>
                <td>${item.type === 'folder' ? '—' : formatBytes(item.size)}</td>
                <td>${formatDate(item.updated_at || item.created_at)}</td>
                <td>
                    <button class="td-btn-icon td-btn-rename" title="Rename">✏️</button>
                    <button class="td-btn-icon td-btn-delete" title="Delete">🗑️</button>
                </td>
            `;

            tr.onclick = (e) => {
                if (e.target.closest('button')) return;
                handleItemClick(item);
            };

            tr.querySelector('.td-btn-rename').onclick = (e) => {
                e.stopPropagation();
                handleRename(item);
            };

            tr.querySelector('.td-btn-delete').onclick = (e) => {
                e.stopPropagation();
                handleDelete(item);
            };

            tableBody.appendChild(tr);
        });
    }

    // 8. Item Interaction: Open or Preview
    function handleItemClick(item) {
        if (item.type === 'folder') {
            state.folderPath.push({ id: item.id, name: item.name });
            loadFolder(item.id);
        } else {
            openPreview(item);
        }
    }

    // 9. Preview Modal Handler
    function openPreview(item) {
        previewName.textContent = item.name;
        const downloadUrl = `api/index.php?action=files.download&id=${encodeURIComponent(item.id)}`;
        const previewUrl = `api/index.php?action=files.preview&id=${encodeURIComponent(item.id)}`;
        previewDownload.href = downloadUrl;

        previewBody.innerHTML = '';
        const mime = item.mime_type || '';

        if (mime.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = previewUrl;
            previewBody.appendChild(img);
        } else if (mime.startsWith('video/')) {
            const video = document.createElement('video');
            video.src = previewUrl;
            video.controls = true;
            video.autoplay = true;
            previewBody.appendChild(video);
        } else if (mime.startsWith('audio/')) {
            const audio = document.createElement('audio');
            audio.src = previewUrl;
            audio.controls = true;
            previewBody.appendChild(audio);
        } else if (mime === 'application/pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = previewUrl;
            previewBody.appendChild(iframe);
        } else {
            previewBody.innerHTML = `
                <div style="color:var(--text-secondary); text-align:center;">
                    <div style="font-size:48px; margin-bottom:12px;">📄</div>
                    <p>No inline preview available for this file type.</p>
                    <a href="${downloadUrl}" class="td-btn-primary td-btn-sm" style="margin-top:12px; display:inline-block;">Download File</a>
                </div>
            `;
        }

        previewModal.style.display = 'flex';
    }

    previewClose.onclick = () => {
        previewModal.style.display = 'none';
        previewBody.innerHTML = '';
    };

    // 10. Folder Creation
    newFolderBtn.onclick = async () => {
        const folderName = prompt('Enter new folder name:');
        if (!folderName || !folderName.trim()) return;

        try {
            const formData = new FormData();
            formData.append('action', 'folder.create');
            formData.append('name', folderName.trim());
            formData.append('parent_id', state.currentFolderId);

            const res = await fetch('api/index.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                TeleDrive.toast('Folder created successfully.', 'success');
                await loadFolder(state.currentFolderId);
            } else {
                TeleDrive.toast(data.error || 'Failed to create folder.', 'error');
            }
        } catch (err) {
            console.error('Error creating folder:', err);
            TeleDrive.toast(`Error creating folder: ${err.message || err}`, 'error');
        }
    };

    // 11. Rename Item
    async function handleRename(item) {
        const newName = prompt(`Rename ${item.type}:`, item.name);
        if (!newName || !newName.trim() || newName.trim() === item.name) return;

        try {
            const formData = new FormData();
            formData.append('action', 'items.rename');
            formData.append('id', item.id);
            formData.append('name', newName.trim());

            const res = await fetch('api/index.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                TeleDrive.toast('Renamed successfully.', 'success');
                loadFolder(state.currentFolderId);
            } else {
                TeleDrive.toast(data.error || 'Failed to rename item.', 'error');
            }
        } catch (err) {
            TeleDrive.toast('Error renaming item.', 'error');
        }
    }

    // 12. Delete Item (Cascade)
    async function handleDelete(item) {
        const isFolder = item.type === 'folder';
        const confirmed = await TeleDrive.confirm({
            title: `Delete ${isFolder ? 'Folder' : 'File'}`,
            message: `Are you sure you want to permanently delete "${item.name}"?${isFolder ? ' All files and subfolders inside will be wiped from Telegram storage.' : ''}`,
            confirmText: 'Delete Permanently',
            isDanger: true
        });

        if (confirmed) {
            try {
                const formData = new FormData();
                formData.append('action', 'items.delete');
                formData.append('id', item.id);

                const res = await fetch('api/index.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    TeleDrive.toast('Item deleted from Telegram storage.', 'success');
                    loadFolder(state.currentFolderId);
                } else {
                    TeleDrive.toast(data.error || 'Failed to delete item.', 'error');
                }
            } catch (err) {
                TeleDrive.toast('Error deleting item.', 'error');
            }
        }
    }

    // 13. File Upload Trigger & Input Handlers
    uploadTrigger.onclick = () => fileInput.click();
    fileInput.onchange = (e) => {
        if (e.target.files.length > 0) {
            handleFilesUpload(Array.from(e.target.files));
            fileInput.value = '';
        }
    };

    // 14. Drag and Drop Support
    let dragCounter = 0;
    dropzone.ondragenter = (e) => {
        e.preventDefault();
        dragCounter++;
        dragOverlay.classList.add('active');
    };

    dropzone.ondragleave = (e) => {
        e.preventDefault();
        dragCounter--;
        if (dragCounter === 0) {
            dragOverlay.classList.remove('active');
        }
    };

    dropzone.ondragover = (e) => e.preventDefault();

    dropzone.ondrop = (e) => {
        e.preventDefault();
        dragCounter = 0;
        dragOverlay.classList.remove('active');
        if (e.dataTransfer.files.length > 0) {
            handleFilesUpload(Array.from(e.dataTransfer.files));
        }
    };

    queueClose.onclick = () => uploadQueue.style.display = 'none';

    // 15. Client-Side Chunked File Upload Engine
    async function handleFilesUpload(files) {
        uploadQueue.style.display = 'block';

        for (const file of files) {
            await uploadSingleFile(file);
        }

        loadFolder(state.currentFolderId);
    }

    async function uploadSingleFile(file) {
        const uploadId = 'up_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const totalChunks = Math.ceil(file.size / state.chunkSize) || 1;

        // Create UI Item in queue
        const qItem = document.createElement('div');
        qItem.className = 'td-queue-item';
        qItem.innerHTML = `
            <div class="td-queue-item-name">${escapeHtml(file.name)} (${formatBytes(file.size)})</div>
            <div class="td-queue-progress-bar">
                <div class="td-queue-progress-fill"></div>
            </div>
        `;
        queueItems.appendChild(qItem);
        const fillBar = qItem.querySelector('.td-queue-progress-fill');

        try {
            for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
                const start = chunkIdx * state.chunkSize;
                const end = Math.min(start + state.chunkSize, file.size);
                const chunkBlob = file.slice(start, end);

                const formData = new FormData();
                formData.append('action', 'files.upload_chunk');
                formData.append('upload_id', uploadId);
                formData.append('chunk_index', chunkIdx);
                formData.append('total_chunks', totalChunks);
                formData.append('filename', file.name);
                formData.append('chunk', chunkBlob, file.name);

                const res = await fetch('api/index.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (!data.success) {
                    throw new Error(data.error || `Chunk ${chunkIdx + 1} upload failed`);
                }

                // Update Progress
                const progress = Math.round(((chunkIdx + 1) / totalChunks) * 90);
                fillBar.style.width = `${progress}%`;
            }

            // Complete and Send to Telegram Storage Channel
            const completeData = new FormData();
            completeData.append('action', 'files.complete_upload');
            completeData.append('upload_id', uploadId);
            completeData.append('filename', file.name);
            completeData.append('size', file.size);
            completeData.append('mime_type', file.type || 'application/octet-stream');
            completeData.append('parent_id', state.currentFolderId);
            completeData.append('total_chunks', totalChunks);

            const completeRes = await fetch('api/index.php', { method: 'POST', body: completeData });
            const completeResult = await completeRes.json();

            if (completeResult.success) {
                fillBar.style.width = '100%';
                TeleDrive.toast(`Uploaded: ${file.name}`, 'success');
                setTimeout(() => {
                    qItem.style.opacity = '0';
                    setTimeout(() => qItem.remove(), 300);
                }, 1500);
            } else {
                throw new Error(completeResult.error || 'Failed to complete Telegram storage upload');
            }
        } catch (err) {
            TeleDrive.toast(`Upload failed for ${file.name}: ${err.message}`, 'error');
            fillBar.style.backgroundColor = 'var(--color-danger)';
        }
    }

    // Utility Helpers
    function getFileIcon(item) {
        if (item.type === 'folder') return '📁';
        const mime = item.mime_type || '';
        if (mime.startsWith('image/')) return '🖼️';
        if (mime.startsWith('video/')) return '🎬';
        if (mime.startsWith('audio/')) return '🎵';
        if (mime.includes('pdf')) return '📕';
        if (mime.includes('zip') || mime.includes('rar') || mime.includes('tar')) return '📦';
        return '📄';
    }

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function formatDate(timestamp) {
        if (!timestamp) return '—';
        const date = new Date(timestamp * 1000);
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
});
