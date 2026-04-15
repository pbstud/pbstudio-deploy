import Cookies from 'js-cookie';

const ModalFlash = {
    container: '.remodal > .pop_box',

    show: function (type, msg) {
        $(this.container)
            .prepend('<div class="alert ' + type + '"><p>' + msg + '</p></div>')
        ;
    },

    reset: function () {
        $(this.container).find('.alert').remove();
    },

    success: function (msg) {
        this.show('success', msg);
    },

    error: function (msg) {
        this.show('error', msg);
    },
};

const ModalLoading = {
    container: null,

    elmReplace: null,

    template: null,

    show: function () {
        $(this.elmReplace).after(this.template);
        this.container.addClass('loader');
    },

    remove: function () {
        this.container.removeClass('loader');
        this.container.find('.processing').remove();
    },

    showElement: function ($el) {
        $el.after(this.template);
    },
};

global.ModalFlash = ModalFlash;
global.ModalLoading = ModalLoading;

const PBNotify = {
    toast: function (msg, type = 'success') {
        const $el = $('<div class="pb-toast pb-toast--' + type + '">' + msg + '</div>');
        $('body').append($el);
        setTimeout(() => $el.addClass('pb-toast--show'), 20);
        setTimeout(() => { $el.removeClass('pb-toast--show'); setTimeout(() => $el.remove(), 350); }, 4000);
    },
};
global.PBNotify = PBNotify;

