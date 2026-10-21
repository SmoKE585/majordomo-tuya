/* Tuya admin UI. Uses the shared Bootstrap, data table and linkedobject controls. */
(function (window, document) {
    'use strict';
    if (window.MDTuyaUI) {
        window.MDTuyaUI.init(document);
        return;
    }

    var socket = null;
    var subscribed = false;
    var socketTimer = 0;

    function feedback(page, type, message) {
        var box = page.querySelector('[data-tuya-feedback]');
        box.className = 'md-alert md-alert--' + type;
        box.hidden = false;
        box.querySelector('[data-tuya-feedback-text]').textContent = message;
        if (window.MDJUIToast) {
            window.MDJUIToast.show({ type: type, title: 'Tuya', text: message });
        }
    }

    async function request(page, op, button) {
        var url = new URL(page.dataset.tuyaApi, window.location.href);
        url.searchParams.set('op', op);
        if (button.dataset.devId) url.searchParams.set('dev_id', button.dataset.devId);
        if (button.dataset.id) url.searchParams.set('id', button.dataset.id);
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 45000);
        try {
            var response = await window.fetch(url, { credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) throw new Error('Ошибка сервера: HTTP ' + response.status + '.');
            return await response.text();
        } finally {
            window.clearTimeout(timeout);
        }
    }

    function responseText(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        parsed.querySelectorAll('br').forEach(function (br) { br.replaceWith('\n'); });
        return parsed.body.textContent.trim();
    }

    function scanResult(container, html) {
        // Only copy text from the legacy response: never insert executable server HTML.
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var rows = parsed.querySelectorAll('table tr');
        container.replaceChildren();
        if (!rows.length) {
            container.textContent = responseText(html) || 'Устройства не найдены. Проверьте, что сервер и устройства находятся в одной сети.';
            return;
        }
        var table = document.createElement('table');
        table.className = 'md-table';
        var head = table.createTHead().insertRow();
        ['Устройство', 'Device ID', 'Протокол', 'IP-адрес'].forEach(function (label) {
            var cell = document.createElement('th');
            cell.scope = 'col';
            cell.textContent = label;
            head.appendChild(cell);
        });
        var body = table.createTBody();
        rows.forEach(function (row) {
            if (!row.querySelector('td')) return;
            var line = body.insertRow();
            row.querySelectorAll('td').forEach(function (cell) {
                line.insertCell().textContent = cell.textContent;
            });
        });
        container.appendChild(table);
        var hint = document.createElement('p');
        hint.className = 'md-field__hint mt-3 mb-0';
        hint.textContent = 'Поиск завершён. Обновите список, чтобы увидеть добавленные устройства.';
        var reload = document.createElement('a');
        reload.className = 'md-btn md-btn--secondary mt-3';
        reload.href = '?data_source=tudevices';
        reload.textContent = 'Обновить список';
        container.append(hint, reload);
    }

    async function action(page, button) {
        var op = button.dataset.tuyaAction;
        var panel = page.querySelector('[data-tuya-scan]');
        if (op === 'close-scan') {
            panel.hidden = true;
            return;
        }
        if (button.disabled) return;
        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        var output;
        if (op === 'scan') {
            panel.hidden = false;
            output = panel.querySelector('[data-tuya-scan-content]');
            output.textContent = 'Ищем устройства в локальной сети…';
        } else if (op === 'info_scene') {
            output = document.querySelector('[data-tuya-scene-content]');
            output.textContent = 'Загружаем действия сцены…';
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('tuya-scene-info')).show(button);
        }
        try {
            var result = await request(page, op, button);
            if (op === 'scan') {
                scanResult(output, result);
            } else if (op === 'info_scene') {
                output.textContent = responseText(result) || 'Сцена не содержит доступных действий.';
            } else {
                if (result.trim()) throw new Error('Сервер вернул неожиданный ответ. Проверьте журнал Tuya.');
                feedback(page, 'success', op === 'run_ir' ? 'Команда отправлена пульту.' : 'Запрос на запуск сцены отправлен.');
            }
        } catch (error) {
            var message = error.name === 'AbortError' ? 'Время ожидания истекло. Проверьте соединение и повторите запрос.' : (error.message || 'Не удалось связаться с сервером.');
            if (output) output.textContent = message;
            feedback(page, 'danger', message);
        } finally {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
        }
    }

    function initProperties(form) {
        var search = form.querySelector('[data-tuya-property-search]');
        var filter = form.querySelector('[data-tuya-property-filter]');
        var cards = Array.from(form.querySelectorAll('[data-tuya-property]'));
        function render() {
            var query = search.value.toLocaleLowerCase().trim();
            var count = 0;
            cards.forEach(function (card) {
                var object = card.querySelector('input[name^="linked_object"]');
                var linked = object && object.value.trim() ? 'linked' : 'unlinked';
                var text = card.textContent + ' ' + Array.from(card.querySelectorAll('input')).map(function (input) { return input.value; }).join(' ');
                card.hidden = (filter.value && filter.value !== linked) || text.toLocaleLowerCase().indexOf(query) === -1;
                if (!card.hidden) count++;
            });
            form.querySelector('[data-tuya-property-count]').textContent = 'Показано ' + count + ' из ' + cards.length;
            form.querySelector('[data-tuya-properties-empty]').hidden = !cards.length || count !== 0;
        }
        search.addEventListener('input', render);
        filter.addEventListener('change', render);
        form.addEventListener('change', function (event) {
            if (event.target.matches('input[name^="linked_object"]')) render();
        });
        render();
    }

    function liveMessage(event) {
        var response, data;
        try {
            response = JSON.parse(event.data);
            if (response.action !== 'events') return;
            data = typeof response.data === 'string' ? JSON.parse(response.data) : response.data;
        } catch (error) {
            window.console.warn('Tuya: некорректное сообщение WebSocket.', error);
            return;
        }
        var eventData = data && data.EVENT_DATA;
        if (!eventData || String(eventData.NAME).toUpperCase() !== 'TUSTATUS' || !eventData.VALUE) return;
        var value = eventData.VALUE;
        if (!/^\d+$/.test(String(value.ID))) return;
        var row = document.querySelector('[data-tuya-device="' + value.ID + '"]');
        if (!row) return;
        var on = Number(value.VALUE) === 1;
        if (value.FIELD === 'STATE') {
            var power = row.querySelector('[data-tuya-power]');
            if (power) {
                power.className = 'md-badge md-badge--' + (on ? 'success' : 'neutral');
                power.textContent = on ? 'Включено' : 'Выключено';
            }
        } else if (value.FIELD === 'ONLINE') {
            var status = row.querySelector('[data-tuya-online]');
            status.className = 'md-status md-status--' + (on ? 'online' : 'offline');
            status.querySelector('.md-status__label').textContent = on ? 'В сети' : 'Не в сети';
            row.dataset.status = on ? 'online' : 'offline';
            row.dataset.sortOnline = on ? '1' : '0';
            var page = row.closest('[data-tuya-page]');
            var rows = Array.from(page.querySelectorAll('[data-tuya-device]'));
            var online = rows.filter(function (item) { return item.dataset.status === 'online'; }).length;
            page.querySelector('[data-tuya-kpi="ONLINE_DEVICES"]').textContent = online;
            page.querySelector('[data-tuya-kpi="OFFLINE_DEVICES"]').textContent = rows.length - online;
            row.closest('[data-md-table]').querySelector('[data-md-table-filter]').dispatchEvent(new Event('change'));
        }
    }

    function syncSocket() {
        var active = document.querySelector('[data-tuya-devices]');
        var current = active ? window.wsSocket : null;
        if (socket !== current) {
            if (socket) socket.removeEventListener('message', liveMessage);
            socket = current;
            subscribed = false;
            if (socket) socket.addEventListener('message', liveMessage);
        }
        if (socket && socket.readyState === 1 && !subscribed) {
            socket.send(JSON.stringify({ action: 'Subscribe', data: { TYPE: 'events', EVENTS: 'TUSTATUS' } }));
            subscribed = true;
        }
        if (!active && socketTimer) {
            window.clearInterval(socketTimer);
            socketTimer = 0;
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var pages = Array.from(scope.querySelectorAll('[data-tuya-page]'));
        if (scope.matches && scope.matches('[data-tuya-page]')) pages.unshift(scope);
        pages.forEach(function (page) {
            if (page.dataset.tuyaReady === '1') return;
            page.dataset.tuyaReady = '1';
            page.addEventListener('click', function (event) {
                var button = event.target.closest('[data-tuya-action]');
                if (button) {
                    event.preventDefault();
                    action(page, button);
                }
                var link = event.target.closest('[data-tuya-delete]');
                if (link) {
                    event.preventDefault();
                    document.querySelector('[data-tuya-delete-confirm]').href = link.href;
                    window.bootstrap.Modal.getOrCreateInstance(document.getElementById('tuya-delete')).show(link);
                }
            });
            page.querySelectorAll('[data-tuya-properties]').forEach(initProperties);
        });
        if (window.MDJUITable) window.MDJUITable.init(scope);
        syncSocket();
        if (!socketTimer && document.querySelector('[data-tuya-devices]')) {
            socketTimer = window.setInterval(syncSocket, 1500);
        }
    }

    window.MDTuyaUI = { init: init };
    if (window.MDJAdminUI) window.MDJAdminUI.registerModuleUI('tuya', { init: init });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    else init(document);
})(window, document);
