import 'switchery/switchery.css';

import Switchery from 'switchery/switchery';
import 'bootstrap-daterangepicker/daterangepicker';
import './plugins';

/**
 * New transaction
 */
function initSessionList()
{
    $('#filter_instructor').select2();
}

/**
 * New transaction
 */
function checkoutHandler(conektaPublicKey)
{
    $.getScript('https://cdn.conekta.io/js/latest/conekta.js')
        .done(function () {
            Conekta.setPublishableKey(conektaPublicKey);
            Conekta.setLanguage('es');
        })
    ;
}

global.checkoutHandler = checkoutHandler;

function initTransactionNew()
{
    let form = $('#form-transaction-new');
    if (!form.length) {
        return;
    }

    const PAYMENT_FREE = 'payment.free';
    const PAYMENT_CARD = 'payment.card';

    let resume = {
        price: 0,
        discountCoupon: 0,
        discountAdditional: 0
    };

    let inputPackage = $('#transaction_package');
    let labelAmount = $('#package_amount');
    let labelSpecialPrice = $('#special_price');
    let inputExpirationAt = $('#transaction_package_expiration_at');
    let chargeMethod = $('#transaction_chargeMethod');
    let inputUser = $('#transaction_user');
    let inputUserSearch = $('#transaction_user_search');
    let inputDiscount = $('#transaction_discount');
    let labelTotal = $('#transaction_total');
    let btnCoupon = $('#btn_coupon');
    let inputCoupon = $('#coupon_code');
    let inputTransactionCoupon = $('#transaction_coupon');
    let labelCouponPercentaje = $('#coupon_percentaje');
    let labelCouponTotal = $('#coupon_total');
    let labelDiscountPercentaje = $('#additional_discount_percentaje');
    let labelDiscount = $('#additional_discount');

    const updateSelectedUser = function (id, email) {
        inputUser.val(id || '');
        $('#transaction_user_email').val(email || '');
    };

    if (inputUserSearch.length) {
        inputUserSearch.select2({
            width: '100%',
            allowClear: true,
            placeholder: 'Buscar por ID, nombre o apellido',
            minimumInputLength: 2,
            ajax: {
                url: inputUserSearch.data('search-url'),
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term || '',
                        limit: 20,
                    };
                },
                processResults: function (data) {
                    return {
                        results: data.items || [],
                    };
                }
            }
        });

        inputUserSearch.on('select2:select', function (event) {
            let selected = event.params && event.params.data ? event.params.data : null;

            updateSelectedUser(selected ? selected.id : '', selected ? selected.email : '');
        });

        inputUserSearch.on('select2:clear', function () {
            updateSelectedUser('', '');
        });

        let selectedOption = inputUserSearch.find('option:selected');

        if (selectedOption.length) {
            updateSelectedUser(selectedOption.val(), selectedOption.data('email'));
        } else {
            updateSelectedUser('', '');
        }
    }

    /**
     * Is free.
     *
     * @returns {boolean}
     */
    const isFree = function () {
        return PAYMENT_FREE === chargeMethod.val();
    };

    const calculateTotals = function () {
        let total = resume.price - resume.discountCoupon;

        if (resume.discountAdditional) {
            total -= (resume.discountAdditional * total) / 100;
            labelDiscount.text(formatNumber(total.toFixed(2)));
        } else {
            labelDiscount.text('--')
        }

        if (isFree()) {
            total = 0.0;
        }

        labelTotal.text(formatNumber(total.toFixed(2)));
    };

    const resetCoupon = function () {
        resume.discountCoupon = 0;
        inputTransactionCoupon.val();
        labelCouponPercentaje.text('');
        labelCouponTotal.text('--');
    };

    /**
     * Input Charge Method
     */
    let paymentPanel = $('.payment-panel');
    chargeMethod
        .on('change', function () {
            let chargeMethodVal = $(this).val();

            paymentPanel.addClass('hidden')
            paymentPanel.find('input, select').val('').prop('required', false);

            let currentPanel = $('#' + chargeMethodVal.replace('.', '_'));
            if (currentPanel.length) {
                currentPanel.removeClass('hidden');
                currentPanel.find('input, select').prop('required', true);
            }

            calculateTotals();
        })
        .trigger('change')
    ;

    /**
     * Input user.
     */
    inputUser.on('change', function () {
        if (!$(this).val()) {
            $('#transaction_user_email').val('');
        }
    });

    /**
     * Input Package
     */
    inputPackage
        .on('change', function () {
            let pckg = inputPackage.find('option:selected');
            let expirationAt = '';
            let amount = '0.00';
            let specialPrice = '--';
            resume.price = 0;

            if ('' !== pckg.val()) {
                expirationAt = pckg.data('expiration-at');
                amount = pckg.data('amount');
                specialPrice = pckg.data('special-price');
                resume.price = parseFloat(pckg.data('amount-original'));
            }

            labelAmount.text(amount);
            labelSpecialPrice.text(specialPrice);
            inputExpirationAt.val(expirationAt);

            resetCoupon();
            calculateTotals();
        })
        .trigger('change')
    ;

    /**
     * Coupon
     */
    const URL_COUPON_VALIDATE = btnCoupon.data('url');
    btnCoupon.on('click', function () {
        resetCoupon();
        calculateTotals();

        if (isFree()) {
            $.flash.danger('No se puede aplicar cupón a clases gratis.');

            return false;
        }

        let packageVal = inputPackage.val();
        if ('' === packageVal) {
            $.flash.danger('Primero debe seleccionar el paquete.');

            return false;
        }

        let code = $.trim(inputCoupon.val());

        if ('' === code) {
            $.flash.danger('Ingrese el código del cupón.');

            return false;
        }

        $.blockUI();
        let data = {
            package: packageVal,
            coupon: code
        };

        $.getJSON(URL_COUPON_VALIDATE, data, function (response) {
            if (response.success) {
                inputTransactionCoupon.val(code);
                resume.discountCoupon = response.data.discount;
                labelCouponTotal.text(response.data.total);
                labelCouponPercentaje.text(formatNumber(response.data.percentaje));
                calculateTotals();
                $.flash.success('Cupón aplicado exitosamente.');
            } else {
                $.flash.danger(response.error);
            }

            $.unblockUI();
        });
    });

    /**
     * Discount.
     */
    const discountMin = parseInt(inputDiscount.attr('min'));
    const discountMax = parseInt(inputDiscount.attr('max'));
    inputDiscount.on('keyup change', function (event) {
        let discount = parseInt($(this).val());

        if (!isNaN(discount) && discount > 0 && discount >= discountMin && discount <= discountMax) {
            resume.discountAdditional = discount;
            labelDiscountPercentaje.text(`(${discount}%)`);
        } else {
            resume.discountAdditional = 0;
            labelDiscountPercentaje.text('')
        }

        calculateTotals();
    }).trigger('change');

    /**
     * Submit
     */
    let btnSubmit = form.find('button[type="submit"]');

    form.on('submit', function () {
        if (!confirm('¿Confirmas que deseas registrar una transacción?')) {
            return false;
        }

        btnSubmit.button('loading');

        if (PAYMENT_CARD === chargeMethod.val()) {
            Conekta.Token.create(
                form,
                function (token) {
                    form.find('[name="conekta_card_token"]').remove();
                    form.append($('<input type="hidden" name="conekta_card_token" />').val(token.id));

                    form.get(0).submit();
                },
                function (response) {
                    alert(response.message_to_purchaser);

                    btnSubmit.button('reset');
                }
            );
        } else {
            return true;
        }

        return false;
    });
}

