(() => {
    'use strict';

    try {
    const root = document.querySelector('.module.rm-shell') || document.querySelector('.rm-shell');
    if (!root || typeof root.nodeType !== 'number') {
        return;
    }

    ensureModuleScroll();
    window.addEventListener('resize', ensureModuleScroll);

    enhanceTranslations();
    enhanceNavigation();
    enhanceTabs();
    enhanceModals();
    enhanceTables();
    enhanceForms();
    enhanceSearchInputs();
    enhanceMatrixSearches();

    function ensureModuleScroll() {
        const module = root.classList && root.classList.contains('module')
            ? root
            : (root.closest ? root.closest('.module') : document.querySelector('.module'));
        const moduleBody = root.classList && root.classList.contains('module-body')
            ? root
            : (module ? module.querySelector('.module-body') : (root.closest ? root.closest('.module-body') : null));
        const innerShell = moduleBody ? moduleBody.querySelector('.rm-shell') : (module === root ? null : root);
        const docHeader = module ? module.querySelector('.module-docheader') : null;
        const docHeaderHeight = docHeader ? Math.ceil(docHeader.getBoundingClientRect().height) : 0;

        if (module) {
            module.classList.add('rm-scroll-frame');
            module.style.setProperty('height', '100%');
            module.style.setProperty('min-height', '0');
            module.style.setProperty('overflow', 'hidden');
            module.style.setProperty('scroll-padding-bottom', '48px');
        }
        if (moduleBody) {
            moduleBody.style.setProperty('box-sizing', 'border-box');
            moduleBody.style.setProperty('height', docHeaderHeight > 0 ? 'calc(100% - ' + docHeaderHeight + 'px)' : '100%');
            moduleBody.style.setProperty('min-height', 'auto');
            moduleBody.style.setProperty('overflow', 'hidden');
            moduleBody.style.setProperty('padding-bottom', '24px');
        }
        if (innerShell && innerShell !== module) {
            innerShell.style.setProperty('box-sizing', 'border-box');
            innerShell.style.setProperty('height', '100%');
            innerShell.style.setProperty('max-height', '100%');
            innerShell.style.setProperty('overflow-x', 'hidden');
            innerShell.style.setProperty('overflow-y', 'auto');
            innerShell.style.setProperty('padding-bottom', '24px');
            innerShell.style.setProperty('scroll-padding-bottom', '48px');
        }
    }

    // KNOWN LIMITATION (P3, display-only, contrived trigger): the i18n token system
    // translates __rmLabel:KEY__ placeholders in server-rendered TEXT here at load, and the
    // JS render paths translate __rmLabel: / {labels. / {uiLabels. in JS-built markup. A DB
    // record whose title literally contains that token syntax (e.g. a group named
    // "__rmLabel:save__") is therefore rewritten on display. The JS-render paths ARE guarded
    // upstream by RightsManagementSave.safeLabel() applied at data-attribute ingestion; but
    // server-rendered TEXT (group/user/page/module lists, the rights-matrix headers) is NOT
    // guarded -- a proper fix would neutralize DB titles at the source (server-side) or
    // exclude DB-content nodes from this load-time text walk. Saves are unaffected (UIDs only).
    function enhanceTranslations() {
        const labels = {};
        const labelNodes = root.querySelectorAll('[data-i18n-key]');
        for (let i = 0; i < labelNodes.length; i++) {
            const key = labelNodes[i].getAttribute('data-i18n-key');
            const value = (labelNodes[i].textContent || '').trim();
            if (key && value) {
                labels[key] = value;
            }
        }
        if (!Object.keys(labels).length) {
            return;
        }
        replaceLabelTokensInText(root, labels);
        replaceLabelTokensInAttributes(root, labels);
    }

    function replaceLabelTokens(value, labels) {
        return String(value || '').replace(/__rmLabel:([A-Za-z0-9_.-]+)__/g, function (match, key) {
            return labels[key] || match;
        });
    }

    function replaceLabelTokensInText(scope, labels) {
        const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const parent = node.parentElement;
            if (parent && (parent.closest('script') || parent.closest('style'))) {
                continue;
            }
            if (node.nodeValue.indexOf('__rmLabel:') !== -1) {
                textNodes.push(node);
            }
        }
        for (let i = 0; i < textNodes.length; i++) {
            textNodes[i].nodeValue = replaceLabelTokens(textNodes[i].nodeValue, labels);
        }
    }

    function replaceLabelTokensInAttributes(scope, labels) {
        const nodes = scope.querySelectorAll('[placeholder], [title], [aria-label], [value]');
        const attributes = ['placeholder', 'title', 'aria-label', 'value'];
        for (let i = 0; i < nodes.length; i++) {
            for (let j = 0; j < attributes.length; j++) {
                const attribute = attributes[j];
                const value = nodes[i].getAttribute(attribute);
                if (value && value.indexOf('__rmLabel:') !== -1) {
                    nodes[i].setAttribute(attribute, replaceLabelTokens(value, labels));
                }
            }
        }
    }

    function enhanceNavigation() {
        const nav = root.querySelector('.rm-toolbar');
        if (!nav) {
            return;
        }
        nav.setAttribute('role', 'navigation');
        if (!nav.getAttribute('aria-label')) {
            nav.setAttribute('aria-label', 'Rights management navigation');
        }
        const links = nav.querySelectorAll('a');
        for (let i = 0; i < links.length; i++) {
            if (links[i].classList.contains('btn-primary')) {
                links[i].setAttribute('aria-current', 'page');
            }
        }
    }

    function enhanceTabs() {
        const tabLists = root.querySelectorAll('.rm-tabs');
        for (let i = 0; i < tabLists.length; i++) {
            const tabList = tabLists[i];
            tabList.setAttribute('role', 'tablist');
            const tabs = tabList.querySelectorAll('button[data-tab], button[data-role]');
            for (let index = 0; index < tabs.length; index++) {
                const tab = tabs[index];
                const panel = findPanel(tab);
                tab.setAttribute('role', 'tab');
                tab.setAttribute('tabindex', tab.classList.contains('is-active') ? '0' : '-1');
                tab.setAttribute('aria-selected', tab.classList.contains('is-active') ? 'true' : 'false');
                if (!tab.id) {
                    tab.id = 'rm-tab-' + i + '-' + index;
                }
                if (panel) {
                    if (!panel.id) {
                        panel.id = tab.id + '-panel';
                    }
                    tab.setAttribute('aria-controls', panel.id);
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('aria-labelledby', tab.id);
                }
            }
            tabList.addEventListener('keydown', onTabKeydown);
            tabList.addEventListener('click', () => {
                window.setTimeout(() => syncTabs(tabList), 0);
            });
        }
    }

    function findPanel(tab) {
        const tabName = tab.dataset.tab;
        if (!tabName) {
            return null;
        }
        const panelSelectors = [
            '[data-role="' + tabName + '-panel"]',
            '[data-role="' + tabName + 's-panel"]',
            '[data-role="' + tabName + 'Panel"]',
        ];
        for (let i = 0; i < panelSelectors.length; i++) {
            const panel = root.querySelector(panelSelectors[i]);
            if (panel) {
                return panel;
            }
        }
        return null;
    }

    function onTabKeydown(event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End') {
            return;
        }
        const tabs = Array.prototype.slice.call(event.currentTarget.querySelectorAll('[role="tab"]:not([hidden])'));
        if (!tabs.length) {
            return;
        }
        const currentIndex = tabs.indexOf(document.activeElement);
        let nextIndex = currentIndex < 0 ? 0 : currentIndex;
        if (event.key === 'ArrowLeft') {
            nextIndex = currentIndex <= 0 ? tabs.length - 1 : currentIndex - 1;
        } else if (event.key === 'ArrowRight') {
            nextIndex = currentIndex >= tabs.length - 1 ? 0 : currentIndex + 1;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        }
        event.preventDefault();
        tabs[nextIndex].focus();
        tabs[nextIndex].click();
        syncTabs(event.currentTarget);
    }

    function syncTabs(tabList) {
        const tabs = tabList.querySelectorAll('[role="tab"]');
        for (let i = 0; i < tabs.length; i++) {
            const selected = tabs[i].classList.contains('is-active');
            tabs[i].setAttribute('aria-selected', selected ? 'true' : 'false');
            tabs[i].setAttribute('tabindex', selected ? '0' : '-1');
        }
    }

    function enhanceModals() {
        const modals = root.querySelectorAll('.rm-modal');
        for (let i = 0; i < modals.length; i++) {
            const modal = modals[i];
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
        }
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            const openModal = root.querySelector('.rm-modal.is-open');
            if (!openModal) {
                return;
            }
            openModal.classList.remove('is-open');
            openModal.setAttribute('aria-hidden', 'true');
        });
    }

    function enhanceTables() {
        const tables = root.querySelectorAll('table');
        for (let i = 0; i < tables.length; i++) {
            const table = tables[i];
            if (!table.getAttribute('role')) {
                table.setAttribute('role', 'table');
            }
            const headers = table.querySelectorAll('th');
            for (let j = 0; j < headers.length; j++) {
                if (!headers[j].getAttribute('scope')) {
                    headers[j].setAttribute('scope', headers[j].parentNode && headers[j].parentNode.parentNode && headers[j].parentNode.parentNode.tagName === 'THEAD' ? 'col' : 'row');
                }
            }
        }
        enhanceTableScrollbars();
        updateStickyTableHeaders();
        window.addEventListener('resize', function () {
            enhanceTableScrollbars();
            updateStickyTableHeaders();
        });
        window.addEventListener('scroll', updateStickyTableHeaders, true);
        document.addEventListener('scroll', updateStickyTableHeaders, true);
        root.addEventListener('scroll', updateStickyTableHeaders, true);
        if (window.MutationObserver && typeof root.nodeType === 'number') {
            const observer = new MutationObserver(function () {
                window.setTimeout(function () {
                    enhanceTableScrollbars();
                    updateStickyTableHeaders();
                }, 0);
            });
            observer.observe(root, {childList: true, subtree: true});
        }
        root.addEventListener('click', function (event) {
            if (!event.target.closest) {
                return;
            }
            if (event.target.closest('.rm-tab, .rm-toolbar .btn, [data-tab], [data-role]')) {
                window.setTimeout(function () {
                    enhanceTableScrollbars();
                    updateStickyTableHeaders();
                }, 0);
            }
        });

        let hoveredCells = [];

        root.addEventListener('mouseover', function (event) {
            const cell = event.target.closest ? event.target.closest('td, th') : null;
            clearColumnHover();
            if (!cell) {
                return;
            }
            const table = cell.closest('table');
            if (!table || (!table.classList.contains('rm-matrix') && !table.classList.contains('rm-overview') && !table.classList.contains('rm-rights-table'))) {
                return;
            }
            const range = getCellRange(table, cell);
            if (!range) {
                return;
            }
            const ranges = getTableCellRanges(table);
            ranges.forEach(function (candidateRange, candidate) {
                if (candidateRange.start <= range.end && candidateRange.end >= range.start) {
                    candidate.classList.add('is-col-hover');
                    hoveredCells.push(candidate);
                }
            });
        });

        root.addEventListener('click', function (event) {
            if (!event.target.closest) {
                return;
            }
            if (event.target.closest('input, label, button, a, select, textarea')) {
                return;
            }
            const cell = event.target.closest('td, th');
            if (!cell || !root.contains(cell)) {
                return;
            }
            const checkboxes = cell.querySelectorAll('input[type="checkbox"]:not(:disabled)');
            if (checkboxes.length !== 1) {
                return;
            }
            checkboxes[0].checked = !checkboxes[0].checked;
            checkboxes[0].dispatchEvent(new Event('change', {bubbles: true}));
        });

        function clearColumnHover() {
            for (let i = 0; i < hoveredCells.length; i++) {
                hoveredCells[i].classList.remove('is-col-hover');
            }
            hoveredCells = [];
        }

        function getCellRange(table, cell) {
            const ranges = getTableCellRanges(table);
            return ranges.get(cell) || null;
        }

        function getTableCellRanges(table) {
            const ranges = new Map();
            const occupied = [];
            for (let rowIndex = 0; rowIndex < table.rows.length; rowIndex++) {
                const row = table.rows[rowIndex];
                if (!occupied[rowIndex]) {
                    occupied[rowIndex] = [];
                }
                let columnIndex = 0;
                for (let cellIndex = 0; cellIndex < row.cells.length; cellIndex++) {
                    const cell = row.cells[cellIndex];
                    while (occupied[rowIndex][columnIndex]) {
                        columnIndex++;
                    }
                    const colSpan = Math.max(1, cell.colSpan || 1);
                    const rowSpan = Math.max(1, cell.rowSpan || 1);
                    ranges.set(cell, {
                        start: columnIndex,
                        end: columnIndex + colSpan - 1,
                    });
                    for (let y = rowIndex; y < rowIndex + rowSpan; y++) {
                        if (!occupied[y]) {
                            occupied[y] = [];
                        }
                        for (let x = columnIndex; x < columnIndex + colSpan; x++) {
                            occupied[y][x] = true;
                        }
                    }
                    columnIndex += colSpan;
                }
            }
            return ranges;
        }

        function enhanceTableScrollbars() {
            const wraps = root.querySelectorAll('.rm-table-wrap');
            for (let i = 0; i < wraps.length; i++) {
                const wrap = wraps[i];
                if (!wrap.parentNode) {
                    continue;
                }
                let proxy = wrap.rmScrollProxy || findManagedSibling(wrap, 'rm-scroll-x');
                if (!proxy) {
                    proxy = document.createElement('div');
                    proxy.className = 'rm-scroll-x';
                    proxy.setAttribute('aria-hidden', 'true');
                    const inner = document.createElement('div');
                    inner.className = 'rm-scroll-x__inner';
                    proxy.appendChild(inner);
                    wrap.parentNode.insertBefore(proxy, wrap);

                    let syncing = false;
                    proxy.addEventListener('scroll', function () {
                        if (syncing) {
                            return;
                        }
                        syncing = true;
                        wrap.scrollLeft = proxy.scrollLeft;
                        syncStickyHeaderScroll(wrap);
                        syncing = false;
                    });
                    wrap.addEventListener('scroll', function () {
                        if (syncing) {
                            return;
                        }
                        syncing = true;
                        proxy.scrollLeft = wrap.scrollLeft;
                        syncStickyHeaderScroll(wrap);
                        syncing = false;
                    });
                }
                wrap.rmScrollProxy = proxy;
                updateStickyTools(wrap, proxy);
                updateScrollbarProxy(wrap, proxy);
                enhanceStickyHeader(wrap);
            }
        }

        function updateScrollbarProxy(wrap, proxy) {
            const inner = proxy.firstElementChild;
            if (!inner) {
                return;
            }
            const table = wrap.querySelector('table');
            const scrollWidth = Math.max(wrap.scrollWidth, table ? table.scrollWidth : 0);
            const isScrollable = scrollWidth > wrap.clientWidth + 1;
            inner.style.width = String(scrollWidth) + 'px';
            proxy.scrollLeft = wrap.scrollLeft;
            proxy.hidden = !isScrollable;
            if (isScrollable) {
                proxy.classList.add('is-active');
            } else {
                proxy.classList.remove('is-active');
            }
        }

        function enhanceStickyHeader(wrap) {
            const table = wrap.querySelector('table.rm-overview, table.rm-matrix, table.rm-rights-table');
            if (!table || !table.tHead) {
                return;
            }
            let head = wrap.rmStickyHead || findManagedSibling(wrap, 'rm-sticky-head');
            if (!head) {
                head = document.createElement('div');
                head.className = 'rm-sticky-head';
                head.setAttribute('aria-hidden', 'true');
                wrap.parentNode.insertBefore(head, wrap);
            }
            wrap.rmStickyHead = head;
            wrap.classList.add('has-floating-head');

            const signature = String(table.tHead.innerHTML.length) + ':' + String(table.rows.length) + ':' + String(Math.round(table.scrollWidth));
            if (head.getAttribute('data-signature') !== signature) {
                buildStickyHeader(wrap, head, table, signature);
            }
            syncStickyHeaderScroll(wrap);
            updateStickyHeaderState(wrap, head);
        }

        function removeStickyHeader(wrap) {
            const head = wrap.rmStickyHead || findManagedSibling(wrap, 'rm-sticky-head');
            if (head && head.parentNode) {
                head.parentNode.removeChild(head);
            }
            wrap.rmStickyHead = null;
            wrap.classList.remove('has-floating-head');
        }

        function buildStickyHeader(wrap, head, table, signature) {
            head.innerHTML = '';
            const scroller = document.createElement('div');
            scroller.className = 'rm-sticky-head__scroller';
            const clone = document.createElement('table');
            clone.className = table.className + ' rm-sticky-head__table';
            clone.removeAttribute('id');
            clone.style.width = String(table.scrollWidth) + 'px';

            const widths = getColumnWidths(table);
            if (widths.length) {
                const colgroup = document.createElement('colgroup');
                for (let i = 0; i < widths.length; i++) {
                    const col = document.createElement('col');
                    col.style.width = String(Math.max(32, Math.round(widths[i]))) + 'px';
                    colgroup.appendChild(col);
                }
                clone.appendChild(colgroup);
            }
            clone.appendChild(table.tHead.cloneNode(true));

            const duplicateIds = clone.querySelectorAll('[id]');
            for (let i = 0; i < duplicateIds.length; i++) {
                duplicateIds[i].removeAttribute('id');
            }
            const focusables = clone.querySelectorAll('a, button, input, select, textarea, [tabindex]');
            for (let i = 0; i < focusables.length; i++) {
                focusables[i].setAttribute('tabindex', '-1');
            }
            bindStickyHeaderControls(head, clone, table);

            scroller.appendChild(clone);
            head.appendChild(scroller);
            head.setAttribute('data-signature', signature);
            syncStickyHeaderControls(head, table);
            syncStickyHeaderScroll(wrap);
        }

        function bindStickyHeaderControls(head, clone, table) {
            const sourceControls = Array.prototype.slice.call(table.tHead.querySelectorAll('input, select, textarea'));
            const stickyControls = Array.prototype.slice.call(clone.querySelectorAll('thead input, thead select, thead textarea'));
            for (let i = 0; i < stickyControls.length; i++) {
                const stickyControl = stickyControls[i];
                stickyControl.removeAttribute('name');
                stickyControl.dataset.rmStickyControlIndex = String(i);
                const sourceControl = sourceControls[i];
                if (!sourceControl) {
                    stickyControl.disabled = true;
                    continue;
                }
                stickyControl.addEventListener(stickyControlEventName(stickyControl), function () {
                    const currentSourceControls = Array.prototype.slice.call(table.tHead.querySelectorAll('input, select, textarea'));
                    const currentSourceControl = currentSourceControls[Number(stickyControl.dataset.rmStickyControlIndex || -1)];
                    if (!currentSourceControl || currentSourceControl.disabled) {
                        syncStickyHeaderControls(head, table);
                        return;
                    }
                    copyControlState(stickyControl, currentSourceControl);
                    currentSourceControl.dispatchEvent(new Event(stickyControlEventName(currentSourceControl), {bubbles: true}));
                    syncStickyHeaderControls(head, table);
                });
            }
        }

        function syncStickyHeaderControls(head, table) {
            const clone = head.querySelector('.rm-sticky-head__table');
            if (!clone || !table.tHead) {
                return;
            }
            const sourceControls = Array.prototype.slice.call(table.tHead.querySelectorAll('input, select, textarea'));
            const stickyControls = Array.prototype.slice.call(clone.querySelectorAll('thead input, thead select, thead textarea'));
            for (let i = 0; i < stickyControls.length; i++) {
                const sourceControl = sourceControls[i];
                const stickyControl = stickyControls[i];
                if (!sourceControl) {
                    stickyControl.disabled = true;
                    continue;
                }
                copyControlState(sourceControl, stickyControl);
                stickyControl.disabled = sourceControl.disabled;
            }
        }

        function copyControlState(sourceControl, targetControl) {
            if (sourceControl.type === 'checkbox' || sourceControl.type === 'radio') {
                targetControl.checked = sourceControl.checked;
            } else {
                targetControl.value = sourceControl.value;
            }
        }

        function stickyControlEventName(control) {
            const tagName = String(control.tagName || '').toLowerCase();
            const type = String(control.type || '').toLowerCase();
            return tagName === 'select' || type === 'checkbox' || type === 'radio' ? 'change' : 'input';
        }

        function getColumnWidths(table) {
            const ranges = getTableCellRanges(table);
            const widths = [];
            let columnCount = 0;
            ranges.forEach(function (range) {
                columnCount = Math.max(columnCount, range.end + 1);
            });
            ranges.forEach(function (range, cell) {
                if (range.start !== range.end) {
                    return;
                }
                const rect = cell.getBoundingClientRect();
                if (!rect.width) {
                    return;
                }
                widths[range.start] = Math.max(widths[range.start] || 0, rect.width);
            });
            const fallbackWidth = table.scrollWidth && columnCount ? table.scrollWidth / columnCount : 120;
            for (let i = 0; i < columnCount; i++) {
                if (!widths[i]) {
                    widths[i] = fallbackWidth;
                }
            }
            return widths;
        }

        function syncStickyHeaderScroll(wrap) {
            const head = wrap.rmStickyHead || findManagedSibling(wrap, 'rm-sticky-head');
            if (!head) {
                return;
            }
            const scroller = head.querySelector('.rm-sticky-head__scroller');
            if (scroller) {
                scroller.scrollLeft = wrap.scrollLeft;
            }
        }

        function updateStickyTableHeaders() {
            const wraps = root.querySelectorAll('.rm-table-wrap');
            for (let i = 0; i < wraps.length; i++) {
                const head = wraps[i].rmStickyHead || findManagedSibling(wraps[i], 'rm-sticky-head');
                if (head) {
                    updateStickyHeaderState(wraps[i], head);
                }
            }
        }

        function updateStickyHeaderState(wrap, head) {
            const table = wrap.querySelector('table.rm-overview, table.rm-matrix, table.rm-rights-table');
            if (!table || !table.tHead) {
                head.classList.remove('is-visible');
                return;
            }
            const proxy = wrap.rmScrollProxy || findManagedSibling(wrap, 'rm-scroll-x');
            updateStickyTools(wrap, proxy);
            const proxyHeight = proxy && !proxy.hidden ? proxy.getBoundingClientRect().height : 0;
            const stickyTop = getStickyTopOffset();
            const toolsHeight = getStickyToolsHeight(wrap);
            const rect = wrap.getBoundingClientRect();
            const headerHeight = table.tHead.getBoundingClientRect().height || head.getBoundingClientRect().height || 1;
            const stickyEdge = stickyTop + toolsHeight + proxyHeight;
            const isVisible = rect.top < stickyEdge && rect.bottom > stickyEdge + headerHeight + 24;
            head.style.top = String(stickyEdge) + 'px';
            if (isVisible) {
                head.classList.add('is-visible');
            } else {
                head.classList.remove('is-visible');
            }
            syncStickyHeaderControls(head, table);
            syncStickyHeaderScroll(wrap);
        }

        function getStickyTopOffset() {
            const value = window.getComputedStyle(root).getPropertyValue('--rm-sticky-top');
            const parsed = Number.parseFloat(value);
            return Number.isFinite(parsed) ? parsed : 0;
        }

        function updateStickyTools(wrap, proxy) {
            const tools = findStickyTools(wrap);
            const parent = wrap.parentElement;
            let toolsHeight = 0;
            if (tools && !tools.hidden) {
                tools.classList.add('is-sticky-table-tools');
                toolsHeight = tools.getBoundingClientRect().height;
                const proxyRect = proxy ? proxy.getBoundingClientRect() : null;
                const toolsRect = tools.getBoundingClientRect();
                const renderedGap = proxyRect ? Math.max(0, proxyRect.top - toolsRect.bottom) : 0;
                if (renderedGap > 20) {
                    toolsHeight = Math.max(0, toolsHeight - renderedGap);
                }
            }
            if (parent) {
                parent.style.setProperty('--rm-sticky-tools-height', String(toolsHeight) + 'px');
            }
        }

        function getStickyToolsHeight(wrap) {
            const tools = findStickyTools(wrap);
            if (!tools || tools.hidden) {
                return 0;
            }
            const parent = wrap.parentElement;
            if (parent) {
                const value = window.getComputedStyle(parent).getPropertyValue('--rm-sticky-tools-height');
                const parsed = Number.parseFloat(value);
                if (Number.isFinite(parsed)) {
                    return parsed;
                }
            }
            return tools.getBoundingClientRect().height || 0;
        }

        function findStickyTools(wrap) {
            let sibling = wrap.previousElementSibling;
            while (sibling && sibling.classList && (sibling.classList.contains('rm-scroll-x') || sibling.classList.contains('rm-sticky-head'))) {
                sibling = sibling.previousElementSibling;
            }
            return sibling && sibling.classList && sibling.classList.contains('rm-selector-tools') ? sibling : null;
        }

        function findManagedSibling(wrap, className) {
            let sibling = wrap.previousElementSibling;
            while (sibling && sibling.classList && (sibling.classList.contains('rm-scroll-x') || sibling.classList.contains('rm-sticky-head'))) {
                if (sibling.classList.contains(className)) {
                    return sibling;
                }
                sibling = sibling.previousElementSibling;
            }
            return null;
        }
    }

    function enhanceForms() {
        const fields = root.querySelectorAll('input[type="search"], input[type="text"], textarea, select');
        for (let i = 0; i < fields.length; i++) {
            const field = fields[i];
            if (field.getAttribute('aria-label') || field.id) {
                continue;
            }
            const placeholder = field.getAttribute('placeholder');
            if (placeholder) {
                field.setAttribute('aria-label', placeholder);
            }
        }
        bindSelectionForms();
    }

    function enhanceSearchInputs() {
        const inputs = root.querySelectorAll('input[type="search"]');
        for (let i = 0; i < inputs.length; i++) {
            const input = inputs[i];
            if (input.dataset.rmSearchInputBound === '1') {
                continue;
            }
            input.dataset.rmSearchInputBound = '1';
            if (!input.getAttribute('autocomplete')) {
                input.setAttribute('autocomplete', 'off');
            }
            input.addEventListener('keydown', preventSearchEnter, true);
            input.addEventListener('search', dispatchInputEvent);
            input.addEventListener('change', dispatchInputEvent);
        }

        root.addEventListener('submit', function (event) {
            const form = event.target;
            const active = document.activeElement;
            if (!form || !active || String(form.tagName || '').toLowerCase() !== 'form') {
                return;
            }
            if (!form.contains(active) || !isSearchInput(active)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
        }, true);

        window.setTimeout(function () {
            for (let i = 0; i < inputs.length; i++) {
                dispatchInputEvent({currentTarget: inputs[i]});
            }
        }, 0);
    }

    function enhanceMatrixSearches() {
        const inputs = root.querySelectorAll('input[type="search"]');
        for (let i = 0; i < inputs.length; i++) {
            const input = inputs[i];
            const table = findSearchMatrix(input);
            if (!table || input.dataset.rmGenericMatrixSearchBound === '1') {
                continue;
            }
            input.dataset.rmGenericMatrixSearchBound = '1';
            const applyFilter = function () {
                const needle = normalizeSearchValue(input.value);
                const rows = table.querySelectorAll('tbody tr');
                for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                    const row = rows[rowIndex];
                    row.hidden = needle !== '' && !normalizeSearchValue(searchableRowText(row)).includes(needle);
                }
            };
            input.addEventListener('input', applyFilter);
            input.addEventListener('search', applyFilter);
            applyFilter();
        }
    }

    function preventSearchEnter(event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
    }

    function dispatchInputEvent(event) {
        const input = event.currentTarget;
        if (!input) {
            return;
        }
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function isSearchInput(node) {
        return node && node.matches && node.matches('input[type="search"]');
    }

    function findSearchMatrix(input) {
        const tools = input.closest('.rm-selector-tools');
        const nearbyTable = findFollowingMatrix(tools || input);
        if (nearbyTable) {
            return nearbyTable;
        }
        const scopes = [input.closest('.rm-card__body'), input.closest('.rm-card'), input.closest('form')];
        for (let i = 0; i < scopes.length; i++) {
            if (!scopes[i]) {
                continue;
            }
            const tables = scopes[i].querySelectorAll('table.rm-matrix');
            if (tables.length === 1) {
                return tables[0];
            }
        }
        return null;
    }

    function findFollowingMatrix(startNode) {
        let sibling = startNode ? startNode.nextElementSibling : null;
        while (sibling) {
            if (sibling.matches && sibling.matches('.rm-table-wrap')) {
                const table = sibling.querySelector('table.rm-matrix');
                if (table) {
                    return table;
                }
            }
            if (sibling.matches && sibling.matches('table.rm-matrix')) {
                return sibling;
            }
            if (!sibling.classList || (!sibling.classList.contains('rm-scroll-x') && !sibling.classList.contains('rm-sticky-head'))) {
                break;
            }
            sibling = sibling.nextElementSibling;
        }
        return null;
    }

    function searchableRowText(row) {
        const parts = [row.textContent || ''];
        const titledNodes = row.querySelectorAll('[data-title], [title], [data-uid], [data-id]');
        for (let i = 0; i < titledNodes.length; i++) {
            parts.push(titledNodes[i].dataset.title || '');
            parts.push(titledNodes[i].dataset.uid || '');
            parts.push(titledNodes[i].dataset.id || '');
            parts.push(titledNodes[i].getAttribute('title') || '');
        }
        const inputs = row.querySelectorAll('input, select, textarea');
        for (let i = 0; i < inputs.length; i++) {
            const field = inputs[i];
            parts.push(field.value || '');
            if (field.type === 'checkbox' || field.type === 'radio') {
                parts.push(field.checked ? 'checked active selected yes' : 'unchecked inactive no');
            }
        }
        return parts.join(' ');
    }

    function normalizeSearchValue(value) {
        return String(value || '').trim().toLowerCase();
    }

    function bindSelectionForms() {
        const forms = root.querySelectorAll('form[data-role="selection-form"]');
        for (let i = 0; i < forms.length; i++) {
            const form = forms[i];
            if (form.dataset.rmSelectionBound === '1') {
                continue;
            }
            form.dataset.rmSelectionBound = '1';
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                applySelectionForm(form);
            });
            const buttons = form.querySelectorAll('[data-role="apply-selection"]');
            for (let j = 0; j < buttons.length; j++) {
                buttons[j].addEventListener('click', function () {
                    applySelectionForm(form);
                });
            }
        }
    }

    function applySelectionForm(form) {
        const action = form.getAttribute('action') || window.location.href;
        const targetUrl = new URL(action, window.location.href);
        const currentUrl = new URL(window.location.href);
        if (!targetUrl.searchParams.has('token') && currentUrl.searchParams.has('token')) {
            targetUrl.searchParams.set('token', currentUrl.searchParams.get('token'));
        }

        const names = new Set();
        const controls = form.querySelectorAll('[name]');
        for (let i = 0; i < controls.length; i++) {
            names.add(controls[i].getAttribute('name'));
        }
        const resetParams = String(form.dataset.resetParams || '').split(',');
        for (let i = 0; i < resetParams.length; i++) {
            const name = resetParams[i].trim();
            if (name) {
                names.add(name);
            }
        }
        names.forEach(function (name) {
            targetUrl.searchParams.delete(name);
        });

        const formData = new FormData(form);
        formData.forEach(function (value, name) {
            if (String(value) !== '') {
                targetUrl.searchParams.append(name, value);
            }
        });
        window.location.assign(targetUrl.toString());
    }
    } catch (error) {
        console.error('Rights management base UI failed.', error);
    }
})();

