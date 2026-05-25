// Shared admin/store app navigation
function initAppNavigation() {
    const dashboard = document.querySelector('.dashboard-container');
    const sidebar = dashboard?.querySelector('.sidebar');

    if (!dashboard || !sidebar || document.querySelector('.app-mobile-nav')) {
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