function initPackage()
{
    let form = $('#form-package');

    if (!form.length) {
        return;
    }

    let $packageUnlimited = $('#package_isUnlimited');

    function ilimitedChangeEvent()
    {
        if ($packageUnlimited.is(':checked')) {
            $('#package_totalClasses').val(0).prop('readonly', true).prop('required', false);
        } else {
            $('#package_totalClasses').prop('readonly', false).prop('required', true);
        }
    }

    ilimitedChangeEvent();

    $packageUnlimited.on('change', ilimitedChangeEvent);
}

/**
 * Resize function without multiple trigger
 *
 * Usage:
 * $(window).smartresize(function(){
 *     // code here
 * });
 */
(function ($, sr) {
    // debouncing function from John Hann
    // http://unscriptable.com/index.php/2009/03/20/debouncing-javascript-methods/
    var debounce = function (func, threshold, execAsap) {
        var timeout;

        return function debounced () {
            var obj = this, args = arguments;
            function delayed () {
                if (!execAsap)
                    func.apply(obj, args);
                timeout = null;
            }

            if (timeout)
                clearTimeout(timeout);
            else if (execAsap)
                func.apply(obj, args);

            timeout = setTimeout(delayed, threshold || 100);
        };
    };

    // smartresize
    jQuery.fn[sr] = function(fn){  return fn ? this.bind('resize', debounce(fn)) : this.trigger(sr); };

})(jQuery,'smartresize');
/**
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

var $BODY = $('body'),
    $MENU_TOGGLE = $('#menu_toggle'),
    $SIDEBAR_MENU = $('#sidebar-menu'),
    $SIDEBAR_FOOTER = $('.sidebar-footer'),
    $LEFT_COL = $('.left_col'),
    $RIGHT_COL = $('.right_col'),
    $NAV_MENU = $('.nav_menu'),
    $FOOTER = $('footer')
;

// Sidebar
function initSidebar()
{
    var setContentHeight = function () {
        // reset height
        $RIGHT_COL.css('min-height', $(window).height());

        var bodyHeight = $BODY.outerHeight(),
            footerHeight = $BODY.hasClass('footer_fixed') ? -10 : $FOOTER.height(),
            leftColHeight = $LEFT_COL.eq(1).height() + $SIDEBAR_FOOTER.height(),
            contentHeight = bodyHeight < leftColHeight ? leftColHeight : bodyHeight;

        // normalize content
        contentHeight -= $NAV_MENU.height() + footerHeight;

        $RIGHT_COL.css('min-height', contentHeight);
    };

    $SIDEBAR_MENU.find('a').on('click', function (ev) {
        var $li = $(this).parent();

        if ($li.is('.active')) {
            $li.removeClass('active active-sm');
            $('ul:first', $li).slideUp(function () {
                setContentHeight();
            });
        } else {
            // prevent closing menu if we are on child menu
            if (!$li.parent().is('.child_menu')) {
                $SIDEBAR_MENU.find('li').removeClass('active active-sm');
                $SIDEBAR_MENU.find('li ul').slideUp();
            } else {
                if ($BODY.is(".nav-sm")) {
                    $SIDEBAR_MENU.find("li").removeClass("active active-sm");
                    $SIDEBAR_MENU.find("li ul").slideUp();
                }
            }

            $li.addClass('active');

            $('ul:first', $li).slideDown(function () {
                setContentHeight();
            });
        }
    });

    // toggle small or large menu
    $MENU_TOGGLE.on('click', function () {
        if ($BODY.hasClass('nav-md')) {
            $SIDEBAR_MENU.find('li.active ul').hide();
            $SIDEBAR_MENU.find('li.active').addClass('active-sm').removeClass('active');
        } else {
            $SIDEBAR_MENU.find('li.active-sm ul').show();
            $SIDEBAR_MENU.find('li.active-sm').addClass('active').removeClass('active-sm');
        }

        $BODY.toggleClass('nav-md nav-sm');

        setContentHeight();

        $('.dataTable').each(function () {
            $(this).dataTable().fnDraw();
        });
    });

    var $currentChild = $SIDEBAR_MENU.find('.menu_level_1 > .has-current-page, .menu_level_1 > .current-page');

    $currentChild.addClass('current-page');
    $currentChild.parent('ul').slideDown(function () {
        setContentHeight();
    }).parent().addClass('active');

    // recompute content when resizing
    $(window).smartresize(function () {
        setContentHeight();
    });

    setContentHeight();

    // fixed sidebar
    if ($.fn.mCustomScrollbar) {
        $('.menu_fixed').mCustomScrollbar({
            autoHideScrollbar: true,
            theme: 'minimal',
            mouseWheel:{ preventDefault: true }
        });
    }
}
// /Sidebar

global.datepickeroptions = {
    singleDatePicker: true,
    singleClasses: "picker_4",
    autoUpdateInput: false,
    locale: {
        format: 'DD/MM/YYYY',
        daysOfWeek: [ 'D', 'L', 'M', 'M', 'J', 'V', 'S', ],
        monthNames: [ 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' ],
        applyLabel: 'Aplicar',
        cancelLabel: 'Cancelar',
    }
};

// Style blockUI
$.blockUI.defaults.message = '<div class="text-primary block-ui-loader">' +
        '<i class="fa fa-refresh fa-spin fa-lg spin-fast"></i>' +
        '<span>Cargando...</span>' +
    '</div>';

$.extend($.blockUI.defaults.css, {
    border: '1px solid #dee2e6',
    width: '150px',
    top: '40%',
    left: '46%',
    backgroundColor: '#fff',
});

$(function () {
    $('.datepicker').daterangepicker(datepickeroptions);

    $('body').on('apply.daterangepicker', '.datepicker', function (ev, picker) {
        $(this).val(picker.startDate.format('DD/MM/YYYY'));
    });

    $('[data-wysiwyg]').summernote({
        focus: true,
        height: 500,
        lang: 'es-ES',
    });

    $('[data-current]').each(function (ind, elm) {
        let value = $(elm).data('current');

        $(elm).find('option[value="' + value + '"]').prop('selected', true);
    });

    $('[data-toggle="tooltip"]').tooltip({
        container: 'body'
    });

    // Progressbar
    if ($(".progress .progress-bar")[0]) {
        $('.progress .progress-bar').progressbar();
    }

    // Switchery
    const $jsSwitch = $('.js-switch');
    if ($jsSwitch.length > 0) {
        $jsSwitch.each(function (index, el) {
            let switchery = new Switchery(el, {
                color: '#26B99A'
            });

            // Prevent duplicate id.
            switchery.switcher.removeAttribute('id');
            $(this).data('switchery', switchery);
        });
    }
    // /Switchery

    // iCheck
    const $iCheck = $('input.flat');
    if ($iCheck.length) {
        $iCheck.iCheck({
            checkboxClass: 'icheckbox_flat-green',
            radioClass: 'iradio_flat-green'
        });
    }
    // /iCheck

    $('[data-tag-number]').on('beforeItemAdd', function (event) {
        let $field = $(this);
        let max = parseInt($('#' + $field.data('tag-number')).val());
        let num = parseInt(event.item);

        if (isNaN(max)) {
            $field.tagsinput('removeAll');
            event.cancel = true;

            return;
        }

        // Remove invalid.
        $.each($field.tagsinput('items'), function (index, value) {
            if (value > max) {
                $field.tagsinput('remove', value);
            }
        });

        if (isNaN(num) || num > max || num < 1) {
            event.cancel = true;
        }
    });

    $('.attended').on('change', function () {
        const $attended = $(this);
        const previousValue = !$attended.prop('checked');

        $.blockUI();
        $.post($attended.data('url'), {
            attended: $attended.prop('checked'),
            _token: $attended.data('csrf-token')
        }, function (data) {
            if (!data || !data.success) {
                $attended.prop('checked', previousValue);
            }
            $.unblockUI();
        }, 'json').fail(function () {
            $attended.prop('checked', previousValue);
            $.unblockUI();
        });
    });

    initSidebar();
    initSessionList();
    initTransactionNew();
    initPackage();
});
