define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'caravelpay',
        component: 'Caravel_Pay/js/view/payment/method-renderer/caravelpay-method'
    });

    return Component.extend({});
});