const NotificationCenter = {
    LIST_CACHE_TTL_MS: 20000,
    $root: null,
    $bell: null,
    $badge: null,
    $panel: null,
    $list: null,
    listUrl: null,
    unreadUrl: null,
    markReadUrlTemplate: null,
    pollTimer: null,
    lastUnreadCount: null,
    listCache: null,
    listCacheAt: 0,

    init: function () {
        this.$root = $('[data-notification-center]').first();
        if (!this.$root.length) {
            return;
        }

        this.$bell = this.$root.find('[data-notification-bell]');
        this.$badge = this.$root.find('[data-notification-badge]');
        this.$panel = this.$root.find('[data-notification-panel]');
        this.$list = this.$root.find('[data-notification-list]');
        this.listUrl = this.$root.data('list-url');
        this.unreadUrl = this.$root.data('unread-url');
        this.markReadUrlTemplate = this.$root.data('mark-read-url-template');

        this.bindEvents();
        this.refreshUnreadCount(true);
        this.loadNotifications({ useCache: true, silent: true });
        this.startPolling();
    },

    bindEvents: function () {
        const self = this;

        this.$bell.on('click', function (event) {
            event.preventDefault();

            const isOpen = !self.$panel.prop('hidden');
            if (isOpen) {
                self.closePanel();

                return;
            }

            self.openPanel();
            self.loadNotifications({ useCache: true, silent: false });
        });

        $(document).on('click', function (event) {
            if (!self.$root.length || !self.$root.get(0).contains(event.target)) {
                self.closePanel();
            }
        });

        this.$list.on('click', '.pb-notification-item', function (event) {
            event.preventDefault();

            const id        = $(this).data('notification-id');
            const targetUrl = $(this).data('target-url') || null;
            if (!id) {
                return;
            }

            self.markAsRead(id, $(this), targetUrl);
        });

        // Fallback para evitar que el scroll "se pase" al fondo cuando el panel llega al borde.
        this.$list.on('wheel', function (event) {
            const el = event.currentTarget;
            const originalEvent = event.originalEvent;
            const deltaY = originalEvent ? originalEvent.deltaY : 0;

            const atTop = el.scrollTop <= 0;
            const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 1;

            if ((deltaY < 0 && atTop) || (deltaY > 0 && atBottom)) {
                event.preventDefault();
            }

            event.stopPropagation();
        });
    },

    startPolling: function () {
        const self = this;

        if (this.pollTimer) {
            clearInterval(this.pollTimer);
        }

        this.pollTimer = setInterval(function () {
            self.refreshUnreadCount();
        }, 10000);
    },

    refreshUnreadCount: function (isInitialLoad = false) {
        const self = this;
        if (!this.unreadUrl) {
            return;
        }

        $.getJSON(this.unreadUrl)
            .done(function (response) {
                const count = parseInt(response.count, 10) || 0;

                if (null !== self.lastUnreadCount && count > self.lastUnreadCount) {
                    const delta = count - self.lastUnreadCount;
                    const msg = 1 === delta
                        ? 'Tienes una notificacion nueva.'
                        : 'Tienes ' + delta + ' notificaciones nuevas.';
                    // PBNotify.toast(msg, 'success'); // Eliminado: ya hay burbuja visual
                    self.listCache = null;
                    self.listCacheAt = 0;

                    if (!self.$panel.prop('hidden')) {
                        self.loadNotifications({ useCache: false, silent: true });
                    }
                } else if (isInitialLoad && count > 0) {
                    // PBNotify.toast('Tienes ' + count + ' notificaciones pendientes.', 'warning'); // Eliminado: solo burbuja visual
                }

                self.lastUnreadCount = count;
                self.renderBadge(count);
            })
            .fail(function () {});
    },

    renderBadge: function (count) {
        if (count > 0) {
            this.$badge.text(count > 99 ? '99+' : String(count));
            this.$badge.prop('hidden', false);

            return;
        }

        this.$badge.prop('hidden', true);
    },

    openPanel: function () {
        this.$panel.prop('hidden', false);
        this.$bell.attr('aria-expanded', 'true');
    },

    closePanel: function () {
        this.$panel.prop('hidden', true);
        this.$bell.attr('aria-expanded', 'false');
    },

    loadNotifications: function (options = {}) {
        const self = this;
        const useCache = false !== options.useCache;
        const silent = true === options.silent;

        if (!this.listUrl) {
            return;
        }

        const now = Date.now();
        const cacheIsFresh = this.listCache
            && (now - this.listCacheAt) < this.LIST_CACHE_TTL_MS;

        if (useCache && cacheIsFresh) {
            this.renderList(this.listCache);

            return;
        }

        if (!silent) {
            this.$list.html('<li class="pb-notification-empty">Cargando...</li>');
        }

        $.getJSON(this.listUrl, { page: 1, limit: 50 })
            .done(function (response) {
                const data = response.data || [];
                self.listCache = data;
                self.listCacheAt = Date.now();
                self.renderList(data);
            })
            .fail(function () {
                self.$list.html('<li class="pb-notification-empty">No se pudieron cargar tus notificaciones.</li>');
            });
    },

    renderList: function (items) {
        if (!items.length) {
            this.$list.html('<li class="pb-notification-empty">Sin notificaciones por ahora.</li>');

            return;
        }

        const groups = {
            hoy: [],
            ayer: [],
            semana: [],
        };

        items.forEach(function (item) {
            const key = NotificationCenter.getTimeGroup(item.createdAt);
            groups[key].push(item);
        });

        const groupOrder = [
            { key: 'hoy', label: 'Hoy' },
            { key: 'ayer', label: 'Ayer' },
            { key: 'semana', label: 'Semana' },
        ];

        const html = groupOrder.map(function (group) {
            const groupItems = groups[group.key];
            if (!groupItems.length) {
                return '';
            }

            const rows = groupItems.map(function (item) {
                const readClass = item.readAt ? ' is-read' : ' is-unread';
                const priorityClass = item.priority ? ' is-priority-' + String(item.priority) : '';
                const time = NotificationCenter.formatNotificationTime(item.createdAt);

                return ''
                    + '<li>'
                    + '<button type="button" class="pb-notification-item' + readClass + priorityClass + '" data-notification-id="' + item.id + '" data-target-url="' + (item.targetUrl || '') + '">'
                    + '<span class="pb-notification-item__header">'
                    + '<span class="pb-notification-item__title">' + (item.title || 'Notificacion') + '</span>'
                    + '<span class="pb-notification-item__time">' + time + '</span>'
                    + '</span>'
                    + '<span class="pb-notification-item__body">' + (item.body || '') + '</span>'
                    + '</button>'
                    + '</li>';
            }).join('');

            return ''
                + '<li class="pb-notification-group-label">' + group.label + '</li>'
                + rows;
        }).join('');

        this.$list.html(html || '<li class="pb-notification-empty">Sin notificaciones por ahora.</li>');
    },

    getTimeGroup: function (createdAt) {
        if (!createdAt) {
            return 'semana';
        }

        const now = new Date();
        const date = new Date(createdAt);

        if (isNaN(date.getTime())) {
            return 'semana';
        }

        const nowDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const itemDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const diffDays = Math.floor((nowDay - itemDay) / 86400000);

        if (diffDays <= 0) {
            return 'hoy';
        }

        if (1 === diffDays) {
            return 'ayer';
        }

        return 'semana';
    },

    formatNotificationTime: function (createdAt) {
        if (!createdAt) {
            return '';
        }

        const date = new Date(createdAt);
        if (isNaN(date.getTime())) {
            return '';
        }

        const group = this.getTimeGroup(createdAt);
        if ('hoy' === group) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        return date.toLocaleString([], {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    },

    markAsRead: function (notificationId, $item, targetUrl) {
        const self = this;
        if (!this.markReadUrlTemplate) {
            return;
        }

        const markReadUrl = this.markReadUrlTemplate.replace('__ID__', String(notificationId));

        $.ajax({
            url: markReadUrl,
            type: 'PATCH',
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).done(function () {
            $item.removeClass('is-unread');
            const notificationIdInt = parseInt(notificationId, 10);
            if (self.listCache && !isNaN(notificationIdInt)) {
                self.listCache = self.listCache.map(function (item) {
                    if (parseInt(item.id, 10) === notificationIdInt) {
                        item.readAt = item.readAt || (new Date()).toISOString();
                    }

                    return item;
                });
            }
            self.refreshUnreadCount();

            if (targetUrl) {
                window.location.href = targetUrl;
            }
        }).fail(function () {
            PBNotify.toast('No se pudo marcar la notificacion como leida.', 'error');
        });
    },
};
global.NotificationCenter = NotificationCenter;

$(function () {
    NotificationCenter.init();

    global.remodal = $('[data-remodal-id="modal"]');

    ModalLoading.container = remodal;
    ModalLoading.elmReplace = '.btn-submit';
    ModalLoading.template = $('#modal-loading').html();

    const instRemodal = remodal.remodal();
    const remodalOriginalClasses = remodal.prop('class');

    $(document).on('closed', '.remodal', function (e) {
        $(this).empty();
        $(this).prop('class', remodalOriginalClasses);
    });

    const $body = $('body');

    $body.on('click', '[data-remodal]', function (e) {
        e.preventDefault();

        let url = $(this).data('url');
        let extraClass = $(this).data('remodal');

        if (extraClass) {
            remodal.addClass(extraClass);
        }

        remodal
            .load(url, function (response, status, xhr) {
                if (403 === xhr.status) {
                    remodal.html(response);
                }

                if ('closed' === instRemodal.getState()) {
                    instRemodal.open();
                }
            })
        ;
    });

    /**
     * Form Ajax: Todos los formularios que se muestran en una ventana modal
     * deben tener la clase .m-fjx
     */
    $body.on('submit', '.m-fjx', function (event) {
        event.preventDefault();

        let $form = $(this);

        $form.find('button').prop('disabled', true);

        ModalFlash.reset();
        ModalLoading.show();

        $.ajax({
            url: $form.attr('action'),
            type: $form.prop('method'),
            data: $form.serialize(),
            success: function (response) {
                if (response.targetUrl) {
                    location = response.targetUrl;
                } else if (response.error) {
                    ModalFlash.error(response.error);
                    $form.find('button').prop('disabled', false);
                    ModalLoading.remove();
                } else if (response.success) {
                    ModalLoading.remove();
                    instRemodal.close();
                    PBNotify.toast(response.message || '¡Operación exitosa!');
                } else {
                    remodal.html(response);
                    $form.find('button').prop('disabled', false);
                    ModalLoading.remove();
                }
            },
            error: function (data) {
                ModalFlash.error('No se pudo procesar la solicitud. Intenta nuevamente.');
                $form.find('button').prop('disabled', false);
                ModalLoading.remove();
            },
        });
    });

    $body.on('submit', '.session-rating-form', function (event) {
        event.preventDefault();

        let $form = $(this);
        let $submit = $form.find('button[type="submit"]');
        let $feedback = $form.find('.session-rating-feedback');
        let $ratingGroups = $form.find('.session-rating-group');

        const renderRatingFeedback = function (type, message) {
            let cssClass = 'feedback-error';
            if ('success' === type) {
                cssClass = 'feedback-success';
            }

            $feedback.html('<p class="' + cssClass + '" tabindex="-1">' + message + '</p>');
            $feedback.find('p').trigger('focus');
        };

        const renderUserRatingSummary = function (userRatingAverage) {
            if (undefined === userRatingAverage || null === userRatingAverage) {
                return;
            }

            const parsedAverage = parseFloat(userRatingAverage);
            if (isNaN(parsedAverage)) {
                return;
            }

            let $summary = $form.find('.session-rating-summary');
            if (0 === $summary.length) {
                $summary = $('<p class="session-rating-summary"><span>Tu promedio actual</span><strong></strong></p>');
                $form.find('.session-rating-actions').append($summary);
            }

            $summary.find('span').text('Tu promedio actual');
            $summary.find('strong').text(parsedAverage.toFixed(1) + '/5');
        };

        if ($submit.prop('disabled')) {
            return;
        }

        $feedback.empty();
        ModalFlash.reset();
        $ratingGroups.removeClass('is-invalid');
        $form.find('input[type="radio"]').removeAttr('aria-invalid');

        const selectedRatingsCount = $form.find('input[type="radio"]:checked').length;

        if (0 === selectedRatingsCount) {
            const $firstInput = $form.find('input[type="radio"]').first();

            $ratingGroups.addClass('is-invalid');
            $form.find('input[type="radio"]').attr('aria-invalid', 'true');
            renderRatingFeedback('error', 'Debes marcar minimo una calificacion para que se registre.');

            if ($firstInput.length) {
                $firstInput.trigger('focus');
            }

            return;
        }

        $submit.prop('disabled', true);
        ModalLoading.showElement($submit);
        ModalLoading.container.addClass('loader');

        $.ajax({
            url: $form.attr('action'),
            type: $form.prop('method'),
            data: $form.serialize(),
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            success: function (response) {
                if (response.error) {
                    renderRatingFeedback('error', response.error);

                    return;
                }

                const successMessage = response.success || 'Calificacion registrada correctamente.';
                renderRatingFeedback('success', successMessage);
                renderUserRatingSummary(response.userRatingAverage);

                setTimeout(function () {
                    instRemodal.close();

                    if (response.targetUrl) {
                        let refreshUrl = response.targetUrl;
                        let hash = '';
                        const hashIndex = refreshUrl.indexOf('#');

                        if (hashIndex >= 0) {
                            hash = refreshUrl.substring(hashIndex);
                            refreshUrl = refreshUrl.substring(0, hashIndex);
                        }

                        const separator = -1 === refreshUrl.indexOf('?') ? '?' : '&';
                        refreshUrl = refreshUrl + separator + '_refresh=' + Date.now() + hash;
                        window.location.href = refreshUrl;

                        return;
                    }

                    window.location.reload();
                }, 900);
            },
            error: function () {
                renderRatingFeedback('error', 'No se pudo guardar la calificacion. Intenta nuevamente.');
            },
            complete: function () {
                ModalLoading.remove();
                $submit.prop('disabled', false);
            },
        });
    });

    $body.on('change', '.session-rating-form input[type="radio"]', function () {
        let $input = $(this);
        let $form = $input.closest('.session-rating-form');

        if ($form.find('input[type="radio"]:checked').length > 0) {
            $form.find('.session-rating-group').removeClass('is-invalid');
            $form.find('input[type="radio"]').removeAttr('aria-invalid');
        }
    });

    $body.on('click', '.sessions-used-pagination a', function (event) {
        const href = $(this).attr('href');

        if (!href || '#' === href || $(this).closest('li').hasClass('disabled') || $(this).closest('li').hasClass('active')) {
            return;
        }

        event.preventDefault();

        const $results = $('.sessions-used-history-results').first();
        if (!$results.length) {
            window.location.href = href;

            return;
        }

        $results.addClass('is-loading');

        $.ajax({
            url: href,
            type: 'GET',
            success: function (html) {
                const $html = $('<div>').html(html);
                const $nextResults = $html.find('.sessions-used-history-results').first();

                if (!$nextResults.length) {
                    window.location.href = href;

                    return;
                }

                $results.replaceWith($nextResults);

                if (window.history && window.history.pushState) {
                    window.history.pushState({}, '', href);
                }
            },
            error: function () {
                window.location.href = href;
            },
            complete: function () {
                $('.sessions-used-history-results').removeClass('is-loading');
            },
        });
    });

    var menu = $('#day-content');
    var contenedor = $('.calendar');

    function updateDayFixTop() {
        var headerH = $('header').outerHeight();
        document.documentElement.style.setProperty('--header-height', headerH + 'px');
    }

    if (contenedor.length) {
        var contenedor_offset = contenedor.offset();
        $(window).on('scroll', function () {
            if ($(window).scrollTop() > contenedor_offset.top) {
                updateDayFixTop();
                menu.addClass('day-fix');
            } else {
                menu.removeClass('day-fix');
            }
        });
        $(window).on('resize', function () {
            updateDayFixTop();
        });
        updateDayFixTop();
    }

    $(window).scroll(function () {
        if ($(this).scrollTop() >= 15) {
            $('header').addClass('fix');
        } else {
            $('header').removeClass('fix');
        }
    });

    $('header .nav-toggle').on('click', function (event) {
        $('.nav-collapse').slideToggle();
        $('header .nav-toggle').toggleClass('close');
    });

    /////////////////////////////////////////////
    const $contact = $('#contact');

    if ($contact.length) {
        $contact.validate({
            rules: {
                field: {
                    required: true,
                    email: true
                }
            },
            submitHandler: function (form) {
                var data = $(form).serialize();
                var url = $(form).attr('action');
                var container = $(form).parent();
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    crossDomain: false
                }).success(function (data) {
                    $(container).slideToggle('fast', function () {
                        $(container).html(data.HTML);
                        $(container).slideToggle('fast');
                    });

                });
            }
        });
    }
    /////////////////////////////////////////////
    const $branchMenu = $('#select-branch-menu');

    $(document).on('click', '.reserve-class-toggle', (event) => {
        event.preventDefault();
        $branchMenu.slideToggle();
    });

    const hasVisualOverflow = function ($text) {
        const node = $text.get(0);

        if (!node) {
            return false;
        }

        // Fast path for regular overflow scenarios.
        if (node.scrollHeight > node.clientHeight + 1) {
            return true;
        }

        // Fallback for line-clamp cases where scrollHeight/clientHeight can be equal.
        const clone = node.cloneNode(true);
        const computed = window.getComputedStyle(node);

        clone.style.position = 'absolute';
        clone.style.visibility = 'hidden';
        clone.style.pointerEvents = 'none';
        clone.style.zIndex = '-1';
        clone.style.height = 'auto';
        clone.style.maxHeight = 'none';
        clone.style.overflow = 'visible';
        clone.style.textOverflow = 'clip';
        clone.style.whiteSpace = 'normal';
        clone.style.display = 'block';
        clone.style.webkitLineClamp = 'unset';
        clone.style.webkitBoxOrient = 'initial';
        clone.style.width = node.clientWidth + 'px';
        clone.style.font = computed.font;
        clone.style.lineHeight = computed.lineHeight;
        clone.style.letterSpacing = computed.letterSpacing;

        document.body.appendChild(clone);
        const naturalHeight = clone.scrollHeight;
        document.body.removeChild(clone);

        return naturalHeight > node.clientHeight + 1;
    };

    const syncClassDescriptionState = function () {
        $('.js-class-description').each(function () {
            const $container = $(this);
            const $text = $container.find('.js-class-description-text');
            const isExpanded = $container.hasClass('is-expanded');

            if (!$text.length) {
                return;
            }

            if (isExpanded) {
                $container.addClass('has-overflow');

                return;
            }

            const hasOverflow = hasVisualOverflow($text);

            $container.toggleClass('has-overflow', hasOverflow);
        });
    };

    syncClassDescriptionState();

    $(window).on('resize', function () {
        syncClassDescriptionState();
    });

    $body.on('click', '.js-class-description-toggle', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const $toggle = $(this);
        const $container = $toggle.closest('.js-class-description');
        const isExpanded = $container.toggleClass('is-expanded').hasClass('is-expanded');

        $toggle.text(isExpanded ? 'Ver menos' : 'Ver mas');
        $toggle.attr('aria-expanded', isExpanded ? 'true' : 'false');

        syncClassDescriptionState();
    });

    $body.on('keydown', '.js-class-description-toggle', function (event) {
        if ('Enter' !== event.key && ' ' !== event.key) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        $(this).trigger('click');
    });

    /////////////////////////////////////////////
    const $modalNotice = $('[data-remodal-id=modal-notice]');
    const noticeId = $modalNotice.attr('data-notice-id');
    const noticeCookieKey = noticeId ? 'notice-dismissed-' + noticeId : 'notice-dismissed';

    if ($modalNotice.length && !Cookies.get(noticeCookieKey)) {
        const remodalNotice = $modalNotice.remodal();

        setTimeout(function () {
            remodalNotice.open();
        }, 300);

        $(document).on('click', '.js-notice-no-show', function () {
            Cookies.set(noticeCookieKey, true, { expires: 365, path: '/' });
            remodalNotice.close();
        });
    }
    /////////////////////////////////////////////
});
