const conektaSuccessResponseHandler = function (token) {
    let form = $("#checkout-form");
    let data = {
        'conekta_card_token': token.id,
        'coupon': $('#coupon-validated').val()
    };

    $.post(form.prop('action'), data, function (data) {
        if (data.error) {
            ModalFlash.error(data.error);

            form.find('button').prop('disabled', false);

            ModalLoading.remove();
        } else {
            location.href = data.redirect;
        }
    });
};

const conektaErrorResponseHandler = function (response) {
    let form = $("#checkout-form");

    ModalFlash.error(response.message_to_purchaser);

    form.find('button').prop('disabled', false);
    ModalLoading.remove();
};

function checkoutHandler(conektaPublicKey)
{
    $.getScript('https://cdn.conekta.io/js/latest/conekta.js')
        .done(function () {
            Conekta.setPublicKey(conektaPublicKey);
            Conekta.setLanguage('es');

            $('#checkout-form').submit(function (e) {
                let form = $(this);

                ModalFlash.reset();
                ModalLoading.show();

                form.find('button').prop('disabled', true);
                Conekta.Token.create(form.get(0), conektaSuccessResponseHandler, conektaErrorResponseHandler);

                return false;
            });
        })
    ;

    const $frmCoupon = $('#frm-coupon');
    const $couponValid = $('#coupon-valid');
    const $couponField = $('#coupon-field');
    const $btnCoupon = $('#btn-coupon');
    const $couponValidated = $('#coupon-validated');
    const URL_COUPON_VALIDATE = $btnCoupon.data('url');

    $btnCoupon.on('click', function () {
        let couponCode = $.trim($couponField.val());

        if (couponCode) {
            ModalFlash.reset();
            $btnCoupon.hide();
            ModalLoading.showElement($btnCoupon);
            $frmCoupon.find('.processing').show();

            $.getJSON(URL_COUPON_VALIDATE, {coupon: couponCode}, function (response) {
                if (response.success) {
                    $.each(response.data, function (key, value) {
                        $(`#coupon-${key}`).text(value);
                    });

                    $couponValidated.val(couponCode);

                    $frmCoupon.hide();
                    $couponValid.show();
                } else {
                    $frmCoupon.find('.processing').remove();
                    $btnCoupon.show();
                    $frmCoupon.show();
                    ModalFlash.error(response.error);
                }
            });
        }
    });
}

// Solo se llama cuando se muestra por ajax.
function checkoutNotLogged()
{
    const $body = $('body');

    const initResettingResendCooldown = function () {
        $('.js-resetting-resend-btn').each(function () {
            let $button = $(this);
            let defaultLabel = 'Click aqui para reenviar';
            let remaining = parseInt($button.data('cooldown-remaining'), 10) || 0;

            const setState = function (seconds) {
                if (seconds > 0) {
                    $button.prop('disabled', true);
                    $button.text('Reenviar en ' + seconds + 's');
                } else {
                    $button.prop('disabled', false);
                    $button.text(defaultLabel);
                }
            };

            setState(remaining);

            if (remaining > 0) {
                let timer = window.setInterval(function () {
                    remaining -= 1;
                    setState(remaining);

                    if (remaining <= 0) {
                        window.clearInterval(timer);
                    }
                }, 1000);
            }
        });
    };

    const renderAuthUrl = function (form, url, callback) {
        let $container = form.closest('.right-container');

        if ($container.length) {
            $container.load(url, callback);

            return;
        }

        remodal.load(url, callback);
    };

    const renderAuthHtml = function (form, response) {
        let $container = form.closest('.right-container');

        if ($container.length) {
            $container.html(response);

            return;
        }

        remodal.html(response);
    };

    const submitAuthForm = function (eventNamespace, selector, onSuccess) {
        $body.off(`submit.${eventNamespace}`).on(`submit.${eventNamespace}`, selector, function (e) {
            e.preventDefault();

            let form = $(this);

            form.find('button').prop('disabled', true);

            ModalFlash.reset();
            ModalLoading.show();

            $.ajax({
                url: form.prop('action'),
                type: form.prop('method'),
                data: form.serialize(),
                success: function (response) {
                    onSuccess(form, response);
                },
                error: function () {
                    ModalFlash.error('No se pudo procesar la solicitud. Intenta nuevamente.');
                    form.find('button').prop('disabled', false);
                    ModalLoading.remove();
                },
            });

            return false;
        });
    };

    initResettingResendCooldown();

    $body.off('submit.resettingResendCooldown').on('submit.resettingResendCooldown', '#frmCheckoutResettingResend, form.resetting-form', function (e) {
        let $button = $(this).find('.js-resetting-resend-btn');

        if ($button.length && $button.prop('disabled')) {
            e.preventDefault();

            return false;
        }
    });

    $body.off('click.checkoutAuthLoadModal').on('click.checkoutAuthLoadModal', '.load-modal', function (e) {
        e.preventDefault();

        let url = $(this).data('url');
        let $container = $(this).closest('.right-container');

        if ($container.length) {
            $container.load(url);

            return;
        }

        remodal.load(url);
    });

    submitAuthForm('checkoutAuthLogin', '#frmCheckoutLogin', function (form, response) {
        if (response.targetUrl) {
            window.location = response.targetUrl;
        } else if (response.error) {
            ModalFlash.error(response.error);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        } else {
            renderAuthHtml(form, response);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        }
    });

    submitAuthForm('checkoutAuthRegister', '#frmCheckoutRegister', function (form, response) {
        if (response.targetUrl) {
            window.location = response.targetUrl;
        } else if (response.error) {
            ModalFlash.error(response.error);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        } else {
            renderAuthHtml(form, response);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        }
    });

    submitAuthForm('checkoutAuthResetting', '#frmCheckoutResetting', function (form, response) {
        if (response.targetUrl) {
            renderAuthUrl(form, response.targetUrl, function () {
                initResettingResendCooldown();
                ModalLoading.remove();
            });
        } else if (response.error) {
            ModalFlash.error(response.error);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        } else {
            renderAuthHtml(form, response);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        }
    });

    submitAuthForm('checkoutAuthResettingResend', '#frmCheckoutResettingResend', function (form, response) {
        if (response.targetUrl) {
            renderAuthUrl(form, response.targetUrl, function () {
                initResettingResendCooldown();
                ModalLoading.remove();
            });
        } else if (response.error) {
            ModalFlash.error(response.error);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        } else {
            renderAuthHtml(form, response);
            form.find('button').prop('disabled', false);
            ModalLoading.remove();
        }
    });
}

global.checkoutHandler = checkoutHandler;
global.checkoutNotLogged = checkoutNotLogged;
