
(function($) {

    const _ = {

        default: {

            init() {},

        },

    };

    for (let f in _) if ((_[f] instanceof Object) && _[f].hasOwnProperty("init")) _[f].init();

})(jQuery);
