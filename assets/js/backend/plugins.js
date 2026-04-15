import PNotify from 'pnotify/dist/pnotify';
import 'pnotify/dist/pnotify.nonblock';
import 'pnotify/dist/pnotify.buttons';

function formatNumber(num)
{
    return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,')
}

global.formatNumber = formatNumber;

/**
 * Random string.
 *
 * @param length
 *
 * @returns {string}
 */
const randomString = (length = 8) => {
    let chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    let str = '';
    for (let i = 0; i < length; i++) {
        str += chars.charAt(Math.floor(Math.random() * chars.length));
    }

    return str;
};

global.randomString = randomString;


(function ($) {
    $.extend({
        alert: function (message, title) {
            new PNotify({
                addclass: 'dark',
                styling: 'brighttheme',
                text: message,
                title: title || 'Mensaje',
            });
        },

        loader: {
            template: $('#loader').html(),

            show: function (container) {
                $(container).html(this.template);
            },

            hide: function (container) {
                $(container).find('.processing').remove();
            }
        },

        flash: {
            show: function (type, message, title) {
                new PNotify({
                    styling: 'bootstrap3',
                    text: message,
                    title: title || 'Mensaje',
                    type: type,
                    buttons: {
                        closer_hover: false,
                        sticker_hover: false,
                    }
                });
            },

            reset: function () {
                PNotify.removeAll();
            },

            success: function (message, title) {
                this.show('success', message, title || 'Éxito');
            },

            danger: function (message, title) {
                this.show('error', message, title || 'Error');
            },
        }
    });
})(jQuery);