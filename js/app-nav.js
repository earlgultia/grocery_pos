// Shared UI helpers for application pages.
(function initAppUI() {
    if (window.AppUI) {
        return;
    }

    function getToastRegion() {
        let region = document.querySelector('.app-toast-region');
        if (!region) {
            region = document.createElement('div');
            region.className = 'app-toast-region';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            document.body.appendChild(region);
        }
        return region;
    }

    function labelForType(type) {
        if (type === 'error') return 'Action needed';
        if (type === 'warning') return 'Please check';
        return 'Success';
    }

    function notify(message, type = 'success', options = {}) {
        if (!message) {
            return null;
        }

        const toast = document.createElement('div');
        const title = options.title || labelForType(type);
        const duration = Number.isFinite(options.duration) ? options.duration : 4200;

        toast.className = `app-toast ${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.innerHTML = `
            <div>
                <strong></strong>
                <span></span>
            </div>
            <button type="button" aria-label="Dismiss notification">&times;</button>
        `;
        toast.querySelector('strong').textContent = title;
        toast.querySelector('span').textContent = message;

        const close = () => {
            toast.classList.remove('is-visible');
            window.setTimeout(() => toast.remove(), 180);
        };

        toast.querySelector('button').addEventListener('click', close);
        getToastRegion().appendChild(toast);
        window.requestAnimationFrame(() => toast.classList.add('is-visible'));

        if (duration > 0) {
            window.setTimeout(close, duration);
        }

        return toast;
    }

    function setButtonLoading(button, text = 'Processing...') {
        if (!button) {
            return () => {};
        }

        const originalHtml = button.innerHTML;
        const originalDisabled = button.disabled;
        const originalBusy = button.getAttribute('aria-busy');

        button.dataset.loadingOriginalHtml = originalHtml;
        button.dataset.loadingOriginalDisabled = String(originalDisabled);
        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = `<span class="loading" aria-hidden="true"></span><span>${text}</span>`;

        return () => {
            button.disabled = originalDisabled;
            button.classList.remove('is-loading');
            delete button.dataset.loadingOriginalHtml;
            delete button.dataset.loadingOriginalDisabled;
            if (originalBusy === null) {
                button.removeAttribute('aria-busy');
            } else {
                button.setAttribute('aria-busy', originalBusy);
            }
            button.innerHTML = originalHtml;
        };
    }

    function confirmAction(message, options = {}) {
        return new Promise(resolve => {
            const previousActive = document.activeElement;
            const backdrop = document.createElement('div');
            const confirmText = options.confirmText || 'Confirm';
            const cancelText = options.cancelText || 'Cancel';

            backdrop.className = 'app-confirm-backdrop';
            backdrop.innerHTML = `
                <section class="app-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="appConfirmTitle">
                    <h2 id="appConfirmTitle">Confirm action</h2>
                    <p></p>
                    <div class="app-confirm-actions">
                        <button type="button" class="btn btn-outline" data-cancel></button>
                        <button type="button" class="btn btn-danger" data-confirm></button>
                    </div>
                </section>
            `;

            backdrop.querySelector('p').textContent = message || 'Are you sure you want to continue?';
            backdrop.querySelector('[data-cancel]').textContent = cancelText;
            backdrop.querySelector('[data-confirm]').textContent = confirmText;
            document.body.appendChild(backdrop);
            document.body.classList.add('app-nav-open');

            const finish = (value) => {
                document.body.classList.remove('app-nav-open');
                backdrop.remove();
                if (previousActive && typeof previousActive.focus === 'function') {
                    previousActive.focus();
                }
                resolve(value);
            };

            backdrop.querySelector('[data-cancel]').addEventListener('click', () => finish(false));
            backdrop.querySelector('[data-confirm]').addEventListener('click', () => finish(true));
            backdrop.addEventListener('click', event => {
                if (event.target === backdrop) {
                    finish(false);
                }
            });
            backdrop.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    finish(false);
                }
            });

            window.setTimeout(() => backdrop.querySelector('[data-cancel]').focus(), 0);
        });
    }

    function extractConfirmMessage(handler) {
        if (!handler) {
            return '';
        }

        const match = handler.match(/confirm\((['"])(.*?)\1\)/);
        return match ? match[2].replace(/\\'/g, "'").replace(/\\"/g, '"') : '';
    }

    function enhanceTablesAndStates() {
        document.querySelectorAll('td[colspan]').forEach(cell => {
            if (cell.textContent.trim()) {
                cell.classList.add('empty-state');
            }
        });

        document.querySelectorAll('.message-card, .form-message, .message, .auth-alert').forEach(message => {
            if (!message.hasAttribute('role')) {
                message.setAttribute('role', message.classList.contains('error') ? 'alert' : 'status');
            }
        });

        document.querySelectorAll('.pagination a.active, .pagination .active').forEach(link => {
            link.setAttribute('aria-current', 'page');
        });
    }

    function enhanceForms() {
        document.querySelectorAll('form').forEach(form => {
            if (form.dataset.appEnhanced === 'true') {
                return;
            }

            form.dataset.appEnhanced = 'true';

            const inlineConfirm = extractConfirmMessage(form.getAttribute('onsubmit'));
            if (inlineConfirm && !form.dataset.confirm) {
                form.dataset.confirm = inlineConfirm;
                form.removeAttribute('onsubmit');
            }

            form.addEventListener('submit', async event => {
                const method = (form.getAttribute('method') || 'get').toLowerCase();

                form.classList.add('was-validated');

                if (form.dataset.confirm && form.dataset.confirmed !== 'true') {
                    event.preventDefault();
                    const ok = await confirmAction(form.dataset.confirm, {
                        confirmText: form.dataset.confirmText || 'Confirm'
                    });

                    if (!ok) {
                        return;
                    }

                    form.dataset.confirmed = 'true';
                    const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
                    if (method === 'post' && submitter) {
                        setButtonLoading(submitter);
                    }
                    HTMLFormElement.prototype.submit.call(form);
                    return;
                }

                if (method === 'post' && form.dataset.noLoading !== 'true') {
                    const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
                    if (submitter) {
                        setButtonLoading(submitter);
                    }
                }
            });
        });
    }

    function enhancePage() {
        enhanceTablesAndStates();
        enhanceForms();
    }

    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-confirmed="true"]').forEach(form => {
            delete form.dataset.confirmed;
        });

        document.querySelectorAll('.is-loading[data-loading-original-html]').forEach(button => {
            button.innerHTML = button.dataset.loadingOriginalHtml;
            button.disabled = button.dataset.loadingOriginalDisabled === 'true';
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
            delete button.dataset.loadingOriginalHtml;
            delete button.dataset.loadingOriginalDisabled;
        });
    });

    window.AppUI = {
        notify,
        confirm: confirmAction,
        setButtonLoading,
        enhancePage
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhancePage);
    } else {
        enhancePage();
    }
})();

// Shared admin/store app navigation for pages that do not define their own mobile top bar.
function initAppNavigation() {
    const dashboard = document.querySelector('.dashboard-container');
    const sidebar = dashboard?.querySelector('.sidebar');

    if (!dashboard || !sidebar || document.querySelector('.app-mobile-nav')) {
        return;
    }

    if (document.body.classList.contains('store-dashboard-page') && document.querySelector('.mobile-top-bar')) {
        return;
    }

    const menu = sidebar.querySelector('.sidebar-menu');
    const title = sidebar.querySelector('.sidebar-header h3')?.textContent.trim() || 'Grocery POS';
    const subtitle = sidebar.querySelector('.sidebar-header p')?.textContent.trim() || 'Navigation';
    const activeLink = sidebar.querySelector('.sidebar-menu a.active');
    const activeText = activeLink?.textContent.replace(/\s+/g, ' ').trim() || 'Menu';
    const navId = sidebar.id || 'appSidebar';

    sidebar.id = navId;
    sidebar.setAttribute('aria-label', 'Application navigation');

    if (menu && menu.tagName.toLowerCase() !== 'nav') {
        menu.setAttribute('role', 'navigation');
        menu.setAttribute('aria-label', 'Main menu');
    }

    let backdrop = dashboard.querySelector('.sidebar-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        sidebar.insertAdjacentElement('afterend', backdrop);
    }

    const mobileNav = document.createElement('header');
    mobileNav.className = 'app-mobile-nav';
    mobileNav.innerHTML = `
        <button type="button" class="app-menu-btn" aria-label="Open navigation" aria-controls="${navId}" aria-expanded="false">
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </button>
        <div class="app-mobile-title">
            <strong></strong>
            <span></span>
        </div>
        <div class="app-mobile-context"></div>
    `;

    mobileNav.querySelector('strong').textContent = title;
    mobileNav.querySelector('.app-mobile-title span').textContent = subtitle;
    mobileNav.querySelector('.app-mobile-context').textContent = activeText;
    dashboard.insertAdjacentElement('beforebegin', mobileNav);

    const button = mobileNav.querySelector('.app-menu-btn');
    const mobileQuery = window.matchMedia('(max-width: 900px)');

    const setOpen = (isOpen) => {
        sidebar.classList.toggle('open', isOpen);
        backdrop.classList.toggle('active', isOpen);
        document.body.classList.toggle('app-nav-open', isOpen);
        button.classList.toggle('is-open', isOpen);
        button.setAttribute('aria-expanded', String(isOpen));
        button.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        document.body.style.overflow = isOpen ? 'hidden' : '';
    };

    button.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    backdrop.addEventListener('click', () => setOpen(false));

    sidebar.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', () => {
            if (mobileQuery.matches) {
                setOpen(false);
            }
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    window.addEventListener('resize', () => {
        if (!mobileQuery.matches) {
            setOpen(false);
        }
    });
}

document.addEventListener('DOMContentLoaded', initAppNavigation);
