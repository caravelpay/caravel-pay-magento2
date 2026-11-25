define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Caravel_Pay/payment/caravelpay',
            // The order is placed first, then the buyer goes to the hosted checkout.
            redirectAfterPlaceOrder: false
        },

        getInstructions: function () {
            var config = window.checkoutConfig.payment.caravelpay;
            return config ? config.instructions : '';
        },

        // '' unless the store passes a fee on to the buyer ("Who pays the fees").
        getFeeNotice: function () {
            var config = window.checkoutConfig.payment.caravelpay;
            return config && config.feeNotice ? config.feeNotice : '';
        },

        // '' for a cart in US dollars; otherwise the line saying the charge is in US dollars.
        getCurrencyNotice: function () {
            var config = window.checkoutConfig.payment.caravelpay;
            return config && config.currencyNotice ? config.currencyNotice : '';
        },

        afterPlaceOrder: function () {
            window.location.replace(url.build(window.checkoutConfig.payment.caravelpay.startUrl));
        }
    });
});
