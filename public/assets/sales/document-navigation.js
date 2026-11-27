(() => {
    'use strict';

    const list = document.querySelector('[data-document-list]');
    const back = document.querySelector('[data-document-back]');
    if (!list && !back) return;

    const listUrl = new URL(list ? list.dataset.documentList : back.href, location.href);
    if (listUrl.origin !== location.origin) return;
    const key = `sales-document-navigation:${listUrl.pathname}`;
    const read = () => {
        try {
            const saved = JSON.parse(sessionStorage.getItem(key));
            if (!saved || typeof saved.list !== 'string' || typeof saved.detail !== 'string') return null;
            const url = new URL(saved.list, location.origin);
            const detail = new URL(saved.detail, location.origin);
            if (url.origin !== location.origin || url.pathname !== listUrl.pathname ||
                detail.origin !== location.origin || !detail.pathname.startsWith(`${listUrl.pathname}/`)) return null;
            return saved;
        } catch { return null; }
    };
    const write = saved => {
        try { sessionStorage.setItem(key, JSON.stringify(saved)); } catch { /* Navigation also works without storage. */ }
    };

    if (list) {
        list.addEventListener('click', event => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('[data-document-link]');
            if (!link || !list.contains(link) || link.target === '_blank' || link.hasAttribute('download')) return;
            const detail = new URL(link.href, location.href);
            if (detail.origin !== location.origin || !detail.pathname.startsWith(`${listUrl.pathname}/`)) return;
            write({ list: location.pathname + location.search, detail: detail.pathname, viewed: false });
        });
    }

    const restore = () => {
        const saved = read();
        if (!saved) return;
        if (back && saved.detail === location.pathname) {
            back.href = saved.list;
            write({ ...saved, viewed: true });
        }
        if (list && saved.viewed === true && saved.list === location.pathname + location.search) {
            const link = [...list.querySelectorAll('[data-document-link]')]
                .find(item => new URL(item.href, location.href).pathname === saved.detail);
            const row = link?.closest('tr');
            if (row && row.parentElement.tagName === 'TBODY') row.parentElement.prepend(row);
        }
    };
    restore();
    window.addEventListener('pageshow', restore);
})();
