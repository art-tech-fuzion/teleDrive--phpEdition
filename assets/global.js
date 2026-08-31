/**
 * TeleDrive Global JavaScript Components
 * Provides reusable Toast notifications and Confirmation modal dialogs.
 */

window.TeleDrive = window.TeleDrive || {};

/**
 * Toast Notification Manager
 */
(function() {
    let toastContainer = null;

    function getContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'td-toast-container';
            toastContainer.style.cssText = `
                position: fixed;
                top: 24px;
                right: 24px;
                display: flex;
                flex-direction: column;
                gap: 12px;
                z-index: 9999;
                pointer-events: none;
            `;
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    TeleDrive.toast = function(message, type = 'info', duration = 3500) {
        const container = getContainer();
        const toast = document.createElement('div');
        toast.className = `td-toast td-toast-${type}`;

        const bgMap = {
            success: 'var(--color-success-bg, rgba(16, 185, 129, 0.15))',
            error: 'var(--color-danger-bg, rgba(239, 68, 68, 0.15))',
            warning: 'var(--color-warning-bg, rgba(245, 158, 11, 0.15))',
            info: 'var(--color-primary-light, rgba(59, 130, 246, 0.15))'
        };

        const borderMap = {
            success: 'var(--color-success, #10b981)',
            error: 'var(--color-danger, #ef4444)',
            warning: 'var(--color-warning, #f59e0b)',
            info: 'var(--color-primary, #3b82f6)'
        };

        const iconMap = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        toast.style.cssText = `
            background: var(--bg-surface, #1e293b);
            border: 1px solid ${borderMap[type] || borderMap.info};
            border-left: 4px solid ${borderMap[type] || borderMap.info};
            color: var(--text-main, #f8fafc);
            padding: 12px 18px;
            border-radius: var(--radius-md, 8px);
            font-size: var(--font-size-sm, 14px);
            box-shadow: var(--shadow-lg, 0 10px 25px rgba(0,0,0,0.3));
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 260px;
            max-width: 400px;
            pointer-events: auto;
            transform: translateX(120%);
            opacity: 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        `;

        toast.innerHTML = `
            <span style="font-weight:bold; color:${borderMap[type] || borderMap.info}; font-size:16px;">${iconMap[type] || 'ℹ'}</span>
            <span style="flex:1;">${message}</span>
        `;

        container.appendChild(toast);

        // Animate In
        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
            toast.style.opacity = '1';
        });

        // Auto Dismiss
        setTimeout(() => {
            toast.style.transform = 'translateX(120%)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };
})();

/**
 * Confirmation Popup Modal Manager
 */
(function() {
    TeleDrive.confirm = function(options = {}) {
        return new Promise((resolve) => {
            const title = options.title || 'Confirm Action';
            const message = options.message || 'Are you sure you want to proceed?';
            const confirmText = options.confirmText || 'Confirm';
            const cancelText = options.cancelText || 'Cancel';
            const isDanger = options.isDanger || false;

            const modalOverlay = document.createElement('div');
            modalOverlay.className = 'td-modal-overlay';
            modalOverlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: var(--bg-overlay, rgba(11, 15, 23, 0.8));
                backdrop-filter: blur(4px);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9998;
                opacity: 0;
                transition: opacity 0.2s ease;
            `;

            const modalBox = document.createElement('div');
            modalBox.style.cssText = `
                background: var(--bg-surface, #1e293b);
                border: 1px solid var(--border-color, rgba(255,255,255,0.1));
                border-radius: var(--radius-lg, 12px);
                padding: 24px;
                width: 90%;
                max-width: 420px;
                box-shadow: var(--shadow-xl, 0 20px 45px rgba(0,0,0,0.5));
                transform: scale(0.95);
                transition: transform 0.2s ease;
            `;

            modalBox.innerHTML = `
                <h3 style="font-size: var(--font-size-lg, 18px); font-weight: var(--font-weight-semibold, 600); margin-bottom: 8px; color: var(--text-main, #f8fafc);">${title}</h3>
                <p style="font-size: var(--font-size-sm, 14px); color: var(--text-secondary, #94a3b8); margin-bottom: 24px; line-height: 1.5;">${message}</p>
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button id="td-modal-cancel" style="
                        background: transparent;
                        border: 1px solid var(--border-color, rgba(255,255,255,0.15));
                        color: var(--text-main, #f8fafc);
                        padding: 8px 16px;
                        border-radius: var(--radius-sm, 6px);
                        cursor: pointer;
                        font-weight: 500;
                    ">${cancelText}</button>
                    <button id="td-modal-confirm" style="
                        background: ${isDanger ? 'var(--color-danger, #ef4444)' : 'var(--color-primary, #3b82f6)'};
                        border: none;
                        color: #ffffff;
                        padding: 8px 18px;
                        border-radius: var(--radius-sm, 6px);
                        cursor: pointer;
                        font-weight: 600;
                    ">${confirmText}</button>
                </div>
            `;

            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            // Animate In
            requestAnimationFrame(() => {
                modalOverlay.style.opacity = '1';
                modalBox.style.transform = 'scale(1)';
            });

            const cleanup = () => {
                modalOverlay.style.opacity = '0';
                modalBox.style.transform = 'scale(0.95)';
                setTimeout(() => modalOverlay.remove(), 200);
            };

            modalBox.querySelector('#td-modal-cancel').onclick = () => {
                cleanup();
                resolve(false);
            };

            modalBox.querySelector('#td-modal-confirm').onclick = () => {
                cleanup();
                resolve(true);
            };
        });
    };
})();

/**
 * Custom Input Prompt Dialog Modal Manager
 */
(function() {
    TeleDrive.prompt = function(options = {}) {
        return new Promise((resolve) => {
            const title = options.title || 'Enter Value';
            const message = options.message || '';
            const defaultValue = options.defaultValue || '';
            const placeholder = options.placeholder || 'Type here...';
            const confirmText = options.confirmText || 'Save';
            const cancelText = options.cancelText || 'Cancel';

            const modalOverlay = document.createElement('div');
            modalOverlay.className = 'td-modal-overlay';
            modalOverlay.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: var(--bg-overlay, rgba(11, 15, 23, 0.8));
                backdrop-filter: blur(6px);
                display: flex; align-items: center; justify-content: center;
                z-index: 9998; opacity: 0;
                transition: opacity 0.2s ease;
            `;

            const modalBox = document.createElement('div');
            modalBox.style.cssText = `
                background: var(--bg-surface, #1e293b);
                border: 1px solid var(--border-color, rgba(255,255,255,0.1));
                border-radius: var(--radius-lg, 12px);
                padding: 24px; width: 90%; max-width: 440px;
                box-shadow: var(--shadow-xl, 0 20px 45px rgba(0,0,0,0.5));
                transform: scale(0.95);
                transition: transform 0.2s ease;
            `;

            modalBox.innerHTML = `
                <h3 style="font-size: var(--font-size-lg, 18px); font-weight: var(--font-weight-semibold, 600); margin-bottom: 6px; color: var(--text-main, #f8fafc);">${title}</h3>
                ${message ? `<p style="font-size: var(--font-size-sm, 14px); color: var(--text-secondary, #94a3b8); margin-bottom: 16px;">${message}</p>` : ''}
                <div style="margin-bottom: 20px; ${message ? '' : 'margin-top: 14px;'}">
                    <input type="text" id="td-prompt-input" value="${escapeHtml(defaultValue)}" placeholder="${placeholder}" style="
                        width: 100%; padding: 10px 14px;
                        background: var(--bg-body, #0b0f17);
                        border: 1px solid var(--border-color, rgba(255,255,255,0.15));
                        border-radius: var(--radius-md, 8px);
                        color: var(--text-main, #f8fafc);
                        font-size: var(--font-size-sm, 14px);
                        outline: none; transition: border-color 0.2s ease;
                    ">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button id="td-prompt-cancel" style="
                        background: transparent;
                        border: 1px solid var(--border-color, rgba(255,255,255,0.15));
                        color: var(--text-main, #f8fafc);
                        padding: 8px 16px;
                        border-radius: var(--radius-sm, 6px);
                        cursor: pointer; font-weight: 500; font-size: 13px;
                    ">${cancelText}</button>
                    <button id="td-prompt-confirm" style="
                        background: var(--color-primary, #3b82f6);
                        border: none; color: #ffffff;
                        padding: 8px 20px;
                        border-radius: var(--radius-sm, 6px);
                        cursor: pointer; font-weight: 600; font-size: 13px;
                    ">${confirmText}</button>
                </div>
            `;

            function escapeHtml(str) {
                return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            const input = modalBox.querySelector('#td-prompt-input');
            input.focus();
            input.select();

            requestAnimationFrame(() => {
                modalOverlay.style.opacity = '1';
                modalBox.style.transform = 'scale(1)';
            });

            const cleanup = () => {
                modalOverlay.style.opacity = '0';
                modalBox.style.transform = 'scale(0.95)';
                setTimeout(() => modalOverlay.remove(), 200);
            };

            modalBox.querySelector('#td-prompt-cancel').onclick = () => {
                cleanup();
                resolve(null);
            };

            const handleConfirm = () => {
                const val = input.value.trim();
                cleanup();
                resolve(val);
            };

            modalBox.querySelector('#td-prompt-confirm').onclick = handleConfirm;
            input.onkeydown = (e) => {
                if (e.key === 'Enter') handleConfirm();
                if (e.key === 'Escape') { cleanup(); resolve(null); }
            };
        });
    };
})();
