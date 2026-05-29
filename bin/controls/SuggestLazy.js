/* jshint esversion: 6 */
/**
 * Lightweight proxy that loads the full suggest control only after the search
 * field is used.
 */
define('package/quiqqer/search/bin/controls/SuggestLazy', [
    'qui/controls/Control'
], function (QUIControl) {
    "use strict";

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/search/bin/controls/SuggestLazy',

        Binds: [
            '$onImport',
            '$loadSuggest'
        ],

        options: {
            suggestControl: 'package/quiqqer/search/bin/controls/Suggest'
        },

        initialize: function (options) {
            this.parent(options);

            this.$Suggest = null;
            this.$Promise = null;
            this.$Input = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: on import
         *
         * @param {Object} self
         * @param {HTMLInputElement} Elm
         */
        $onImport: function (self, Elm) {
            this.$Input = Elm;
            Elm.autocomplete = 'off';

            Elm.addEventListener('focus', this.$loadSuggest, {once: true});
            Elm.addEventListener('pointerdown', this.$loadSuggest, {once: true});
            Elm.addEventListener('input', this.$loadSuggest, {once: true});
        },

        /**
         * Loads and imports the real suggest control once it is needed.
         *
         * @return {Promise}
         */
        $loadSuggest: function () {
            if (this.$Promise) {
                return this.$Promise;
            }

            this.$Promise = new Promise((resolve, reject) => {
                require([this.getAttribute('suggestControl')], (Suggest) => {
                    const Elm = this.$Input || this.getElm();

                    Elm.removeEventListener('focus', this.$loadSuggest);
                    Elm.removeEventListener('pointerdown', this.$loadSuggest);
                    Elm.removeEventListener('input', this.$loadSuggest);

                    this.$Suggest = new Suggest(this.getAttributes());
                    this.$Suggest.addEvents({
                        onSuggestionClick: (text, url, SuggestControl) => {
                            this.fireEvent('suggestionClick', [
                                text,
                                url,
                                SuggestControl
                            ]);
                        }
                    });

                    this.$Suggest.imports(Elm);
                    resolve(this.$Suggest);
                }, reject);
            });

            return this.$Promise;
        }
    });
});
