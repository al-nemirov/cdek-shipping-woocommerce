/**
 * CDEK Orders Page — чистый список заказов для склада.
 * Фильтрация, быстрые действия, авто-обновление.
 */
(function ($) {
    'use strict';

    var currentFilter = '';
    var currentSearch = '';
    var loadTimer;

    /* ── Инициализация ─────────────────────────────────── */

    $(document).ready(function () {
        load();

        // Фильтры по кнопкам
        $(document).on('click', '.cdek-op-filter', function () {
            $('.cdek-op-filter').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('filter') || '';
            load();
        });

        // Фильтры по сводке
        $(document).on('click', '.cdek-op-stat', function () {
            var f = $(this).data('filter') || '';
            $('.cdek-op-filter').removeClass('active');
            $('.cdek-op-filter[data-filter="' + f + '"]').addClass('active');
            currentFilter = f;
            load();
        });

        // Поиск с дебаунсом
        $(document).on('input', '#cdek-op-search', function () {
            currentSearch = $(this).val();
            clearTimeout(loadTimer);
            loadTimer = setTimeout(load, 350);
        });

        // Быстрые действия
        $(document).on('click', '.cdek-op-btn[data-action]', function (e) {
            e.preventDefault();
            var $btn   = $(this);
            var action = $btn.data('action');
            var oid    = $btn.data('id');
            var $row   = $btn.closest('tr');

            $btn.addClass('cdek-op-btn--loading').text('…');
            $row.find('.cdek-op-row-notice').remove();

            $.post(cdekOrders.ajax_url, {
                action:      'cdek_quick_action',
                nonce:       cdekOrders.nonce,
                cdek_action: action,
                order_id:    oid
            }, function (resp) {
                $btn.removeClass('cdek-op-btn--loading');
                if (resp.success) {
                    $btn.text('✓');
                    var $notice = $('<span class="cdek-op-row-notice cdek-op-row-notice--ok">' + escHtml(resp.data.msg || 'OK') + '</span>');
                    $btn.closest('.cdek-op-actions').after($notice);
                    setTimeout(function () { load(); }, 1500);
                } else {
                    $btn.text('!');
                    var msg = resp.data || 'Ошибка';
                    var $notice = $('<span class="cdek-op-row-notice cdek-op-row-notice--err">' + escHtml(msg) + '</span>');
                    $btn.closest('.cdek-op-actions').after($notice);
                }
            }).fail(function () {
                $btn.removeClass('cdek-op-btn--loading').text('!');
            });
        });
    });

    /* ── Загрузка данных ───────────────────────────────── */

    function load() {
        $('#cdek-op-tbody').html('<tr><td colspan="9" class="cdek-op-loading">Загрузка…</td></tr>');

        $.post(cdekOrders.ajax_url, {
            action: 'cdek_orders_data',
            nonce:  cdekOrders.nonce,
            filter: currentFilter,
            search: currentSearch
        }, function (resp) {
            if (!resp.success) return;
            renderCounts(resp.data.counts);
            renderRows(resp.data.rows);
        });
    }

    /* ── Счётчики сводки ───────────────────────────────── */

    function renderCounts(c) {
        $('#stat-today').text(c.today || 0);
        $('#stat-need-cdek').text(c.need_cdek || 0);
        $('#stat-cdek-sent').text(c.cdek_sent || 0);
        $('#stat-need-ms').text(c.need_ms || 0);
    }

    /* ── Строки таблицы ────────────────────────────────── */

    function renderRows(rows) {
        if (!rows.length) {
            $('#cdek-op-tbody').html('<tr><td colspan="9" class="cdek-op-loading">Заказов не найдено</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += buildRow(r);
        });
        $('#cdek-op-tbody').html(html);
    }

    function buildRow(r) {
        var todayClass = r.is_today ? ' is-today' : '';

        // Номер
        var num = '<a href="' + escAttr(r.edit_url) + '" class="cdek-op-num" target="_blank">#' + escHtml(r.number) + '</a>';

        // Дата
        var date = '<span class="cdek-op-date">' + escHtml(r.date) + (r.is_today ? '<span class="cdek-op-today-badge">сегодня</span>' : '') + '</span>';

        // Покупатель
        var cust = '<div class="cdek-op-customer">' + escHtml(r.customer || '—') + '</div>'
                 + (r.phone ? '<div class="cdek-op-phone">' + escHtml(r.phone) + '</div>' : '');

        // Товары
        var items = '<ul class="cdek-op-items">';
        (r.items || []).forEach(function (i) { items += '<li>' + escHtml(i) + '</li>'; });
        items += '</ul>';

        // Сумма + статус WC
        var statusCls = {
            processing: 'cdek-op-wc-status--processing',
            completed:  'cdek-op-wc-status--completed',
            pending:    'cdek-op-wc-status--pending',
            cancelled:  'cdek-op-wc-status--cancelled'
        }[r.status] || 'cdek-op-wc-status--other';
        var total = '<div class="cdek-op-total">' + r.total + '</div>'
                  + '<span class="cdek-op-wc-status ' + statusCls + '">' + escHtml(r.status_label) + '</span>';

        // Доставка
        var shipCls = r.is_cdek ? 'cdek-op-ship--cdek' : (r.is_yd ? 'cdek-op-ship--yd' : 'cdek-op-ship--other');
        var shipIcon = r.is_cdek ? '🚚' : (r.is_yd ? '🟡' : '📦');
        var shipLabel = r.is_cdek ? 'СДЭК' : (r.is_yd ? 'Яндекс' : escHtml(r.method_id || '—'));
        var ship = '<span class="cdek-op-ship ' + shipCls + '">' + shipIcon + ' ' + shipLabel + '</span>';

        // СДЭК статус
        var cdekCell;
        if (r.cdek_uuid) {
            cdekCell = '<span class="cdek-op-cdek-ok">✅ ' + escHtml(r.cdek_status || 'Отправлен') + '</span>'
                     + (r.cdek_track ? '<span class="cdek-op-cdek-uuid">📦 ' + escHtml(r.cdek_track) + '</span>' : '');
        } else if (r.is_cdek) {
            cdekCell = '<span class="cdek-op-cdek-wait">⏳ Не отправлен</span>';
        } else {
            cdekCell = '<span style="color:#ccc">—</span>';
        }

        // МС статус
        var msCell;
        if (r.ms_id) {
            msCell = '<span class="cdek-op-ms-ok">✅ ' + escHtml(r.ms_name || 'В МС') + '</span>';
        } else if (r.ms_error) {
            msCell = '<span class="cdek-op-ms-wait">❌</span><span class="cdek-op-ms-err">' + escHtml(r.ms_error.substring(0, 40)) + '</span>';
        } else {
            msCell = '<span class="cdek-op-ms-wait">⏳ Нет в МС</span>';
        }

        // Действия
        var actions = '<div class="cdek-op-actions">';
        actions += '<a href="' + escAttr(r.edit_url) + '" class="cdek-op-btn cdek-op-btn--view" target="_blank">✏️</a>';
        if (r.is_cdek && !r.cdek_uuid) {
            actions += '<button class="cdek-op-btn cdek-op-btn--cdek" data-action="send_cdek" data-id="' + r.id + '">СДЭК →</button>';
        }
        if (r.is_cdek && r.cdek_uuid) {
            actions += '<button class="cdek-op-btn" data-action="update_cdek" data-id="' + r.id + '">↻ СДЭК</button>';
        }
        if (!r.ms_id) {
            actions += '<button class="cdek-op-btn cdek-op-btn--ms" data-action="send_ms" data-id="' + r.id + '">МС →</button>';
        }
        actions += '</div>';

        return '<tr class="cdek-op-row' + todayClass + '" data-id="' + r.id + '">'
             + '<td>' + num + '</td>'
             + '<td>' + date + '</td>'
             + '<td>' + cust + '</td>'
             + '<td>' + items + '</td>'
             + '<td>' + total + '</td>'
             + '<td>' + ship + '</td>'
             + '<td>' + cdekCell + '</td>'
             + '<td>' + msCell + '</td>'
             + '<td>' + actions + '</td>'
             + '</tr>';
    }

    /* ── Утилиты ───────────────────────────────────────── */

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return escHtml(s); }

})(jQuery);