(() => {
    'use strict';

    window.RightsManagementSave = {
        closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        },
        label(key) {
            const labelNode = document.querySelector('[data-role="i18n"] [data-i18n-key="' + key + '"]');
            return labelNode ? (labelNode.textContent || '').trim() : key;
        },
        translate(value) {
            return String(value || '').replace(/__rmLabel:([A-Za-z0-9_.-]+)__/g, (match, key) => {
                const label = this.label(key);
                return label || match;
            });
        },
        escapeHtml(value) {
            return this.translate(value).replace(/[&<>"']/g, function (char) {
                return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]);
            });
        },
        safeLabel(value) {
            // Neutralize this module's i18n token syntax (__rmLabel:KEY__, {labels.KEY},
            // {uiLabels.KEY}) inside DB-derived display text, so neither translate() nor
            // translateDom() rewrites a record title/label that happens to contain it. A
            // zero-width space breaks the token pattern while staying visually identical;
            // ordinary titles never contain the syntax and pass through unchanged, and
            // saved payloads carry UIDs only, so stored data is unaffected.
            return String(value || '')
                .replace(/__rmLabel:/g, '__rmLabel\u200B:')
                .replace(/\{labels\./g, '{labels\u200B.')
                .replace(/\{uiLabels\./g, '{uiLabels\u200B.');
        },
        openModal(modal, changesNode, html) {
            if (!modal || !changesNode) {
                this.notify('Confirmation dialog was not found.');
                return;
            }
            changesNode.innerHTML = this.translate(html);
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        },
        submit(scope, payload) {
            const form = document.querySelector('[data-role="save-form"]');
            if (!form) {
                this.notify('Speicherformular wurde nicht gefunden.');
                return;
            }
            const scopeInput = form.querySelector('input[name="scope"]');
            const payloadInput = form.querySelector('input[name="payload"]');
            const returnUrlInput = form.querySelector('input[name="returnUrl"]');
            const actionUrl = new URL(form.getAttribute('action') || form.action || window.location.href, window.location.href);
            const routeToken = actionUrl.searchParams.get('token');
            if (routeToken) {
                this.ensureHiddenInput(form, 'token').value = routeToken;
            }
            if (scopeInput) scopeInput.value = scope;
            if (payloadInput) payloadInput.value = JSON.stringify(payload || {});
            if (returnUrlInput) returnUrlInput.value = window.location.href;
            form.method = 'post';
            form.action = actionUrl.toString();
            if (!routeToken && !form.querySelector('input[name="token"]')) {
                this.notify('Save token is missing. Please clear the backend cache and reload the view.');
                return;
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            HTMLFormElement.prototype.submit.call(form);
        },
        ensureHiddenInput(form, name) {
            let input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.append(input);
            }
            return input;
        },
        notify(message) {
            let notice = document.querySelector('[data-role="save-client-notice"]');
            if (!notice) {
                notice = document.createElement('div');
                notice.setAttribute('data-role', 'save-client-notice');
                notice.setAttribute('role', 'alert');
                notice.className = 'rm-client-notice';
                notice.style.position = 'fixed';
                notice.style.right = '16px';
                notice.style.bottom = '16px';
                notice.style.zIndex = '9999';
                notice.style.maxWidth = '420px';
                notice.style.padding = '12px 14px';
                notice.style.border = '1px solid #b91c1c';
                notice.style.borderRadius = '6px';
                notice.style.background = '#fee2e2';
                notice.style.color = '#7f1d1d';
                notice.style.boxShadow = '0 12px 30px rgba(0,0,0,.18)';
                document.body.append(notice);
            }
            notice.textContent = message;
        },
    };
})();

