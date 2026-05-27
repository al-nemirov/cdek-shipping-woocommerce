/**
 * Родина — СДЭК: Checkout frontend
 *
 * - Watches for CDEK shipping method selection
 * - Shows "Выбрать ПВЗ" button
 * - Opens PVZ selection modal (Yandex Maps + list)
 * - Stores selected PVZ code in hidden field
 */
(function($) {
    'use strict';

    var state = {
        points:    [],
        selected:  null,
        map:       null,
        mapReady:  false,
        city:      '',
        overlay:   null
    };

    /* ─── Init ─── */
    $(document).ready(function() {
        injectPvzButton();

        // Watch for shipping method changes
        $(document.body).on('updated_checkout', function() {
            injectPvzButton();
        });
    });

    /* ─── Inject PVZ button next to CDEK shipping option ─── */
    function injectPvzButton() {
        // Find CDEK shipping method radio/label
        var $methods = $('input.shipping_method[value*="rodina_cdek_pvz"]');
        if (!$methods.length) return;

        $methods.each(function() {
            var $input = $(this);
            var $li = $input.closest('li');

            // Already has button?
            if ($li.find('.cdek-pvz-btn').length) return;

            // Add button
            var $btn = $('<a href="#" class="cdek-pvz-btn">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>' +
                '<circle cx="12" cy="10" r="3"/></svg> ' +
                'ВЫБРАТЬ ПВЗ СДЭК</a>');

            // Show selected PVZ if any
            var selectedCode = $('#cdek_pvz_code').val();
            if (selectedCode) {
                var $info = $('<div class="cdek-pvz-selected">' +
                    '<span class="cdek-pvz-selected-label">ПВЗ:</span> ' +
                    '<span class="cdek-pvz-selected-addr">' + ($('#cdek_pvz_address').val() || selectedCode) + '</span>' +
                    '</div>');
                $li.append($info);
            }

            $btn.on('click', function(e) {
                e.preventDefault();
                // Only load PVZ if this method is selected
                $input.prop('checked', true).trigger('change');
                openPvzModal();
            });

            $li.append($btn);
        });
    }

    /* ─── Open PVZ Modal ─── */
    function openPvzModal() {
        // Determine city from billing
        var city = $('#billing_city').val() || '';
        var postcode = $('#billing_postcode').val() || '';

        if (!city && !postcode) {
            alert('Укажите город в форме заказа');
            $('#billing_city').focus();
            return;
        }

        state.city = city;
        createOverlay();
        loadPvzPoints(city, postcode);
    }

    /* ─── Create modal overlay ─── */
    function createOverlay() {
        if (state.overlay) {
            state.overlay.show();
            return;
        }

        var html =
            '<div id="cdek-pvz-overlay">' +
                '<div class="cdek-pvz-modal">' +
                    '<div class="cdek-pvz-header">' +
                        '<h3>Выберите пункт выдачи СДЭК</h3>' +
                        '<input type="text" id="cdek-pvz-search" placeholder="Поиск по адресу...">' +
                        '<button class="cdek-pvz-close">&times;</button>' +
                    '</div>' +
                    '<div class="cdek-pvz-content">' +
                        '<div id="cdek-pvz-map"></div>' +
                        '<div id="cdek-pvz-list">' +
                            '<div class="cdek-pvz-loading">Загрузка пунктов выдачи...</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        $('body').append(html);
        state.overlay = $('#cdek-pvz-overlay');

        // Close button
        state.overlay.on('click', '.cdek-pvz-close', function() {
            closePvzModal();
        });

        // Close on overlay click
        state.overlay.on('click', function(e) {
            if (e.target === this) closePvzModal();
        });

        // ESC key
        $(document).on('keydown.cdekpvz', function(e) {
            if (e.key === 'Escape') closePvzModal();
        });

        // Search filter
        $('#cdek-pvz-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            filterPoints(q);
        });
    }

    function closePvzModal() {
        if (state.overlay) state.overlay.hide();
        $(document).off('keydown.cdekpvz');
    }

    /* ─── Load PVZ points via AJAX ─── */
    function loadPvzPoints(city, postcode) {
        $('#cdek-pvz-list').html('<div class="cdek-pvz-loading">Загрузка пунктов выдачи...</div>');

        $.ajax({
            url: rodinaCdek.ajax_url,
            data: {
                action:   'rodina_cdek_pvz',
                city:     city,
                postcode: postcode
            },
            success: function(resp) {
                if (!resp.success) {
                    $('#cdek-pvz-list').html('<div class="cdek-pvz-error">' + (resp.data || 'Ошибка') + '</div>');
                    return;
                }

                state.points = resp.data.points || [];
                renderPointsList(state.points);
                initMap(state.points);
            },
            error: function() {
                $('#cdek-pvz-list').html('<div class="cdek-pvz-error">Ошибка загрузки</div>');
            }
        });
    }

    /* ─── Render points list ─── */
    function renderPointsList(points) {
        var $list = $('#cdek-pvz-list');
        $list.empty();

        if (!points.length) {
            $list.html('<div class="cdek-pvz-empty">Нет доступных ПВЗ</div>');
            return;
        }

        points.forEach(function(p) {
            var selectedClass = (state.selected && state.selected.code === p.code) ? ' cdek-pvz-item--active' : '';
            var features = [];
            if (p.have_cash) features.push('💵');
            if (p.have_card) features.push('💳');
            if (p.is_dressing) features.push('👔');

            var $item = $(
                '<div class="cdek-pvz-item' + selectedClass + '" data-code="' + p.code + '">' +
                    '<div class="cdek-pvz-item-name">' + escHtml(p.name || p.code) + '</div>' +
                    '<div class="cdek-pvz-item-addr">' + escHtml(p.address) + '</div>' +
                    '<div class="cdek-pvz-item-meta">' +
                        '<span class="cdek-pvz-item-time">' + escHtml(p.work_time) + '</span>' +
                        (features.length ? ' <span class="cdek-pvz-item-features">' + features.join(' ') + '</span>' : '') +
                    '</div>' +
                '</div>'
            );

            $item.on('click', function() {
                selectPoint(p);
            });

            $list.append($item);
        });
    }

    /* ─── Filter points by search query ─── */
    function filterPoints(q) {
        if (!q) {
            renderPointsList(state.points);
            return;
        }
        var filtered = state.points.filter(function(p) {
            return (p.address && p.address.toLowerCase().indexOf(q) >= 0) ||
                   (p.name && p.name.toLowerCase().indexOf(q) >= 0) ||
                   (p.code && p.code.toLowerCase().indexOf(q) >= 0);
        });
        renderPointsList(filtered);
    }

    /* ─── Select a PVZ point ─── */
    function selectPoint(point) {
        state.selected = point;

        // Update hidden fields
        $('#cdek_pvz_code').val(point.code);
        $('#cdek_pvz_address').val(point.address);

        // Visual highlight
        $('.cdek-pvz-item').removeClass('cdek-pvz-item--active');
        $('.cdek-pvz-item[data-code="' + point.code + '"]').addClass('cdek-pvz-item--active');

        // Update info on checkout page
        updateCheckoutPvzInfo(point);

        // Center map on selected point
        if (state.map && point.lat && point.lng) {
            state.map.setCenter([point.lat, point.lng], 15);
        }

        // Close modal after short delay
        setTimeout(function() {
            closePvzModal();
        }, 300);
    }

    /* ─── Update checkout display ─── */
    function updateCheckoutPvzInfo(point) {
        $('.cdek-pvz-selected').remove();

        var $method = $('input.shipping_method[value*="rodina_cdek_pvz"]:checked').closest('li');
        if ($method.length) {
            var $info = $('<div class="cdek-pvz-selected">' +
                '<span class="cdek-pvz-selected-label">✓ ПВЗ:</span> ' +
                '<span class="cdek-pvz-selected-addr">' + escHtml(point.address) + '</span>' +
                '</div>');
            $method.find('.cdek-pvz-btn').after($info);
        }
    }

    /* ─── Init Yandex Map ─── */
    function initMap(points) {
        // Load Yandex Maps API if not loaded
        if (typeof ymaps === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://api-maps.yandex.ru/2.1/?apikey=&lang=ru_RU';
            script.onload = function() {
                ymaps.ready(function() {
                    state.mapReady = true;
                    buildMap(points);
                });
            };
            document.head.appendChild(script);
        } else if (state.mapReady) {
            buildMap(points);
        } else {
            ymaps.ready(function() {
                state.mapReady = true;
                buildMap(points);
            });
        }
    }

    function buildMap(points) {
        var container = document.getElementById('cdek-pvz-map');
        if (!container) return;

        // Destroy old map
        if (state.map) {
            state.map.destroy();
            state.map = null;
        }

        // Default center (Moscow)
        var center = [55.751244, 37.618423];
        if (points.length && points[0].lat) {
            center = [points[0].lat, points[0].lng];
        }

        state.map = new ymaps.Map('cdek-pvz-map', {
            center: center,
            zoom: 11,
            controls: ['zoomControl', 'geolocationControl']
        });

        // Add placemarks
        var collection = new ymaps.GeoObjectCollection();

        points.forEach(function(p) {
            if (!p.lat || !p.lng) return;

            var placemark = new ymaps.Placemark([p.lat, p.lng], {
                balloonContentHeader: p.name || p.code,
                balloonContentBody:
                    '<div style="font-size:13px">' +
                    '<b>' + escHtml(p.address) + '</b><br>' +
                    p.work_time + '<br>' +
                    '<a href="#" onclick="window.cdekSelectPvz(\'' + p.code + '\');return false;" ' +
                    'style="color:#e53935;font-weight:bold;margin-top:4px;display:inline-block">Выбрать этот ПВЗ</a>' +
                    '</div>',
                hintContent: p.address
            }, {
                preset: 'islands#redDotIcon'
            });

            placemark.events.add('click', function() {
                // Highlight in list
                var $item = $('.cdek-pvz-item[data-code="' + p.code + '"]');
                if ($item.length) {
                    $('#cdek-pvz-list').scrollTop(0);
                    var pos = $item.position().top + $('#cdek-pvz-list').scrollTop();
                    $('#cdek-pvz-list').animate({ scrollTop: pos - 10 }, 200);
                    $item.addClass('cdek-pvz-item--highlight');
                    setTimeout(function() { $item.removeClass('cdek-pvz-item--highlight'); }, 1500);
                }
            });

            collection.add(placemark);
        });

        state.map.geoObjects.add(collection);

        // Fit bounds
        if (points.length > 1) {
            state.map.setBounds(collection.getBounds(), { checkZoomRange: true, zoomMargin: 30 });
        }
    }

    // Global function for balloon link
    window.cdekSelectPvz = function(code) {
        var point = state.points.find(function(p) { return p.code === code; });
        if (point) selectPoint(point);
    };

    /* ─── Helpers ─── */
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