(() => {
    'use strict';

    const form = document.querySelector('[data-role="matrix-save-source"]');
    if (!form) return;

    const canSave = truthy(form.dataset.canSave);
    const scope = form.dataset.scope || '';
    const saveButton = form.querySelector('[data-role="matrix-save"]');
    const discardButton = form.querySelector('[data-role="matrix-discard"]');
    const modal = document.querySelector('[data-role="confirm-modal"]');
    const modalChanges = modal ? modal.querySelector('[data-role="modal-changes"]') : null;
    const modalDiscard = modal ? modal.querySelector('[data-role="modal-discard"]') : null;
    const modalSave = modal ? modal.querySelector('[data-role="modal-save"]') : null;
    const initialSignature = signature(serialize());

    if (!canSave) {
        form.querySelectorAll('.rm-matrix input').forEach((input) => {
            input.disabled = true;
        });
    }
    form.querySelectorAll('[data-assignable="0"] input').forEach((input) => {
        input.disabled = true;
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
    });
    form.addEventListener('change', updateState);
    if (saveButton) {
        saveButton.addEventListener('click', openModal);
    }
    if (discardButton) {
        discardButton.addEventListener('click', discardMatrixDraft);
    }
    if (modalDiscard) {
        modalDiscard.addEventListener('click', () => window.RightsManagementSave.closeModal(modal));
    }
    if (modalSave) {
        modalSave.addEventListener('click', () => {
            if (canSave && hasChanges()) {
                window.RightsManagementSave.submit(scope, serialize());
            }
        });
    }
    updateState();

    function updateState() {
        const changed = hasChanges();
        if (saveButton) {
            saveButton.disabled = !canSave || !changed;
        }
        if (discardButton) {
            discardButton.disabled = !canSave || !changed;
        }
    }

    function discardMatrixDraft() {
        form.querySelectorAll('.rm-matrix input').forEach((input) => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = input.defaultChecked;
            }
        });
        updateState();
    }

    function openModal() {
        if (!canSave || !hasChanges()) return;
        const changes = buildChangeList();
        window.RightsManagementSave.openModal(modal, modalChanges, `
            <p><strong>${escapeHtml(scopeLabel())}</strong></p>
            <ul>${changes.map((change) => `<li>${escapeHtml(change)}</li>`).join('')}</ul>`);
    }

    function serialize() {
        const groups = new Map();
        form.querySelectorAll('.rm-matrix input[name]').forEach((input) => {
            if (input.closest('[data-assignable="0"]')) return;
            const pageTypeMatch = input.name.match(/^pageTypes\[(\d+)]/);
            const tableMatch = input.name.match(/^tables\[(\d+)]\[(.+)]$/);
            const moduleMatch = input.name.match(/^modules\[(\d+)]/);
            if (pageTypeMatch) {
                const group = ensureGroup(groups, Number(pageTypeMatch[1]));
                if (input.checked) group.pageTypes.push(input.value);
                return;
            }
            if (tableMatch) {
                const group = ensureGroup(groups, Number(tableMatch[1]));
                if (input.checked) group.tables[tableMatch[2]] = input.value;
                return;
            }
            if (moduleMatch) {
                const group = ensureGroup(groups, Number(moduleMatch[1]));
                if (input.checked) group.modules.push(input.value);
            }
        });

        return {groups: Array.from(groups.values())};
    }

    function ensureGroup(groups, uid) {
        if (!groups.has(uid)) {
            groups.set(uid, {uid, pageTypes: [], tables: {}, modules: []});
        }
        return groups.get(uid);
    }

    function hasChanges() {
        return signature(serialize()) !== initialSignature;
    }

    function signature(payload) {
        return JSON.stringify(payload.groups.map((group) => ({
            uid: group.uid,
            pageTypes: (group.pageTypes || []).map(String).sort(),
            modules: (group.modules || []).map(String).sort(),
            tables: Object.keys(group.tables || {}).sort().map((table) => [table, group.tables[table]]),
        })).sort((a, b) => a.uid - b.uid));
    }

    function buildChangeList() {
        const changes = [];
        form.querySelectorAll('.rm-matrix input[name]').forEach((input) => {
            if (input.type === 'checkbox' && input.checked !== input.defaultChecked) {
                changes.push(`${cellLabel(input)}: ${input.checked ? '+ ' : '- '}${input.value}`);
            }
            if (input.type === 'radio' && input.checked && !input.defaultChecked) {
                changes.push(`${cellLabel(input)}: ${input.value}`);
            }
        });
        return changes.length ? changes : ['__rmLabel:noChanges__'];
    }

    function cellLabel(input) {
        const row = input.closest('tr');
        const cell = input.closest('[data-group]');
        const groupUid = cell ? cell.dataset.group : '';
        const rowHead = row ? (row.querySelector('.rm-row-head')?.textContent || '').trim() : '';
        const groupHead = groupUid ? (form.querySelector(`.rm-group-head[data-group="${groupUid}"]`)?.textContent || '').trim() : '';
        return [rowHead, groupHead].filter(Boolean).join(' / ');
    }

    function scopeLabel() {
        return scope === 'module-management' ? '__rmLabel:modulePermissions__' : '__rmLabel:recordPermissions__';
    }

    function truthy(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function escapeHtml(value) {
        return window.RightsManagementSave.escapeHtml(value);
    }
})();


// RightsManagement template scripts moved from Fluid templates for CSP compliance

// Template: BackendUserManagement.html #0
(() => {
            const app = document.querySelector('#rm-user-rights-app');
            if (!app) return;

            const i18nNode = document.querySelector('[data-role="i18n"]');
            const hasFileMountFeature = isTruthy(app.dataset.hasFileMountFeature);
            const canSave = isTruthy(app.dataset.canSave);
            const nodes = {
                selectedCount: app.querySelector('[data-role="selected-count"]'),
                selectedTitle: app.querySelector('[data-role="selected-title"]'),
                selectedSummary: app.querySelector('[data-role="selected-summary"]'),
                userList: app.querySelector('[data-role="user-list"]'),
                userSearch: app.querySelector('[data-role="user-search"]'),
                tabs: Array.from(app.querySelectorAll('[data-role="editor-tab"]')),
                rightsTools: app.querySelector('[data-role="rights-tools"]'),
                groupTools: app.querySelector('[data-role="group-tools"]'),
                moduleTools: app.querySelector('[data-role="module-tools"]'),
                dbTools: app.querySelector('[data-role="db-tools"]'),
                treeTools: app.querySelector('[data-role="tree-tools"]'),
                fileTools: app.querySelector('[data-role="file-tools"]'),
                rightsSearch: app.querySelector('[data-role="rights-search"]'),
                groupSearch: app.querySelector('[data-role="group-search"]'),
                moduleSearch: app.querySelector('[data-role="module-search"]'),
                pageSearch: app.querySelector('[data-role="page-search"]'),
                pageTreeSearch: app.querySelector('[data-role="page-tree-search"]'),
                fileSearch: app.querySelector('[data-role="file-search"]'),
                overviewPanel: app.querySelector('[data-role="overview-panel"]'),
                groupsPanel: app.querySelector('[data-role="groups-panel"]'),
                modulesPanel: app.querySelector('[data-role="modules-panel"]'),
                dbPanel: app.querySelector('[data-role="db-panel"]'),
                treePanel: app.querySelector('[data-role="tree-panel"]'),
                filePanel: app.querySelector('[data-role="file-panel"]'),
                changeSummary: app.querySelector('[data-role="change-summary"]'),
                saveButton: app.querySelector('[data-role="save-draft"]'),
                discardButton: app.querySelector('[data-role="discard-draft"]'),
            };
            const modal = document.querySelector('[data-role="confirm-modal"]');
            const modalChanges = modal ? modal.querySelector('[data-role="modal-changes"]') : null;
            const modalDiscard = modal ? modal.querySelector('[data-role="modal-discard"]') : null;
            const modalSave = modal ? modal.querySelector('[data-role="modal-save"]') : null;
            const users = Array.from(app.querySelectorAll('[data-role="user-item"]')).map((node) => ({
                node,
                checkbox: node.querySelector('[data-role="user-check"]'),
                uid: Number(node.dataset.uid || 0),
                username: window.RightsManagementSave.safeLabel(node.dataset.title),
                realName: window.RightsManagementSave.safeLabel(node.dataset.realName),
                email: window.RightsManagementSave.safeLabel(node.dataset.email),
                description: window.RightsManagementSave.safeLabel(node.dataset.description),
                admin: isTruthy(node.dataset.admin),
                assignable: isTruthy(node.dataset.assignable),
                groups: parseIds(node.dataset.groups),
                modules: parseMixed(node.dataset.modules),
                dbMounts: parseIds(node.dataset.dbMounts),
                fileMounts: parseIds(node.dataset.fileMounts),
            }));
            const groups = Array.from(app.querySelectorAll('[data-role="group-data"]')).map((node) => ({
                uid: Number(node.dataset.uid || 0),
                title: window.RightsManagementSave.safeLabel(node.dataset.title),
                description: window.RightsManagementSave.safeLabel(node.dataset.description),
                assignable: isTruthy(node.dataset.assignable),
                subgroups: parseIds(node.dataset.subgroups),
                pageTypes: parseMixed(node.dataset.pageTypes),
                tablesSelect: parseMixed(node.dataset.tablesSelect),
                tablesModify: parseMixed(node.dataset.tablesModify),
                modules: parseMixed(node.dataset.modules),
                dbMounts: parseIds(node.dataset.dbMounts),
                fileMounts: parseIds(node.dataset.fileMounts),
            }));
            const pageTypes = Array.from(app.querySelectorAll('[data-role="page-type-data"]')).map((node) => ({id: node.dataset.id || '', label: window.RightsManagementSave.safeLabel(node.dataset.label), assignable: isTruthy(node.dataset.assignable)}));
            const tables = Array.from(app.querySelectorAll('[data-role="table-data"]')).map((node) => ({id: node.dataset.id || '', label: window.RightsManagementSave.safeLabel(node.dataset.label), assignable: isTruthy(node.dataset.assignable), canAssignWrite: isTruthy(node.dataset.canAssignWrite)}));
            const modules = Array.from(app.querySelectorAll('[data-role="module-data"]')).map((node) => ({id: node.dataset.id || '', label: window.RightsManagementSave.safeLabel(node.dataset.label), assignable: isTruthy(node.dataset.assignable)}));
            const pages = Array.from(app.querySelectorAll('[data-role="page-data"]')).map((node) => ({
                uid: Number(node.dataset.uid || 0),
                pid: Number(node.dataset.pid || 0),
                sorting: Number(node.dataset.sorting || 0),
                label: window.RightsManagementSave.safeLabel(node.dataset.label),
                meta: window.RightsManagementSave.safeLabel(node.dataset.meta),
                disabled: isTruthy(node.dataset.disabled),
                assignable: isTruthy(node.dataset.assignable),
            }));
            const fileMounts = Array.from(app.querySelectorAll('[data-role="file-data"]')).map((node) => ({
                uid: Number(node.dataset.uid || 0),
                label: window.RightsManagementSave.safeLabel(node.dataset.label),
                meta: window.RightsManagementSave.safeLabel(node.dataset.meta),
                disabled: isTruthy(node.dataset.disabled),
                assignable: isTruthy(node.dataset.assignable),
                readOnly: isTruthy(node.dataset.readOnly),
            }));
            const groupMap = new Map(groups.map((group) => [group.uid, group]));
            const state = {
                selectedUids: new Set(users.filter((user) => user.checkbox && user.checkbox.checked).map((user) => user.uid)),
                activeTab: 'overview',
                userSearch: '',
                rightSearch: '',
                groupSearch: '',
                moduleSearch: '',
                pageSearch: '',
                pageTreeSearch: '',
                fileSearch: '',
                drafts: new Map(),
            };

            bindEvents();

            function bindEvents() {
                if (nodes.userSearch) {
                    nodes.userSearch.addEventListener('input', () => {
                        state.userSearch = nodes.userSearch.value;
                        renderUserList();
                    });
                }
                if (nodes.userList) {
                    nodes.userList.addEventListener('change', (event) => {
                        const checkbox = event.target.closest('[data-role="user-check"]');
                        if (!checkbox) return;
                        const uid = Number(checkbox.dataset.uid || 0);
                        checkbox.checked ? state.selectedUids.add(uid) : state.selectedUids.delete(uid);
                        render();
                    });
                }
                nodes.tabs.forEach((tab) => {
                    tab.addEventListener('click', () => {
                        state.activeTab = tab.dataset.tab || 'overview';
                        render();
                    });
                });
                bindSearch(nodes.rightsSearch, 'rightSearch');
                bindSearch(nodes.groupSearch, 'groupSearch');
                bindSearch(nodes.moduleSearch, 'moduleSearch');
                bindSearch(nodes.pageSearch, 'pageSearch');
                bindSearch(nodes.pageTreeSearch, 'pageTreeSearch');
                bindSearch(nodes.fileSearch, 'fileSearch');
                bindAssignmentPanel(nodes.overviewPanel);
                bindAssignmentPanel(nodes.groupsPanel);
                bindAssignmentPanel(nodes.modulesPanel);
                bindAssignmentPanel(nodes.dbPanel);
                bindAssignmentPanel(nodes.treePanel);
                bindAssignmentPanel(nodes.filePanel);
                if (nodes.saveButton) nodes.saveButton.addEventListener('click', openModal);
                if (nodes.discardButton) nodes.discardButton.addEventListener('click', () => {
                    state.drafts.clear();
                    render();
                });
                if (modalDiscard) modalDiscard.addEventListener('click', () => {
                    closeModal();
                });
                if (modalSave) modalSave.addEventListener('click', submitDraft);
            }
            function bindSearch(input, key) {
                if (!input) return;
                input.addEventListener('input', () => {
                    state[key] = input.value;
                    renderPanels();
                });
            }
            function bindAssignmentPanel(panel) {
                if (!panel) return;
                panel.addEventListener('change', (event) => {
                    const checkbox = event.target.closest('input[type="checkbox"][data-kind]');
                    if (!checkbox || checkbox.disabled) return;
                    setAssignment(checkbox.dataset.kind, checkbox.dataset.uid || '', checkbox.checked);
                });
                panel.addEventListener('click', (event) => {
                    if (event.target.closest('input')) return;
                    const cell = event.target.closest('.rm-check-cell');
                    if (!cell || !panel.contains(cell)) return;
                    const checkbox = cell.querySelector('input[type="checkbox"][data-kind]');
                    if (!checkbox || checkbox.disabled) return;
                    checkbox.indeterminate = false;
                    checkbox.checked = !checkbox.checked;
                    setAssignment(checkbox.dataset.kind, checkbox.dataset.uid || '', checkbox.checked);
                });
            }
            function render() {
                if (!hasFileMountFeature && state.activeTab === 'files') state.activeTab = 'overview';
                renderFeatureGates();
                renderUserList();
                renderHeader();
                renderPanels();
                translateDom(app);
            }
            function renderFeatureGates() {
                app.querySelectorAll('[data-requires-feature="file-mounts"]').forEach((node) => {
                    node.hidden = !hasFileMountFeature;
                });
            }
            function renderUserList() {
                const needle = normalize(state.userSearch);
                users.forEach((user) => {
                    const search = `${user.username} ${user.realName} ${user.email} ${user.description} ${user.uid} ${user.groups.join(' ')} ${user.admin ? '__rmLabel:admin__' : ''}`;
                    const visible = !needle || normalize(search).includes(needle);
                    user.node.hidden = !visible;
                    user.node.classList.toggle('is-active', state.selectedUids.has(user.uid));
                    user.node.classList.toggle('is-disabled', !user.assignable);
                    user.node.title = user.assignable ? '' : '__rmLabel:noPermission__';
                    if (user.checkbox) user.checkbox.checked = state.selectedUids.has(user.uid);
                });
            }
            function renderHeader() {
                const selectedUsers = getSelectedUsers();
                if (nodes.selectedCount) nodes.selectedCount.textContent = String(selectedUsers.length);
                if (!nodes.selectedTitle || !nodes.selectedSummary) return;
                if (!selectedUsers.length) {
                    nodes.selectedTitle.textContent = t('noUsersSelected');
                    nodes.selectedSummary.textContent = t('commonAssignedRights');
                    return;
                }
                if (selectedUsers.length === 1) {
                    const user = selectedUsers[0];
                    nodes.selectedTitle.textContent = `${user.username} (UID ${user.uid})`;
                    nodes.selectedSummary.textContent = t('assignedRightsThisUser');
                    return;
                }
                nodes.selectedTitle.textContent = `${selectedUsers.length} ${t('usersSelected')}`;
                nodes.selectedSummary.textContent = t('onlyCommonAssignedRights');
            }
            function renderPanels() {
                const selectedUsers = getSelectedUsers();
                const panelMap = {
                    overview: nodes.overviewPanel,
                    groups: nodes.groupsPanel,
                    modules: nodes.modulesPanel,
                    db: nodes.dbPanel,
                    tree: nodes.treePanel,
                    files: nodes.filePanel,
                };
                const toolMap = {
                    overview: nodes.rightsTools,
                    groups: nodes.groupTools,
                    modules: nodes.moduleTools,
                    db: nodes.dbTools,
                    tree: nodes.treeTools,
                    files: nodes.fileTools,
                };
                nodes.tabs.forEach((tab) => {
                    const active = tab.dataset.tab === state.activeTab;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                Object.keys(panelMap).forEach((key) => {
                    if (panelMap[key]) panelMap[key].hidden = key !== state.activeTab;
                    if (toolMap[key]) toolMap[key].hidden = key !== state.activeTab;
                });
                if (state.activeTab === 'groups') renderGroupEditor(selectedUsers);
                else if (state.activeTab === 'modules') renderModuleEditor(selectedUsers);
                else if (state.activeTab === 'db') renderDbEditor(selectedUsers);
                else if (state.activeTab === 'tree') renderPageTreeEditor(selectedUsers);
                else if (state.activeTab === 'files') renderFileEditor(selectedUsers);
                else renderOverview(selectedUsers);
                renderChanges(selectedUsers);
                translateDom(app);
            }
            function renderOverview(selectedUsers) {
                nodes.overviewPanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.overviewPanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                const rows = buildRightsRows(selectedUsers);
                if (!rows.some((section) => section.rows.length)) {
                    nodes.overviewPanel.innerHTML = '<div class="rm-empty">__rmLabel:noCommonRights__</div>';
                    return;
                }
                const needle = normalize(state.rightSearch);
                const table = document.createElement('table');
                table.className = 'rm-rights-table';
                table.innerHTML = '<thead><tr><th class="rm-row-head">__rmLabel:area__</th><th class="rm-check-cell">__rmLabel:direct__</th><th class="rm-check-cell">__rmLabel:viaGroups__</th><th class="rm-source-cell">__rmLabel:source__</th></tr></thead><tbody></tbody>';
                const tbody = table.querySelector('tbody');
                let visibleRows = 0;
                for (const section of rows) {
                    const sectionRows = section.rows.filter((row) => {
                        if (!needle) return true;
                        return normalize(rightRowSearchText(section, row)).includes(needle);
                    });
                    if (!sectionRows.length) continue;
                    const head = document.createElement('tr');
                    head.className = 'rm-rights-section';
                    head.innerHTML = `<th colspan="4">${escapeHtml(section.label)}</th>`;
                    tbody.append(head);
                    for (const row of sectionRows) {
                        visibleRows++;
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <th class="rm-row-head">${escapeHtml(row.label)}<br><span class="rm-muted">${escapeHtml(row.meta)}</span></th>
                            ${renderCheckCell(row.direct, row.directControl, 'direct')}
                            ${renderCheckCell(row.group, null, 'status')}
                            <td class="rm-source-cell">${row.sources.length ? row.sources.map((source) => `<span class="rm-source-list">${escapeHtml(source)}</span>`).join('') : '<span class="rm-muted">-</span>'}</td>`;
                        tr.querySelectorAll('input[data-indeterminate="1"]').forEach((input) => { input.indeterminate = true; });
                        tbody.append(tr);
                    }
                }
                if (!visibleRows) {
                    nodes.overviewPanel.innerHTML = '<div class="rm-empty">__rmLabel:noRightsFound__</div>';
                    return;
                }
                nodes.overviewPanel.append(table);
            }
            function rightRowSearchText(section, row) {
                const parts = [
                    section.label,
                    row.label,
                    row.meta,
                    row.sources.join(' '),
                    row.direct !== 'none' ? '__rmLabel:direct__' : '',
                    row.group !== 'none' ? '__rmLabel:viaGroups__' : '',
                    row.direct === 'some' || row.group === 'some' ? '__rmLabel:partial__' : '',
                ];
                return parts.join(' ');
            }
            function renderGroupEditor(selectedUsers) {
                nodes.groupsPanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.groupsPanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                nodes.groupsPanel.append(createAssignmentHeader());
                const needle = normalize(state.groupSearch);
                for (const group of groups) {
                    if (needle && !normalize(`${group.title} ${group.description} ${group.uid} __rmLabel:group__ __rmLabel:groups__`).includes(needle)) continue;
                    nodes.groupsPanel.append(createAssignmentRow('groups', group.uid, group.title, `UID ${group.uid}`, selectedUsers, (user) => getDraft(user).groups, (context) => context.inheritedGroups.some((candidate) => candidate.uid === group.uid), {
                        disabled: !group.assignable,
                        disabledReason: '__rmLabel:noPermission__',
                    }));
                }
            }
            function renderModuleEditor(selectedUsers) {
                nodes.modulesPanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.modulesPanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                nodes.modulesPanel.append(createAssignmentHeader());
                const needle = normalize(state.moduleSearch);
                for (const module of modules) {
                    if (needle && !normalize(`${module.label} ${module.id} __rmLabel:modules__ __rmLabel:backendModule__`).includes(needle)) continue;
                    nodes.modulesPanel.append(createAssignmentRow('modules', module.id, module.label, module.id, selectedUsers, (user) => getDraft(user).modules, (context) => groupsWithValue(context.allGroups, 'modules', module.id).length > 0, {
                        disabled: !module.assignable,
                        disabledReason: '__rmLabel:noPermission__',
                    }));
                }
            }
            function renderDbEditor(selectedUsers) {
                nodes.dbPanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.dbPanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                nodes.dbPanel.append(createAssignmentHeader());
                const needle = normalize(state.pageSearch);
                for (const page of pages) {
                    if (needle && !normalize(`${page.label} ${page.meta} ${page.uid} ${page.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:databaseMounts__`).includes(needle)) continue;
                    nodes.dbPanel.append(createAssignmentRow('dbMounts', page.uid, page.label || '__rmLabel:noTitle__', `${page.meta}${page.disabled ? ' / __rmLabel:hidden__' : ''}`, selectedUsers, (user) => getDraft(user).dbMounts, (context) => groupsWithValue(context.allGroups, 'dbMounts', page.uid).length > 0, {
                        disabled: !page.assignable,
                        disabledReason: '__rmLabel:noPermission__',
                    }));
                }
            }
            function renderFileEditor(selectedUsers) {
                if (!nodes.filePanel) return;
                nodes.filePanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.filePanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                nodes.filePanel.append(createAssignmentHeader());
                const needle = normalize(state.fileSearch);
                for (const mount of fileMounts) {
                    if (needle && !normalize(`${mount.label} ${mount.meta} ${mount.uid} ${mount.readOnly ? '__rmLabel:readOnly__' : ''} ${mount.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:fileMounts__`).includes(needle)) continue;
                    const meta = `${mount.meta || '__rmLabel:noPath__'}${mount.readOnly ? ' / __rmLabel:readOnly__' : ''}${mount.disabled ? ' / __rmLabel:hidden__' : ''}`;
                    nodes.filePanel.append(createAssignmentRow('fileMounts', mount.uid, mount.label || '__rmLabel:noTitle__', meta, selectedUsers, (user) => getDraft(user).fileMounts, (context) => groupsWithValue(context.allGroups, 'fileMounts', mount.uid).length > 0, {
                        disabled: !mount.assignable,
                        disabledReason: '__rmLabel:noPermission__',
                    }));
                }
            }
            function renderPageTreeEditor(selectedUsers) {
                nodes.treePanel.innerHTML = '';
                if (!selectedUsers.length) {
                    nodes.treePanel.innerHTML = '<div class="rm-empty">__rmLabel:noUsersSelected__</div>';
                    return;
                }
                nodes.treePanel.append(createAssignmentHeader());
                const needle = normalize(state.pageTreeSearch);
                const pageMap = new Map(pages.map((page) => [page.uid, page]));
                const children = new Map();
                for (const page of pages) {
                    const pid = pageMap.has(page.pid) ? page.pid : 0;
                    if (!children.has(pid)) children.set(pid, []);
                    children.get(pid).push(page);
                }
                for (const list of children.values()) {
                    list.sort((a, b) => a.sorting - b.sorting || a.label.localeCompare(b.label));
                }
                const fragment = document.createDocumentFragment();
                for (const page of children.get(0) || []) renderPageNode(page, 0, fragment);
                if (!fragment.childNodes.length) {
                    nodes.treePanel.innerHTML = '<div class="rm-empty">__rmLabel:noPagesFound__</div>';
                    return;
                }
                nodes.treePanel.append(fragment);

                function renderPageNode(page, depth, target) {
                    const childItems = children.get(page.uid) || [];
                    const ownMatch = !needle || normalize(`${page.label} ${page.meta} ${page.uid} ${page.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:pageTree__ __rmLabel:databaseMounts__`).includes(needle);
                    const childMatches = childItems.some((child) => hasVisiblePage(child));
                    if (needle && !ownMatch && !childMatches) return;
                    target.append(createPageTreeRow(page, depth, selectedUsers));
                    for (const child of childItems) renderPageNode(child, depth + 1, target);
                }
                function hasVisiblePage(page) {
                    if (normalize(`${page.label} ${page.meta} ${page.uid} ${page.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:pageTree__ __rmLabel:databaseMounts__`).includes(needle)) return true;
                    return (children.get(page.uid) || []).some((child) => hasVisiblePage(child));
                }
            }
            function createPageTreeRow(page, depth, selectedUsers) {
                const selectedCount = selectedUsers.filter((user) => getDraft(user).dbMounts.has(page.uid)).length;
                const allSelected = selectedUsers.length > 0 && selectedCount === selectedUsers.length;
                const partial = selectedCount > 0 && !allSelected;
                const contexts = selectedUsers.map((user) => buildUserContext(user));
                const groupCount = contexts.filter((context) => groupsWithValue(context.allGroups, 'dbMounts', page.uid).length > 0).length;
                const groupAllSelected = selectedUsers.length > 0 && groupCount === selectedUsers.length;
                const groupPartial = groupCount > 0 && !groupAllSelected;
                const disabled = !canSave || !page.assignable || selectedUsers.some((user) => !user.assignable);
                const row = document.createElement('label');
                row.className = `rm-tree-row${allSelected ? ' is-active' : ''}${partial ? ' is-partial' : ''}${groupAllSelected ? ' has-group-right' : ''}${groupPartial ? ' has-group-partial' : ''}${page.disabled || !page.assignable ? ' is-disabled' : ''}`;
                if (!page.assignable) row.title = '__rmLabel:noPermission__';
                row.style.setProperty('--depth', String(depth));
                row.innerHTML = `
                    <span class="rm-assignment-checks">
                        <input type="checkbox" data-kind="dbMounts" data-uid="${page.uid}" title="${page.assignable ? '__rmLabel:directAssignmentEdit__' : '__rmLabel:noPermission__'}" ${allSelected ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                        <input type="checkbox" disabled title="__rmLabel:viaGroups__" ${groupAllSelected ? 'checked' : ''} ${groupPartial ? 'data-indeterminate="1"' : ''}>
                    </span>
                    <span class="rm-row-main">
                        <strong>${escapeHtml(page.label || '__rmLabel:noTitle__')}</strong>
                        <span class="rm-muted">${escapeHtml(page.meta)}</span>${page.disabled ? '<span class="rm-badge">__rmLabel:hidden__</span>' : ''}${partial ? `<span class="rm-badge">__rmLabel:partial__ (${selectedCount}/${selectedUsers.length})</span>` : ''}
                    </span>`;
                row.querySelector('input[data-kind]').indeterminate = partial;
                row.querySelectorAll('input[data-indeterminate="1"]').forEach((input) => { input.indeterminate = true; });
                return row;
            }
            function buildRightsRows(selectedUsers) {
                const contexts = selectedUsers.map((user) => buildUserContext(user));
                const sections = [
                    {label: '__rmLabel:pageTypes__', rows: pageTypes.map((pageType) => combineRightsRow(pageType.label, `[${pageType.id}]`, contexts.map((context) => booleanGroupRight(context, 'pageTypes', pageType.id))))},
                    {label: '__rmLabel:tablesRead__', rows: tables.map((table) => combineRightsRow(table.label, `[${table.id}]`, contexts.map((context) => tableRight(context, table.id, 'read'))))},
                    {label: '__rmLabel:tablesWrite__', rows: tables.map((table) => combineRightsRow(table.label, `[${table.id}]`, contexts.map((context) => tableRight(context, table.id, 'write'))))},
                ];
                return sections;
            }
            function combineRightsRow(label, meta, values, directControl) {
                const total = values.length;
                return {
                    label,
                    meta,
                    directControl,
                    direct: stateFor(values.filter((value) => value.direct).length, total),
                    group: stateFor(values.filter((value) => value.group).length, total),
                    sources: unique([].concat(...values.map((value) => value.sources))),
                };
            }
            function booleanGroupRight(context, field, value) {
                if (context.user.admin) return {direct: false, group: false, sources: ['__rmLabel:admin__']};
                const directGroups = groupsWithValue(context.directGroups, field, value);
                const inheritedGroups = groupsWithValue(context.inheritedGroups, field, value);
                return {
                    direct: false,
                    group: directGroups.length > 0 || inheritedGroups.length > 0,
                    sources: formatGroupSources(directGroups, inheritedGroups),
                };
            }
            function tableRight(context, tableId, requiredMode) {
                if (context.user.admin) return {direct: false, group: false, sources: ['__rmLabel:admin__']};
                const directGroups = groupsWithTableMode(context.directGroups, tableId, requiredMode);
                const inheritedGroups = groupsWithTableMode(context.inheritedGroups, tableId, requiredMode);
                return {
                    direct: false,
                    group: directGroups.length > 0 || inheritedGroups.length > 0,
                    sources: formatGroupSources(directGroups, inheritedGroups),
                };
            }
            function renderCheckCell(state, control, mode) {
                const checked = state === 'all' ? 'checked' : '';
                const partial = state === 'some';
                const indeterminate = partial ? 'data-indeterminate="1"' : '';
                if (control) {
                    return `<td class="rm-check-cell"><input type="checkbox" data-kind="${escapeHtml(control.kind)}" data-uid="${escapeHtml(control.uid)}" title="__rmLabel:directAssignmentEdit__" ${checked} ${indeterminate}>${partial ? '<span class="rm-badge">__rmLabel:partial__</span>' : ''}</td>`;
                }
                if (mode === 'direct') {
                    return '<td class="rm-check-cell is-readonly" title="__rmLabel:viaGroups__"><span class="rm-muted">-</span></td>';
                }
                return `<td class="rm-check-cell is-readonly"><input type="checkbox" disabled title="__rmLabel:inheritedRightsReadonly__" ${checked} ${indeterminate}>${partial ? '<span class="rm-badge">__rmLabel:partial__</span>' : ''}</td>`;
            }
            function stateFor(count, total) {
                if (!total || count === 0) return 'none';
                return count === total ? 'all' : 'some';
            }
            function createAssignmentHeader() {
                const row = document.createElement('div');
                row.className = 'rm-assignment-head';
                row.innerHTML = `
                    <span class="rm-assignment-legend">__rmLabel:direct__ / __rmLabel:viaGroups__</span>`;
                return row;
            }
            function createAssignmentRow(kind, uid, title, meta, selectedUsers, valueGetter, groupValueGetter, options = {}) {
                const selectedCount = selectedUsers.filter((user) => valueGetter(user).has(uid)).length;
                const allSelected = selectedUsers.length > 0 && selectedCount === selectedUsers.length;
                const partial = selectedCount > 0 && !allSelected;
                const contexts = groupValueGetter ? selectedUsers.map((user) => buildUserContext(user)) : [];
                const groupCount = groupValueGetter ? contexts.filter((context) => groupValueGetter(context)).length : 0;
                const groupAllSelected = selectedUsers.length > 0 && groupCount === selectedUsers.length;
                const groupPartial = groupCount > 0 && !groupAllSelected;
                const disabled = !canSave || selectedUsers.some((user) => !user.assignable) || Boolean(options.disabled);
                const directTitle = disabled ? (options.disabledReason || '__rmLabel:noPermission__') : '__rmLabel:directAssignmentEdit__';
                const row = document.createElement('label');
                row.className = `rm-right-row${allSelected ? ' is-active' : ''}${partial ? ' is-partial' : ''}${groupAllSelected ? ' has-group-right' : ''}${groupPartial ? ' has-group-partial' : ''}${disabled ? ' is-disabled' : ''}`;
                if (disabled) row.title = options.disabledReason || '__rmLabel:noPermission__';
                row.innerHTML = `
                    <span class="rm-right-main">
                        <span class="rm-assignment-checks">
                            <input type="checkbox" data-kind="${escapeHtml(kind)}" data-uid="${escapeHtml(uid)}" title="${escapeHtml(directTitle)}" ${allSelected ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                            <input type="checkbox" disabled title="__rmLabel:viaGroups__" ${groupAllSelected ? 'checked' : ''} ${groupPartial ? 'data-indeterminate="1"' : ''}>
                        </span>
                        <span><strong>${escapeHtml(title)}</strong><span class="rm-muted">${escapeHtml(meta)}</span></span>
                    </span>
                    ${disabled ? '<span class="rm-badge">__rmLabel:noPermission__</span>' : ''}${partial ? `<span class="rm-badge">__rmLabel:partial__ (${selectedCount}/${selectedUsers.length})</span>` : ''}`;
                row.querySelector('input[data-kind]').indeterminate = partial;
                row.querySelectorAll('input[data-indeterminate="1"]').forEach((input) => { input.indeterminate = true; });
                return row;
            }
            function setAssignment(type, uid, value) {
                if (!canSave) return;
                const normalizedUid = normalizeAssignmentValue(type, uid);
                if (type === 'groups' && value) {
                    const group = groupMap.get(Number(normalizedUid));
                    if (group && !group.assignable) return;
                }
                const draftKey = draftFieldForType(type);
                for (const user of getSelectedUsers()) {
                    const draft = getDraft(user);
                    const values = new Set(draft[draftKey]);
                    value ? values.add(normalizedUid) : values.delete(normalizedUid);
                    draft[draftKey] = values;
                    state.drafts.set(user.uid, draft);
                }
                renderPanels();
            }
            function renderChanges(selectedUsers) {
                const changes = aggregateChanges(selectedUsers);
                nodes.changeSummary.innerHTML = changes.length ? changes.map((change) => `<span class="rm-change">${escapeHtml(change)}</span>`).join('') : '__rmLabel:noChanges__';
                nodes.saveButton.disabled = !canSave || !changes.length;
                nodes.discardButton.disabled = !canSave || !changes.length;
            }
            function aggregateChanges(selectedUsers) {
                const aggregate = new Map();
                for (const user of selectedUsers) {
                    const draft = getDraft(user);
                    collectDiff(user.groups, draft.groups, '+ __rmLabel:group__', '- __rmLabel:group__', groupLabel);
                    collectDiff(user.modules, draft.modules, '+ __rmLabel:backendModule__', '- __rmLabel:backendModule__', moduleLabel);
                    collectDiff(user.dbMounts, draft.dbMounts, '+ DB', '- DB', pageLabel);
                    if (hasFileMountFeature) collectDiff(user.fileMounts, draft.fileMounts, '+ __rmLabel:file__', '- __rmLabel:file__', fileLabel);
                }
                return [...aggregate.values()].map((item) => `${item.prefix} ${item.label} (${item.count} __rmLabel:users__)`);

                function collectDiff(originalValues, currentValues, addPrefix, removePrefix, labeler) {
                    const original = new Set(originalValues);
                    for (const uid of currentValues) if (!original.has(uid)) collect(`${addPrefix}:${uid}`, addPrefix, labeler(uid));
                    for (const uid of original) if (!currentValues.has(uid)) collect(`${removePrefix}:${uid}`, removePrefix, labeler(uid));
                }
                function collect(key, prefix, label) {
                    const item = aggregate.get(key) || {prefix, label, count: 0};
                    item.count++;
                    aggregate.set(key, item);
                }
            }
            function buildUserContext(user) {
                const draft = getDraft(user);
                const directGroups = [...draft.groups].map((groupId) => groupMap.get(Number(groupId))).filter(Boolean);
                const inheritedMap = new Map();
                for (const group of directGroups) {
                    collectInheritedGroups(group, new Set([group.uid]), inheritedMap);
                }
                return {
                    user,
                    draft,
                    directGroups,
                    inheritedGroups: [...inheritedMap.values()],
                    allGroups: [...directGroups, ...inheritedMap.values()],
                };
            }
            function collectInheritedGroups(group, visited, inheritedMap) {
                for (const subgroupId of group.subgroups) {
                    const uid = Number(subgroupId);
                    if (visited.has(uid)) continue;
                    const inheritedGroup = groupMap.get(uid);
                    if (!inheritedGroup) continue;
                    visited.add(uid);
                    inheritedMap.set(uid, inheritedGroup);
                    collectInheritedGroups(inheritedGroup, visited, inheritedMap);
                }
            }
            function groupsWithValue(groupList, field, value) {
                return groupList.filter((group) => containsMixed(group[field] || [], value));
            }
            function groupsWithTableMode(groupList, tableId, requiredMode) {
                return groupList.filter((group) => requiredMode === 'write'
                    ? containsMixed(group.tablesModify, tableId)
                    : containsMixed(group.tablesSelect, tableId) || containsMixed(group.tablesModify, tableId));
            }
            function formatGroupSources(directGroups, inheritedGroups) {
                return [
                    ...directGroups.map((group) => `__rmLabel:directGroup__: ${group.title} [${group.uid}]`),
                    ...inheritedGroups.map((group) => `__rmLabel:inherited__: ${group.title} [${group.uid}]`),
                ];
            }
            function containsMixed(values, value) {
                return values.some((item) => String(item) === String(value));
            }
            function normalizeAssignmentValue(type, value) {
                const raw = String(value || '');
                return type === 'modules' ? raw : Number(raw);
            }
            function draftFieldForType(type) {
                if (type === 'db') return 'dbMounts';
                if (type === 'files') return 'fileMounts';
                return type;
            }
            function unique(values) {
                return [...new Set(values.filter(Boolean))];
            }
            function openModal() {
                if (!canSave) return;
                const selectedUsers = getSelectedUsers();
                const changes = aggregateChanges(selectedUsers);
                if (!changes.length) return;
                window.RightsManagementSave.openModal(modal, modalChanges, `
                    <p><strong>${selectedUsers.length} __rmLabel:backendUsers__</strong></p>
                    <ul>${changes.map((change) => `<li>${escapeHtml(change)}</li>`).join('')}</ul>`);
                translateDom(modal);
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }
            function submitDraft() {
                if (!canSave) return;
                window.RightsManagementSave.submit('backend-user-management', buildPayload());
            }
            function buildPayload() {
                return {
                    users: getSelectedUsers().map((user) => {
                        const draft = getDraft(user);
                        return {
                            uid: user.uid,
                            groups: [...draft.groups],
                            modules: [...draft.modules],
                            dbMounts: [...draft.dbMounts],
                            fileMounts: [...draft.fileMounts],
                        };
                    }).filter((item) => {
                        const user = users.find((candidate) => candidate.uid === item.uid);
                        return user && (
                            hasListChanged(user.groups, item.groups)
                            || hasListChanged(user.modules, item.modules)
                            || hasListChanged(user.dbMounts, item.dbMounts)
                            || hasListChanged(user.fileMounts, item.fileMounts)
                        );
                    }),
                };
            }
            function hasListChanged(left, right) {
                const leftValues = left.map(String).sort();
                const rightValues = right.map(String).sort();
                return leftValues.length !== rightValues.length || leftValues.some((value, index) => value !== rightValues[index]);
            }
            function getSelectedUsers() {
                return users.filter((user) => state.selectedUids.has(user.uid));
            }
            function getDraft(user) {
                const draft = state.drafts.get(user.uid);
                return {
                    groups: new Set(draft && draft.groups ? draft.groups : user.groups),
                    modules: new Set(draft && draft.modules ? draft.modules : user.modules),
                    dbMounts: new Set(draft && draft.dbMounts ? draft.dbMounts : user.dbMounts),
                    fileMounts: new Set(draft && draft.fileMounts ? draft.fileMounts : user.fileMounts),
                };
            }
            function groupLabel(uid) {
                const group = groupMap.get(Number(uid));
                return group ? `${group.title} [${group.uid}]` : `UID ${uid}`;
            }
            function pageTypeLabel(id) {
                const item = pageTypes.find((pageType) => String(pageType.id) === String(id));
                return item ? `${item.label} [${item.id}]` : String(id);
            }
            function tableLabel(id) {
                const item = tables.find((table) => table.id === id);
                return item ? item.label : id;
            }
            function moduleLabel(id) {
                const item = modules.find((module) => module.id === id);
                return item ? `${item.label} [${item.id}]` : id;
            }
            function pageLabel(uid) {
                const page = pages.find((candidate) => candidate.uid === Number(uid));
                return page ? `${page.label} [${page.meta}]` : `Page ID ${uid}`;
            }
            function fileLabel(uid) {
                const mount = fileMounts.find((candidate) => candidate.uid === Number(uid));
                return mount ? `${mount.label} [${mount.uid}]` : `sys_filemounts_${uid}`;
            }
            function isTruthy(value) {
                const normalized = String(value || '').toLowerCase();
                return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
            }
            function parseIds(value) {
                return String(value || '').split(',').map((id) => Number(id.trim())).filter(Boolean);
            }
            function parseMixed(value) {
                return String(value || '').split(',').map((id) => id.trim()).filter(Boolean).map((id) => /^\d+$/.test(id) ? Number(id) : id);
            }
            function t(key) {
                if (!i18nNode) return key;
                const labelNode = i18nNode.querySelector('[data-i18n-key="' + key + '"]');
                let value = '';
                if (labelNode) {
                    value = labelNode.textContent || '';
                } else if (i18nNode.dataset) {
                    value = i18nNode.dataset[key] || '';
                }
                value = String(value || '').trim();
                if (!value || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1 || value.indexOf('{' + 'f:' + 'translate') !== -1 || value.indexOf('LLL:' + 'EXT:') !== -1 || value.indexOf('common.') === 0) {
                    return key;
                }
                return value;
            }
            function translate(value) {
                return String(value || '')
                    .replace(/__rmLabel:([A-Za-z0-9_.-]+)__/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{labels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{uiLabels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    });
            }
            function translateDom(rootNode) {
                if (!rootNode) return;
                const walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT);
                const textNodes = [];
                while (walker.nextNode()) textNodes.push(walker.currentNode);
                for (let i = 0; i < textNodes.length; i++) {
                    if (textNodes[i].nodeValue.indexOf('__rmLabel:') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'labels.') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'uiLabels.') !== -1) {
                        textNodes[i].nodeValue = translate(textNodes[i].nodeValue);
                    }
                }
                const attrNodes = rootNode.querySelectorAll('[title], [aria-label], [placeholder]');
                for (let i = 0; i < attrNodes.length; i++) {
                    const attrs = ['title', 'aria-label', 'placeholder'];
                    for (let j = 0; j < attrs.length; j++) {
                        const attr = attrs[j];
                        const value = attrNodes[i].getAttribute(attr);
                        if (value && (value.indexOf('__rmLabel:') !== -1 || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1)) {
                            attrNodes[i].setAttribute(attr, translate(value));
                        }
                    }
                }
            }
            function normalize(value) {
                return translate(value).trim().toLowerCase();
            }
            function escapeHtml(value) {
                return String(translate(value)).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
            }
            render();
        })();

// Template: GroupManagement.html #0
(() => {
            const input = document.querySelector('[data-role="group-table-search"]');
            if (!input) return;
            const rows = [...document.querySelectorAll('[data-role="group-table-row"]')];
            input.addEventListener('input', () => {
                const needle = normalize(input.value);
                for (const row of rows) {
                    const searchable = `${row.textContent || ''} ${row.dataset.title || ''} ${row.dataset.description || ''} ${row.dataset.uid || ''}`;
                    row.hidden = needle !== '' && !normalize(searchable).includes(needle);
                }
            });

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
        (() => {
            const app = document.querySelector('#rm-group-management-app');
            if (!app) return;
            const canSave = isTruthy(app.dataset.canSave);
            const titleInput = app.querySelector('[data-role="new-group-title"]');
            const descriptionInput = app.querySelector('[data-role="new-group-description"]');
            const createButton = app.querySelector('[data-role="create-group"]');
            const discardGroupDraftButton = app.querySelector('[data-role="discard-group-draft"]');
            const deleteButtons = Array.from(app.querySelectorAll('[data-role="delete-group"]'));
            const modal = document.querySelector('[data-role="confirm-modal"]');
            const modalChanges = modal ? modal.querySelector('[data-role="modal-changes"]') : null;
            const modalDiscard = modal ? modal.querySelector('[data-role="modal-discard"]') : null;
            const modalSave = modal ? modal.querySelector('[data-role="modal-save"]') : null;
            let pendingPayload = null;

            if (titleInput) titleInput.addEventListener('input', syncCreateButton);
            if (descriptionInput) descriptionInput.addEventListener('input', syncCreateButton);
            if (createButton) createButton.addEventListener('click', openCreateModal);
            if (discardGroupDraftButton) discardGroupDraftButton.addEventListener('click', discardGroupDraft);
            deleteButtons.forEach((button) => {
                button.disabled = !canSave;
                button.addEventListener('click', () => openDeleteModal(button));
            });
            if (modalDiscard) modalDiscard.addEventListener('click', () => {
                pendingPayload = null;
                window.RightsManagementSave.closeModal(modal);
            });
            if (modalSave) modalSave.addEventListener('click', () => {
                if (pendingPayload) {
                    window.RightsManagementSave.submit('group-management', pendingPayload);
                }
            });
            syncCreateButton();

            function syncCreateButton() {
                const hasDraft = Boolean(String(titleInput ? titleInput.value : '').trim() || String(descriptionInput ? descriptionInput.value : '').trim());
                if (createButton) {
                    createButton.disabled = !canSave || !String(titleInput ? titleInput.value : '').trim();
                }
                if (discardGroupDraftButton) {
                    discardGroupDraftButton.disabled = !canSave || !hasDraft;
                }
            }

            function discardGroupDraft() {
                if (titleInput) titleInput.value = '';
                if (descriptionInput) descriptionInput.value = '';
                pendingPayload = null;
                syncCreateButton();
            }

            function openCreateModal() {
                if (!canSave || !titleInput || !String(titleInput.value || '').trim()) return;
                const title = String(titleInput.value || '').trim();
                const description = String(descriptionInput ? descriptionInput.value : '').trim();
                pendingPayload = {create: [{title, description}], delete: []};
                window.RightsManagementSave.openModal(modal, modalChanges, `
                    <p><strong>__rmLabel:addGroup__</strong></p>
                    <ul><li>${escapeHtml(title)}${description ? ' - ' + escapeHtml(description) : ''}</li></ul>`);
            }

            function openDeleteModal(button) {
                if (!canSave || !button) return;
                const uid = Number(button.dataset.uid || 0);
                const title = window.RightsManagementSave.safeLabel(button.dataset.title) || ('UID ' + uid);
                if (!uid) return;
                pendingPayload = {create: [], delete: [uid]};
                window.RightsManagementSave.openModal(modal, modalChanges, `
                    <p><strong>__rmLabel:delete__</strong></p>
                    <ul><li>${escapeHtml(title)} [${uid}]</li></ul>`);
            }

            function isTruthy(value) {
                const normalized = String(value || '').toLowerCase();
                return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
            }

            function escapeHtml(value) {
                return window.RightsManagementSave.escapeHtml(value);
            }
        })();

// Template: GroupRightsInheritanceManagement.html #0
(() => {
            const app = document.querySelector('#rm-inheritance-app');
            if (!app) return;

            const i18nNode = document.querySelector('[data-role="i18n"]');
            const canSave = isTruthy(app.dataset.canSave);
            const sourceList = app.querySelector('[data-role="source-list"]');
            const sourceSearch = app.querySelector('[data-role="source-search"]');
            const inheritSearch = app.querySelector('[data-role="inherit-search"]');
            const selectedList = app.querySelector('[data-role="selected-list"]');
            const availableList = app.querySelector('[data-role="available-list"]');
            const selectedCount = app.querySelector('[data-role="selected-count"]');
            const availableCount = app.querySelector('[data-role="available-count"]');
            const targetTitle = app.querySelector('[data-role="target-title"]');
            const changeSummary = app.querySelector('[data-role="change-summary"]');
            const saveButton = app.querySelector('[data-role="save-draft"]');
            const discardButton = app.querySelector('[data-role="discard-draft"]');
            const modal = document.querySelector('[data-role="confirm-modal"]');
            const modalChanges = modal ? modal.querySelector('[data-role="modal-changes"]') : null;
            const modalDiscard = modal ? modal.querySelector('[data-role="modal-discard"]') : null;
            const modalSave = modal ? modal.querySelector('[data-role="modal-save"]') : null;
            const allGroups = Array.from(app.querySelectorAll('[data-role="source-group"]')).map((node) => ({
                node,
                uid: Number(node.dataset.uid || 0),
                title: window.RightsManagementSave.safeLabel(node.dataset.title),
                description: window.RightsManagementSave.safeLabel(node.dataset.description),
                assignable: isTruthy(node.dataset.assignable),
                editable: isTruthy(node.dataset.editable),
                inherited: parseIds(node.dataset.inherited || ''),
            }));
            const requestedSelectedUid = Number(app.dataset.selectedGroup || 0);
            const requestedGroup = allGroups.find((group) => group.uid === requestedSelectedUid && group.editable);
            const firstEditableGroup = allGroups.find((group) => group.editable) || allGroups[0];
            const state = {
                selectedUid: requestedGroup ? requestedGroup.uid : (firstEditableGroup ? firstEditableGroup.uid : 0),
                sourceSearch: '',
                inheritSearch: '',
                drafts: new Map(),
            };
            const fallbackLabels = {
                de: {add: 'Hinzufügen', available: 'Verfügbar', inherited: 'Geerbt', noChanges: 'Keine Änderungen', noMoreGroups: 'Keine weiteren Gruppen', noPermission: 'Keine Berechtigung', none: 'Keine', remove: 'Entfernen', selectedGroup: 'Ausgewählte Gruppe', warningDraftNotPersisted: 'Änderungen vor dem Speichern prüfen.'},
                en: {add: 'Add', available: 'Available', inherited: 'Inherited', noChanges: 'No changes', noMoreGroups: 'No more groups', noPermission: 'No permission', none: 'None', remove: 'Remove', selectedGroup: 'Selected group', warningDraftNotPersisted: 'Review the changes before saving.'},
            };

            bindEvents();
            render();

            function bindEvents() {
                if (sourceSearch) sourceSearch.addEventListener('input', () => {
                    state.sourceSearch = sourceSearch.value;
                    renderSourceList();
                });
                if (inheritSearch) inheritSearch.addEventListener('input', () => {
                    state.inheritSearch = inheritSearch.value;
                    renderBuckets();
                });
                if (sourceList) sourceList.addEventListener('click', (event) => {
                    const item = event.target.closest('[data-role="source-group"]');
                    if (!item) return;
                    if (!isTruthy(item.dataset.editable)) return;
                    state.selectedUid = Number(item.dataset.uid || 0);
                    render();
                });
                [selectedList, availableList].forEach((list) => {
                    if (!list) return;
                    list.addEventListener('change', (event) => {
                        const checkbox = event.target.closest('input[type="checkbox"][data-uid]');
                        if (!checkbox || checkbox.disabled) return;
                        setInherited(Number(checkbox.dataset.uid || 0), checkbox.checked);
                    });
                });
                if (saveButton) saveButton.addEventListener('click', openModal);
                if (discardButton) discardButton.addEventListener('click', () => {
                    state.drafts.delete(state.selectedUid);
                    renderBuckets();
                });
                if (modalDiscard) modalDiscard.addEventListener('click', () => {
                    closeModal();
                });
                if (modalSave) modalSave.addEventListener('click', submitDraft);
            }
            function render() {
                renderSourceList();
                renderBuckets();
                translateDom(app);
                translateDom(modal);
            }
            function renderSourceList() {
                const needle = normalize(state.sourceSearch);
                allGroups.forEach((group) => {
                    const visible = !needle || normalize(`${group.title} ${group.description} ${group.uid}`).includes(needle);
                    group.node.hidden = !visible;
                    group.node.classList.toggle('is-active', group.uid === state.selectedUid);
                    group.node.classList.toggle('is-disabled', !group.editable);
                    group.node.disabled = !group.editable;
                    group.node.title = group.editable ? '' : '__rmLabel:noPermission__';
                });
            }
            function renderBuckets() {
                const group = getSelectedGroup();
                if (!group || !selectedList || !availableList) return;
                const inherited = getCurrentInherited();
                const original = new Set(group.inherited);
                const needle = normalize(state.inheritSearch);
                let shownSelected = 0;
                let shownAvailable = 0;
                selectedList.innerHTML = '';
                availableList.innerHTML = '';
                if (targetTitle) targetTitle.textContent = `${t('selectedGroup')}: ${group.title} (UID ${group.uid})`;
                if (!group.editable) {
                    selectedList.innerHTML = '<div class="rm-empty">__rmLabel:noPermission__</div>';
                    availableList.innerHTML = '<div class="rm-empty">__rmLabel:noPermission__</div>';
                    if (selectedCount) selectedCount.textContent = '0';
                    if (availableCount) availableCount.textContent = '0';
                    if (changeSummary) changeSummary.innerHTML = '__rmLabel:noChanges__';
                    if (saveButton) saveButton.disabled = true;
                    if (discardButton) discardButton.disabled = true;
                    translateDom(app);
                    return;
                }
                allGroups.forEach((candidate) => {
                    if (candidate.uid === group.uid) return;
                    if (needle && !normalize(`${candidate.title} ${candidate.description} ${candidate.uid}`).includes(needle)) return;
                    if (inherited.has(candidate.uid)) {
                        selectedList.append(createBucketRow(candidate, true));
                        shownSelected++;
                    } else {
                        availableList.append(createBucketRow(candidate, false));
                        shownAvailable++;
                    }
                });
                if (!selectedList.children.length) selectedList.innerHTML = '<div class="rm-empty">__rmLabel:none__</div>';
                if (!availableList.children.length) availableList.innerHTML = '<div class="rm-empty">__rmLabel:noMoreGroups__</div>';
                if (selectedCount) selectedCount.textContent = String(shownSelected);
                if (availableCount) availableCount.textContent = String(shownAvailable);
                const added = [...inherited].filter((uid) => !original.has(uid));
                const removed = [...original].filter((uid) => !inherited.has(uid));
                const changed = added.length || removed.length;
                if (changeSummary) {
                    changeSummary.innerHTML = changed ? [
                        ...added.map((uid) => `<span class="rm-change">+ ${escapeHtml(label(uid))}</span>`),
                        ...removed.map((uid) => `<span class="rm-change">- ${escapeHtml(label(uid))}</span>`),
                    ].join('') : '__rmLabel:noChanges__';
                }
                if (saveButton) saveButton.disabled = !canSave || !changed;
                if (discardButton) discardButton.disabled = !canSave || !changed;
                translateDom(app);
            }
            function createBucketRow(group, checked) {
                const row = document.createElement('label');
                const disabled = !canSave || !group.assignable;
                row.className = `rm-right-row${checked ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`;
                if (disabled) row.title = '__rmLabel:noPermission__';
                row.innerHTML = `
                    <span class="rm-right-main">
                        <input type="checkbox" data-uid="${group.uid}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                        <span><strong>${escapeHtml(group.title)}</strong><span class="rm-muted">UID ${group.uid}</span></span>
                    </span>
                    ${disabled ? '<span class="rm-badge">__rmLabel:noPermission__</span>' : ''}`;
                return row;
            }
            function setInherited(uid, value) {
                if (!canSave) return;
                const selectedGroup = getSelectedGroup();
                if (!selectedGroup || !selectedGroup.editable) return;
                const candidate = allGroups.find((group) => group.uid === uid);
                if (value && candidate && !candidate.assignable) return;
                const inherited = getCurrentInherited();
                value ? inherited.add(uid) : inherited.delete(uid);
                state.drafts.set(state.selectedUid, inherited);
                renderBuckets();
            }
            function openModal() {
                if (!canSave || !modal || !modalChanges) return;
                const group = getSelectedGroup();
                const inherited = getCurrentInherited();
                const original = new Set(group.inherited);
                const added = [...inherited].filter((uid) => !original.has(uid));
                const removed = [...original].filter((uid) => !inherited.has(uid));
                if (!added.length && !removed.length) return;
                modalChanges.innerHTML = `
                    <p><strong>${escapeHtml(group.title)}</strong> UID ${group.uid}</p>
                    <p>__rmLabel:add__:</p><div>${added.map((uid) => `<span class="rm-change">+ ${escapeHtml(label(uid))}</span>`).join('') || '<span class="rm-muted">__rmLabel:none__</span>'}</div>
                    <p>__rmLabel:remove__:</p><div>${removed.map((uid) => `<span class="rm-change">- ${escapeHtml(label(uid))}</span>`).join('') || '<span class="rm-muted">__rmLabel:none__</span>'}</div>`;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                translateDom(modal);
            }
            function submitDraft() {
                if (!canSave) return;
                const group = getSelectedGroup();
                if (!group) return;
                window.RightsManagementSave.submit('group-rights-inheritance-management', {
                    groupUid: group.uid,
                    inherited: [...getCurrentInherited()],
                });
            }
            function closeModal() {
                if (!modal) return;
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }
            function getSelectedGroup() {
                return allGroups.find((group) => group.uid === state.selectedUid && group.editable) || allGroups.find((group) => group.editable) || allGroups[0];
            }
            function getCurrentInherited() {
                const group = getSelectedGroup();
                return new Set(state.drafts.get(group.uid) || group.inherited);
            }
            function label(uid) {
                const group = allGroups.find((candidate) => candidate.uid === uid);
                return group ? `${group.title} [${group.uid}]` : `UID ${uid}`;
            }
            function parseIds(value) {
                return String(value || '').split(',').map((id) => Number(id.trim())).filter(Boolean);
            }
            function isTruthy(value) {
                const normalized = String(value || '').toLowerCase();
                return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
            }
            function normalize(value) {
                return translate(value).trim().toLowerCase();
            }
            function t(key) {
                const fallback = fallbackLabel(key);
                if (!i18nNode) return fallback;
                const labelNode = i18nNode.querySelector('[data-i18n-key="' + key + '"]');
                let value = '';
                if (labelNode) {
                    value = labelNode.textContent || '';
                } else if (i18nNode.dataset) {
                    value = i18nNode.dataset[key] || '';
                }
                value = String(value || '').trim();
                if (!value || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1 || value.indexOf('{' + 'f:' + 'translate') !== -1 || value.indexOf('LLL:' + 'EXT:') !== -1 || value.indexOf('common.') === 0) {
                    return fallback;
                }
                return value;
            }
            function fallbackLabel(key) {
                const language = String(document.documentElement.lang || navigator.language || '').toLowerCase();
                const labels = language.indexOf('de') === 0 ? fallbackLabels.de : fallbackLabels.en;
                return labels[key] || fallbackLabels.en[key] || key;
            }
            function translate(value) {
                const rawFluidTranslate = new RegExp("\\{" + "f:" + "translate\\(key:\\s*'[^']+:common\\.([A-Za-z0-9_.-]+)'\\)\\}", 'g');
                return String(value || '')
                    .replace(/__rmLabel:([A-Za-z0-9_.-]+)__/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{labels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{uiLabels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    })
                    .replace(rawFluidTranslate, function (match, key) {
                        return t(key);
                    });
            }
            function translateDom(rootNode) {
                if (!rootNode) return;
                const walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT);
                const textNodes = [];
                while (walker.nextNode()) textNodes.push(walker.currentNode);
                for (let i = 0; i < textNodes.length; i++) {
                    if (textNodes[i].nodeValue.indexOf('__rmLabel:') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'labels.') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'uiLabels.') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'f:' + 'translate') !== -1) {
                        textNodes[i].nodeValue = translate(textNodes[i].nodeValue);
                    }
                }
                const attrNodes = rootNode.querySelectorAll('[title], [aria-label], [placeholder]');
                for (let i = 0; i < attrNodes.length; i++) {
                    const attrs = ['title', 'aria-label', 'placeholder'];
                    for (let j = 0; j < attrs.length; j++) {
                        const attr = attrs[j];
                        const value = attrNodes[i].getAttribute(attr);
                        if (value && (value.indexOf('__rmLabel:') !== -1 || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1 || value.indexOf('{' + 'f:' + 'translate') !== -1)) {
                            attrNodes[i].setAttribute(attr, translate(value));
                        }
                    }
                }
            }
            function escapeHtml(value) {
                return String(translate(value)).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
            }
        })();

// Template: GroupRightsManagement.html #0
(() => {
            const input = document.querySelector('[data-role="group-selection-search"]');
            if (!input) return;
            const options = [...document.querySelectorAll('[data-role="group-selection-option"]')];
            input.addEventListener('input', () => {
                const needle = normalize(input.value);
                for (const option of options) {
                    const searchable = `${option.textContent || ''} ${option.dataset.title || ''} ${option.dataset.description || ''} ${option.dataset.uid || ''}`;
                    option.hidden = needle !== '' && !normalize(searchable).includes(needle);
                }
            });

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
        (() => {
            bindMatrixSearch('[data-role="record-matrix-search"]');
            bindMatrixSearch('[data-role="table-matrix-search"]');

            function bindMatrixSearch(inputSelector) {
                const input = document.querySelector(inputSelector);
                if (!input) return;
                const table = findMatrixForInput(input);
                if (!table) return;
                const rows = [...table.querySelectorAll('tbody tr')];
                const applyFilter = () => {
                    const needle = normalize(input.value);
                    for (const row of rows) {
                        row.hidden = needle !== '' && !normalize(rowSearchText(row)).includes(needle);
                    }
                };
                input.addEventListener('input', applyFilter);
                input.addEventListener('search', applyFilter);
                input.addEventListener('change', applyFilter);
                applyFilter();
            }

            function findMatrixForInput(input) {
                let sibling = input.closest('.rm-selector-tools');
                sibling = sibling ? sibling.nextElementSibling : null;
                while (sibling) {
                    if (sibling.classList && sibling.classList.contains('rm-table-wrap')) {
                        return sibling.querySelector('.rm-matrix');
                    }
                    if (sibling.matches && sibling.matches('.rm-matrix')) {
                        return sibling;
                    }
                    if (!sibling.classList || (!sibling.classList.contains('rm-scroll-x') && !sibling.classList.contains('rm-sticky-head'))) {
                        break;
                    }
                    sibling = sibling.nextElementSibling;
                }
                const body = input.closest('.rm-card__body');
                return body ? body.querySelector('.rm-table-wrap .rm-matrix, .rm-matrix') : null;
            }

            function rowSearchText(row) {
                const parts = [row.textContent || ''];
                for (const cell of row.querySelectorAll('[data-title], [title]')) {
                    parts.push(cell.dataset.title || '');
                    parts.push(cell.getAttribute('title') || '');
                }
                for (const input of row.querySelectorAll('input')) {
                    const label = input.closest('label');
                    parts.push(input.checked ? 'checked active selected yes' : 'unchecked inactive no');
                    parts.push(input.value || '');
                    if (label) {
                        parts.push(label.textContent || '');
                        parts.push(label.getAttribute('title') || '');
                    }
                }
                return parts.join(' ');
            }

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
        document.querySelectorAll('.rm-matrix').forEach((table) => {
            const clearColumnHover = () => table.querySelectorAll('.is-col-hover').forEach((item) => item.classList.remove('is-col-hover'));
            table.addEventListener('mouseover', (event) => {
                const cell = event.target.closest('[data-group][data-kind]');
                if (!cell) return;
                clearColumnHover();
                table.querySelectorAll(`[data-group="${cell.dataset.group}"][data-kind="${cell.dataset.kind}"]`).forEach((item) => item.classList.add('is-col-hover'));
            });
            table.addEventListener('mouseleave', clearColumnHover);
        });

// Template: ModuleManagement.html #0
(() => {
            const input = document.querySelector('[data-role="group-selection-search"]');
            if (!input) return;
            const options = [...document.querySelectorAll('[data-role="group-selection-option"]')];
            input.addEventListener('input', () => {
                const needle = normalize(input.value);
                for (const option of options) {
                    const searchable = `${option.textContent || ''} ${option.dataset.title || ''} ${option.dataset.description || ''} ${option.dataset.uid || ''}`;
                    option.hidden = needle !== '' && !normalize(searchable).includes(needle);
                }
            });

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
        (() => {
            const input = document.querySelector('[data-role="module-matrix-search"]');
            if (!input) return;
            const table = input.closest('.rm-card__body').querySelector('.rm-matrix');
            const rows = table ? [...table.querySelectorAll('tbody tr')] : [];
            input.addEventListener('input', () => {
                const needle = normalize(input.value);
                for (const row of rows) {
                    row.hidden = needle !== '' && !normalize(rowSearchText(row)).includes(needle);
                }
            });

            function rowSearchText(row) {
                const parts = [row.textContent || ''];
                for (const cell of row.querySelectorAll('[data-title], [title]')) {
                    parts.push(cell.dataset.title || '');
                    parts.push(cell.getAttribute('title') || '');
                }
                for (const input of row.querySelectorAll('input')) {
                    parts.push(input.checked ? 'checked active selected yes' : 'unchecked inactive no');
                }
                return parts.join(' ');
            }

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
        document.querySelectorAll('.rm-matrix').forEach((table) => {
            const clearColumnHover = () => table.querySelectorAll('.is-col-hover').forEach((item) => item.classList.remove('is-col-hover'));
            table.addEventListener('mouseover', (event) => {
                const cell = event.target.closest('[data-group][data-kind]');
                if (!cell) return;
                clearColumnHover();
                table.querySelectorAll(`[data-group="${cell.dataset.group}"][data-kind="${cell.dataset.kind}"]`).forEach((item) => item.classList.add('is-col-hover'));
            });
            table.addEventListener('mouseleave', clearColumnHover);
        });

// Template: MountManagement.html #0
(() => {
            const app = document.querySelector('#rm-mount-app');
            if (!app) return;

            const i18nNode = document.querySelector('[data-role="i18n"]');
            const hasFileMountFeature = isTruthy(app.dataset.hasFileMountFeature);
            const canSave = isTruthy(app.dataset.canSave);
            const groupList = app.querySelector('[data-role="group-list"]');
            const groupSearch = app.querySelector('[data-role="group-search"]');
            const tabs = Array.from(app.querySelectorAll('[data-role="mount-tab"]'));
            const dbTools = app.querySelector('[data-role="db-tools"]');
            const fileTools = app.querySelector('[data-role="file-tools"]');
            const pageSearch = app.querySelector('[data-role="page-search"]');
            const fileSearch = app.querySelector('[data-role="file-search"]');
            const dbPanel = app.querySelector('[data-role="db-panel"]');
            const filePanel = app.querySelector('[data-role="file-panel"]');
            const selectedTitle = app.querySelector('[data-role="selected-title"]');
            const selectedSummary = app.querySelector('[data-role="selected-summary"]');
            const changeSummary = app.querySelector('[data-role="change-summary"]');
            const saveButton = app.querySelector('[data-role="save-draft"]');
            const discardButton = app.querySelector('[data-role="discard-draft"]');
            const modal = document.querySelector('[data-role="confirm-modal"]');
            const modalChanges = modal ? modal.querySelector('[data-role="modal-changes"]') : null;
            const modalDiscard = modal ? modal.querySelector('[data-role="modal-discard"]') : null;
            const modalSave = modal ? modal.querySelector('[data-role="modal-save"]') : null;
            const groups = Array.from(app.querySelectorAll('[data-role="group-item"]')).map((node) => ({
                node,
                uid: Number(node.dataset.uid || 0),
                title: window.RightsManagementSave.safeLabel(node.dataset.title),
                description: window.RightsManagementSave.safeLabel(node.dataset.description),
                assignable: isTruthy(node.dataset.assignable),
                editable: isTruthy(node.dataset.editable),
                subgroups: parseIds(node.dataset.subgroups || ''),
                dbMounts: parseIds(node.dataset.dbMounts || ''),
                fileMounts: parseIds(node.dataset.fileMounts || ''),
            }));
            const groupMap = new Map(groups.map((group) => [group.uid, group]));
            const pages = Array.from(app.querySelectorAll('[data-role="page-data"]')).map((node) => ({
                uid: Number(node.dataset.uid || 0),
                pid: Number(node.dataset.pid || 0),
                sorting: Number(node.dataset.sorting || 0),
                label: window.RightsManagementSave.safeLabel(node.dataset.label),
                meta: window.RightsManagementSave.safeLabel(node.dataset.meta),
                disabled: isTruthy(node.dataset.disabled),
                assignable: isTruthy(node.dataset.assignable),
            }));
            const fileMounts = Array.from(app.querySelectorAll('[data-role="file-data"]')).map((node) => ({
                uid: Number(node.dataset.uid || 0),
                label: window.RightsManagementSave.safeLabel(node.dataset.label),
                meta: window.RightsManagementSave.safeLabel(node.dataset.meta),
                disabled: isTruthy(node.dataset.disabled),
                assignable: isTruthy(node.dataset.assignable),
                readOnly: isTruthy(node.dataset.readOnly),
            }));
            const state = {
                selectedUid: (groups.find((group) => group.editable) || groups[0] || {uid: 0}).uid,
                activeTab: 'db',
                groupSearch: '',
                pageSearch: '',
                fileSearch: '',
                drafts: new Map(),
            };

            bindEvents();

            function bindEvents() {
                if (groupSearch) {
                    groupSearch.addEventListener('input', () => {
                        state.groupSearch = groupSearch.value;
                        renderGroupList();
                    });
                }
                if (groupList) {
                    groupList.addEventListener('click', (event) => {
                        const item = event.target.closest('[data-role="group-item"]');
                        if (!item) return;
                        if (!isTruthy(item.dataset.editable)) return;
                        state.selectedUid = Number(item.dataset.uid || 0);
                        render();
                    });
                }
                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => {
                        state.activeTab = tab.dataset.tab || 'db';
                        render();
                    });
                });
                if (pageSearch) {
                    pageSearch.addEventListener('input', () => {
                        state.pageSearch = pageSearch.value;
                        renderPanels();
                    });
                }
                if (fileSearch) {
                    fileSearch.addEventListener('input', () => {
                        state.fileSearch = fileSearch.value;
                        renderPanels();
                    });
                }
                bindMountPanel(dbPanel);
                bindMountPanel(filePanel);
                if (saveButton) saveButton.addEventListener('click', openModal);
                if (discardButton) discardButton.addEventListener('click', () => {
                    state.drafts.clear();
                    render();
                });
                if (modalDiscard) modalDiscard.addEventListener('click', () => {
                    closeModal();
                });
                if (modalSave) modalSave.addEventListener('click', submitDraft);
            }
            function bindMountPanel(panel) {
                if (!panel) return;
                panel.addEventListener('change', (event) => {
                    const checkbox = event.target.closest('input[type="checkbox"][data-kind]');
                    if (!checkbox || checkbox.disabled) return;
                    setMount(checkbox.dataset.kind, Number(checkbox.dataset.uid || 0), checkbox.checked);
                });
            }
            function render() {
                if (!hasFileMountFeature && state.activeTab === 'files') state.activeTab = 'db';
                renderFeatureGates();
                renderGroupList();
                renderPanels();
                translateDom(app);
            }
            function renderFeatureGates() {
                app.querySelectorAll('[data-requires-feature="file-mounts"]').forEach((node) => {
                    node.hidden = !hasFileMountFeature;
                });
            }
            function renderGroupList() {
                const needle = normalize(state.groupSearch);
                groups.forEach((group) => {
                    const context = buildGroupContext(group);
                    const search = `${group.title} ${group.description} ${group.uid} ${group.subgroups.join(' ')} ${[...combinedMountSet(context, 'db')].join(' ')} ${[...combinedMountSet(context, 'files')].join(' ')}`;
                    const summary = group.node.querySelector('[data-role="group-mount-summary"]');
                    if (summary) summary.textContent = formatCompactMountSummary(context);
                    const visible = !needle || normalize(search).includes(needle);
                    group.node.hidden = !visible;
                    group.node.classList.toggle('is-active', group.uid === state.selectedUid);
                    group.node.classList.toggle('is-disabled', !group.editable);
                    group.node.disabled = !group.editable;
                    group.node.title = group.editable ? '' : '__rmLabel:noPermission__';
                });
            }
            function renderPanels() {
                const group = getSelectedGroup();
                const context = group ? buildGroupContext(group) : null;
                const activeFiles = state.activeTab === 'files' && hasFileMountFeature;
                if (selectedTitle) selectedTitle.textContent = group ? `${group.title} (UID ${group.uid})` : t('selectedGroup');
                if (selectedSummary) selectedSummary.textContent = context ? formatMountSummary(context) : t('dbMountsAndDirectoryMounts');
                tabs.forEach((tab) => {
                    const active = tab.dataset.tab === state.activeTab;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                if (dbTools) dbTools.hidden = activeFiles;
                if (fileTools) fileTools.hidden = !activeFiles;
                if (dbPanel) dbPanel.hidden = activeFiles;
                if (filePanel) filePanel.hidden = !activeFiles;
                if (activeFiles) renderFileMounts(getMountSet('files'), context);
                else renderPages(getMountSet('db'), context);
                renderChanges();
                translateDom(app);
            }
            function renderPages(dbMounts, context) {
                dbPanel.innerHTML = '';
                const needle = normalize(state.pageSearch);
                const pageMap = new Map(pages.map((page) => [page.uid, page]));
                const children = new Map();
                for (const page of pages) {
                    const pid = pageMap.has(page.pid) ? page.pid : 0;
                    if (!children.has(pid)) children.set(pid, []);
                    children.get(pid).push(page);
                }
                for (const list of children.values()) {
                    list.sort((a, b) => a.sorting - b.sorting || a.label.localeCompare(b.label));
                }
                const fragment = document.createDocumentFragment();
                let renderedRows = 0;
                for (const page of children.get(0) || []) renderPageNode(page, 0, fragment);
                if (!renderedRows) {
                    dbPanel.innerHTML = '<div class="rm-empty">__rmLabel:noPagesFound__</div>';
                    return;
                }
                dbPanel.append(createMountHeader());
                dbPanel.append(fragment);

                function renderPageNode(page, depth, target) {
                    const childItems = children.get(page.uid) || [];
                    const ownMatch = !needle || normalize(`${page.label} ${page.meta} ${page.uid} ${page.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:databaseMounts__ __rmLabel:pageTree__`).includes(needle);
                    const childMatches = childItems.some((child) => hasVisiblePage(child));
                    if (needle && !ownMatch && !childMatches) return;
                    target.append(createPageRow(page, depth, dbMounts.has(page.uid), context ? context.inheritedDb.has(page.uid) : false, context ? context.group.editable : false));
                    renderedRows++;
                    for (const child of childItems) renderPageNode(child, depth + 1, target);
                }
                function hasVisiblePage(page) {
                    if (normalize(`${page.label} ${page.meta} ${page.uid} ${page.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:databaseMounts__ __rmLabel:pageTree__`).includes(needle)) return true;
                    return (children.get(page.uid) || []).some((child) => hasVisiblePage(child));
                }
            }
            function createPageRow(page, depth, selected, inherited, groupAssignable) {
                const available = selected || inherited;
                const editable = canSave && groupAssignable && page.assignable;
                const row = document.createElement('label');
                row.className = `rm-tree-row${available ? ' is-active' : ''}${inherited && !selected ? ' is-inherited' : ''}${page.disabled || !page.assignable ? ' is-disabled' : ''}`;
                if (!page.assignable) row.title = '__rmLabel:noPermission__';
                row.style.setProperty('--depth', String(depth));
                row.innerHTML = `
                    <span class="rm-assignment-checks">
                        <input type="checkbox" data-kind="db" data-uid="${page.uid}" title="${page.assignable ? '__rmLabel:directAssignmentEdit__' : '__rmLabel:noPermission__'}" ${selected ? 'checked' : ''} ${editable ? '' : 'disabled'}>
                        <input type="checkbox" disabled title="__rmLabel:viaGroups__" ${inherited ? 'checked' : ''}>
                    </span>
                    <span class="rm-row-main">
                        <strong>${escapeHtml(page.label || '__rmLabel:noTitle__')}</strong>
                        <span class="rm-muted">${escapeHtml(page.meta)}</span>${inherited ? '<span class="rm-badge">__rmLabel:viaGroups__</span>' : ''}${page.disabled ? '<span class="rm-badge">__rmLabel:hidden__</span>' : ''}
                    </span>`;
                return row;
            }
            function renderFileMounts(selectedFiles, context) {
                filePanel.innerHTML = '';
                const needle = normalize(state.fileSearch);
                const inheritedFiles = context ? context.inheritedFiles : new Set();
                const sorted = [...fileMounts].sort((a, b) => Number((selectedFiles.has(b.uid) || inheritedFiles.has(b.uid))) - Number((selectedFiles.has(a.uid) || inheritedFiles.has(a.uid))) || a.label.localeCompare(b.label));
                let renderedRows = 0;
                for (const mount of sorted) {
                    if (needle && !normalize(`${mount.label} ${mount.meta} ${mount.uid} ${mount.readOnly ? '__rmLabel:readOnly__' : ''} ${mount.disabled ? '__rmLabel:hidden__' : ''} __rmLabel:directoryMounts__ __rmLabel:file__`).includes(needle)) continue;
                    if (!renderedRows) filePanel.append(createMountHeader());
                    filePanel.append(createFileRow(mount, selectedFiles.has(mount.uid), inheritedFiles.has(mount.uid), context ? context.group.editable : false));
                    renderedRows++;
                }
                if (!renderedRows) filePanel.innerHTML = '<div class="rm-empty">__rmLabel:noFileMountsFound__</div>';
            }
            function createFileRow(mount, selected, inherited, groupAssignable) {
                const available = selected || inherited;
                const editable = canSave && groupAssignable && mount.assignable;
                const row = document.createElement('label');
                row.className = `rm-check-row${available ? ' is-active' : ''}${inherited && !selected ? ' is-inherited' : ''}${mount.disabled || !mount.assignable ? ' is-disabled' : ''}`;
                if (!mount.assignable) row.title = '__rmLabel:noPermission__';
                row.innerHTML = `
                    <span class="rm-assignment-checks">
                        <input type="checkbox" data-kind="files" data-uid="${mount.uid}" title="${mount.assignable ? '__rmLabel:directAssignmentEdit__' : '__rmLabel:noPermission__'}" ${selected ? 'checked' : ''} ${editable ? '' : 'disabled'}>
                        <input type="checkbox" disabled title="__rmLabel:viaGroups__" ${inherited ? 'checked' : ''}>
                    </span>
                    <span class="rm-row-main">
                        <strong>${escapeHtml(mount.label || '__rmLabel:noTitle__')}</strong>${inherited ? '<span class="rm-badge">__rmLabel:viaGroups__</span>' : ''}
                        <span class="rm-muted">${escapeHtml(mount.meta || '__rmLabel:noPath__')}</span>${mount.readOnly ? '<span class="rm-badge">__rmLabel:readOnly__</span>' : ''}${mount.disabled ? '<span class="rm-badge">__rmLabel:hidden__</span>' : ''}
                    </span>`;
                return row;
            }
            function createMountHeader() {
                const row = document.createElement('div');
                row.className = 'rm-assignment-head';
                row.innerHTML = '<span class="rm-assignment-legend">__rmLabel:direct__ / __rmLabel:viaGroups__</span>';
                return row;
            }
            function setMount(type, uid, value) {
                if (!canSave) return;
                const group = getSelectedGroup();
                if (!group) return;
                const draft = getDraft(group);
                const mounts = new Set(draft[type]);
                value ? mounts.add(uid) : mounts.delete(uid);
                draft[type] = mounts;
                state.drafts.set(group.uid, draft);
                renderGroupList();
                renderPanels();
            }
            function renderChanges() {
                const group = getSelectedGroup();
                if (!group) return;
                const db = getMountSet('db');
                const files = hasFileMountFeature ? getMountSet('files') : new Set();
                const originalDb = new Set(group.dbMounts);
                const originalFiles = hasFileMountFeature ? new Set(group.fileMounts) : new Set();
                const addedDb = [...db].filter((uid) => !originalDb.has(uid));
                const removedDb = [...originalDb].filter((uid) => !db.has(uid));
                const addedFiles = hasFileMountFeature ? [...files].filter((uid) => !originalFiles.has(uid)) : [];
                const removedFiles = hasFileMountFeature ? [...originalFiles].filter((uid) => !files.has(uid)) : [];
                const changed = addedDb.length || removedDb.length || addedFiles.length || removedFiles.length;
                changeSummary.innerHTML = changed ? [
                    ...addedDb.map((uid) => `<span class="rm-change">+ DB ${escapeHtml(pageLabel(uid))}</span>`),
                    ...removedDb.map((uid) => `<span class="rm-change">- DB ${escapeHtml(pageLabel(uid))}</span>`),
                    ...addedFiles.map((uid) => `<span class="rm-change">+ __rmLabel:file__ ${escapeHtml(fileLabel(uid))}</span>`),
                    ...removedFiles.map((uid) => `<span class="rm-change">- __rmLabel:file__ ${escapeHtml(fileLabel(uid))}</span>`),
                ].join('') : '__rmLabel:noChanges__';
                saveButton.disabled = !canSave || !changed;
                discardButton.disabled = !canSave || !changed;
            }
            function openModal() {
                if (!canSave) return;
                const group = getSelectedGroup();
                if (!group) return;
                const changes = buildChangeList(group);
                if (!changes.length) return;
                modalChanges.innerHTML = `
                    <p><strong>${escapeHtml(group.title)}</strong> UID ${group.uid}</p>
                    <ul>${changes.map((change) => `<li>${change}</li>`).join('')}</ul>`;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                translateDom(modal);
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }
            function getSelectedGroup() {
                return groups.find((group) => group.uid === state.selectedUid && group.editable) || groups.find((group) => group.editable) || groups[0];
            }
            function submitDraft() {
                if (!canSave) return;
                window.RightsManagementSave.submit('mount-management', buildPayload());
            }
            function buildPayload() {
                return {
                    groups: groups.map((group) => {
                        const draft = getDraft(group);
                        return {
                            uid: group.uid,
                            dbMounts: [...draft.db],
                            fileMounts: [...draft.files],
                        };
                    }).filter((item) => {
                        const group = groupMap.get(item.uid);
                        return group && (hasListChanged(group.dbMounts, item.dbMounts) || hasListChanged(group.fileMounts, item.fileMounts));
                    }),
                };
            }
            function buildChangeList(group) {
                const db = getMountSet('db');
                const files = hasFileMountFeature ? getMountSet('files') : new Set();
                const originalDb = new Set(group.dbMounts);
                const originalFiles = hasFileMountFeature ? new Set(group.fileMounts) : new Set();
                return [
                    ...[...db].filter((uid) => !originalDb.has(uid)).map((uid) => `+ DB ${escapeHtml(pageLabel(uid))}`),
                    ...[...originalDb].filter((uid) => !db.has(uid)).map((uid) => `- DB ${escapeHtml(pageLabel(uid))}`),
                    ...[...files].filter((uid) => !originalFiles.has(uid)).map((uid) => `+ __rmLabel:file__ ${escapeHtml(fileLabel(uid))}`),
                    ...[...originalFiles].filter((uid) => !files.has(uid)).map((uid) => `- __rmLabel:file__ ${escapeHtml(fileLabel(uid))}`),
                ];
            }
            function hasListChanged(left, right) {
                const leftValues = left.map(String).sort();
                const rightValues = right.map(String).sort();
                return leftValues.length !== rightValues.length || leftValues.some((value, index) => value !== rightValues[index]);
            }
            function getDraft(group) {
                const draft = state.drafts.get(group.uid);
                return {
                    db: new Set(draft && draft.db ? draft.db : group.dbMounts),
                    files: new Set(draft && draft.files ? draft.files : group.fileMounts),
                };
            }
            function getMountSet(type) {
                const group = getSelectedGroup();
                return group ? getDraft(group)[type] : new Set();
            }
            function buildGroupContext(group) {
                const direct = getDraft(group);
                const inheritedMap = new Map();
                collectInheritedGroups(group, new Set([group.uid]), inheritedMap);
                const inheritedGroups = [...inheritedMap.values()];
                return {
                    group,
                    direct,
                    inheritedGroups,
                    inheritedDb: collectInheritedMounts(inheritedGroups, 'db'),
                    inheritedFiles: collectInheritedMounts(inheritedGroups, 'files'),
                };
            }
            function collectInheritedGroups(group, visited, inheritedMap) {
                for (const subgroupId of group.subgroups) {
                    const uid = Number(subgroupId);
                    if (visited.has(uid)) continue;
                    const inheritedGroup = groupMap.get(uid);
                    if (!inheritedGroup) continue;
                    visited.add(uid);
                    inheritedMap.set(uid, inheritedGroup);
                    collectInheritedGroups(inheritedGroup, visited, inheritedMap);
                }
            }
            function collectInheritedMounts(inheritedGroups, type) {
                const values = new Set();
                for (const group of inheritedGroups) {
                    for (const uid of getDraft(group)[type]) values.add(uid);
                }
                return values;
            }
            function combinedMountSet(context, type) {
                const inherited = type === 'db' ? context.inheritedDb : context.inheritedFiles;
                return new Set([...context.direct[type], ...inherited]);
            }
            function formatMountSummary(context) {
                return `${formatMountCount(context, 'db', t('databaseMounts'))} / ${hasFileMountFeature ? formatMountCount(context, 'files', t('directoryMounts')) : `0 ${t('directoryMounts')}`}`;
            }
            function formatCompactMountSummary(context) {
                return `${formatMountCount(context, 'db', 'DB')} / ${formatMountCount(context, 'files', t('file'))}`;
            }
            function formatMountCount(context, type, label) {
                const direct = context.direct[type].size;
                const combined = combinedMountSet(context, type).size;
                return direct === combined ? `${direct} ${label}` : `${direct}/${combined} ${label}`;
            }
            function pageLabel(uid) {
                const page = pages.find((candidate) => candidate.uid === uid);
                return page ? `${page.label} [${page.meta}]` : `Page ID ${uid}`;
            }
            function fileLabel(uid) {
                const mount = fileMounts.find((candidate) => candidate.uid === uid);
                return mount ? `${mount.label} [${mount.uid}]` : `sys_filemounts_${uid}`;
            }
            function isTruthy(value) {
                const normalized = String(value || '').toLowerCase();
                return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
            }
            function parseIds(value) {
                return String(value || '').split(',').map((id) => Number(id.trim())).filter(Boolean);
            }
            function t(key) {
                if (!i18nNode) return key;
                const labelNode = i18nNode.querySelector('[data-i18n-key="' + key + '"]');
                let value = '';
                if (labelNode) {
                    value = labelNode.textContent || '';
                } else if (i18nNode.dataset) {
                    value = i18nNode.dataset[key] || '';
                }
                value = String(value || '').trim();
                if (!value || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1 || value.indexOf('{' + 'f:' + 'translate') !== -1 || value.indexOf('LLL:' + 'EXT:') !== -1 || value.indexOf('common.') === 0) {
                    return key;
                }
                return value;
            }
            function translate(value) {
                return String(value || '')
                    .replace(/__rmLabel:([A-Za-z0-9_.-]+)__/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{labels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    })
                    .replace(/\{uiLabels\.([A-Za-z0-9_.-]+)\}/g, function (match, key) {
                        return t(key);
                    });
            }
            function translateDom(rootNode) {
                if (!rootNode) return;
                const walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT);
                const textNodes = [];
                while (walker.nextNode()) textNodes.push(walker.currentNode);
                for (let i = 0; i < textNodes.length; i++) {
                    if (textNodes[i].nodeValue.indexOf('__rmLabel:') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'labels.') !== -1 || textNodes[i].nodeValue.indexOf('{' + 'uiLabels.') !== -1) {
                        textNodes[i].nodeValue = translate(textNodes[i].nodeValue);
                    }
                }
                const attrNodes = rootNode.querySelectorAll('[title], [aria-label], [placeholder]');
                for (let i = 0; i < attrNodes.length; i++) {
                    const attrs = ['title', 'aria-label', 'placeholder'];
                    for (let j = 0; j < attrs.length; j++) {
                        const attr = attrs[j];
                        const value = attrNodes[i].getAttribute(attr);
                        if (value && (value.indexOf('__rmLabel:') !== -1 || value.indexOf('{' + 'labels.') !== -1 || value.indexOf('{' + 'uiLabels.') !== -1)) {
                            attrNodes[i].setAttribute(attr, translate(value));
                        }
                    }
                }
            }
            function normalize(value) {
                return translate(value).trim().toLowerCase();
            }
            function escapeHtml(value) {
                return String(translate(value)).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
            }
            render();
        })();

// Template: Overview.html #0
(() => {
            bindOptionSearch('[data-role="overview-group-search"]', '[data-role="overview-group-option"]', (option) => `${option.textContent || ''} ${option.dataset.title || ''} ${option.dataset.description || ''} ${option.dataset.uid || ''}`);
            bindOptionSearch('[data-role="overview-user-search"]', '[data-role="overview-user-option"]', (option) => `${option.textContent || ''} ${option.dataset.title || ''} ${option.dataset.realName || ''} ${option.dataset.uid || ''}`);
            bindOverviewMatrixSearch();

            function bindOptionSearch(inputSelector, optionSelector, getSearchableText) {
                const input = document.querySelector(inputSelector);
                if (!input) return;
                const options = [...document.querySelectorAll(optionSelector)];
                input.addEventListener('input', () => {
                    const needle = normalize(input.value);
                    for (const option of options) {
                        option.hidden = needle !== '' && !normalize(getSearchableText(option)).includes(needle);
                    }
                });
                for (const option of options) {
                    option.hidden = false;
                }
            }

            function bindOverviewMatrixSearch() {
                const input = document.querySelector('[data-role="overview-matrix-search"]');
                const table = document.querySelector('.rm-table-wrap table.rm-overview');
                if (!table) return;
                const showActive = table.querySelector('[data-role="overview-show-active"]');
                const showInactive = table.querySelector('[data-role="overview-show-inactive"]');
                const rows = [...table.querySelectorAll('[data-role="overview-result-row"]')];
                const sections = [...table.querySelectorAll('[data-role="overview-section-row"]')];
                const applyFilters = () => {
                    const needle = normalize(input ? input.value : '');
                    const includeActive = !showActive || showActive.checked;
                    const includeInactive = !showInactive || showInactive.checked;
                    for (const row of rows) {
                        const isActiveRow = row.querySelector('.rm-value.is-active') !== null;
                        const matchesSearch = needle === '' || normalize(rowSearchText(row)).includes(needle);
                        const matchesStatus = isActiveRow ? includeActive : includeInactive;
                        row.hidden = !matchesSearch || !matchesStatus;
                    }
                    for (const section of sections) {
                        const sectionRows = rows.filter((row) => row.dataset.section === section.dataset.section);
                        section.hidden = !sectionRows.some((row) => !row.hidden);
                    }
                };
                if (input) input.addEventListener('input', applyFilters);
                if (showActive) showActive.addEventListener('change', applyFilters);
                if (showInactive) showInactive.addEventListener('change', applyFilters);
                applyFilters();
            }

            function rowSearchText(row) {
                const parts = [row.textContent || ''];
                for (const cell of row.querySelectorAll('[title]')) {
                    parts.push(cell.getAttribute('title') || '');
                }
                return parts.join(' ');
            }

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }
        })();
