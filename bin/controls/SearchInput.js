/**
 * SearchInput JavaScript Control
 *
 * @module package/quiqqer/search/bin/controls/SearchInput
 * @author www.pcsg.de (Henning Leutz)
 * @author www.pcsg.de (Patrick Müller)
 *
 * @require qui/QUI
 * @require qui/controls/Control
 * @require Ajax
 */
define('package/quiqqer/search/bin/controls/SearchInput', [

    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Select',
    'utils/Controls',

    'Locale',

    'package/quiqqer/search/bin/controls/SearchExtension'

    //'css!package/quiqqer/search/bin/controls/SearchInput.css'

], function (QUILoader, QUIButton, QUISelect, QUIControlUtils, QUILocale, SearchExtension) {
    "use strict";

    var lg = 'quiqqer/search';

    return new Class({

        Extends: SearchExtension,
        Type   : 'package/quiqqer/search/bin/controls/SearchInput',

        Binds: [
            '$onImport',
            '$toggleSettings',
            '$submit'
        ],

        options: {
            suggestsearch    : 'off',
            showfieldsettings: true,
            delay            : 300
        },

        initialize: function (options) {
            this.parent(options);

            this.$SearchInput       = null;
            this.$SettingsElm       = null;
            this.$SearchFieldSelect = null;
            this.$Elm               = null;
            this.Loader             = new QUILoader();

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var self = this;

            this.$Elm = this.getElm();

            this.Loader.inject(this.$Elm);

            this.$SearchInput = this.$Elm.getElement(
                '.quiqqer-search-searchinput-input'
            );

            this.$SettingsElm = this.$Elm.getElement(
                '.qui-search-searchinput-settings'
            );

            this.$searchTerms = this.$parseSearchTerms();

            // Initialize suggest input event (if option is set)
            QUIControlUtils.getControlByElement(this.$SearchInput).then(function (SuggestControl) {
                SuggestControl.addEvents({
                    onSuggestionClick: function (suggestion, url, Control) {
                        switch (self.getAttribute('suggestsearch')) {
                            case 'clicktosite':
                                window.location = url;
                                break;

                            default:
                                Control.$hideResults();
                                self.$searchTerms       = [suggestion];
                                self.$SearchInput.value = suggestion;
                                self.$Search.search();
                        }
                    }
                });
            }, function () {
                // nothing
            });

            this.$initSearchFieldSettings();

            // form
            this.$Elm.getElement('form').addEvents({
                submit: function (event) {
                    event.stop();
                    self.$submit();
                }
            });
        },

        /**
         * Initialize search field settings (user can select which fields to search)
         */
        $initSearchFieldSettings: function () {
            if (!this.getAttribute('showfieldsettings')) {
                return;
            }

            var self = this;

            // config toggle btn
            //new QUIButton({
            //    'class': 'quiqqer-search-searchinput-settings-btn',
            //    icon   : 'fa fa-gears',
            //    title  : 'Sucheinstellungen',   // @todo translation
            //    events : {
            //        onClick: this.$toggleSettings
            //    }
            //}).inject(
            //    this.$Elm.getElement(
            //        '.quiqqer-search-searchinput-submit'
            //    ),
            //    'after'
            //);

            this.$SearchFieldSelect = new QUISelect({
                'class'              : 'quiqqer-search-searchinput-searchfieldselect',
                placeholderText      : QUILocale.get(
                    lg,
                    'controls.searchinput.searchfieldselect.placeholder'
                ),
                placeholderSelectable: false,
                multiple             : true,
                checkable            : true
            });

            var settingInputs = this.$SettingsElm.getElements(
                '.qui-search-searchinput-settings-setting'
            );

            var setValues = [];

            settingInputs.each(function (Input) {
                var fieldLabel;
                var field = Input.value;

                switch (field) {
                    case 'AND':
                        fieldLabel = QUILocale.get(lg, 'tpl.search.' + field.toLowerCase() + '.type');
                        break;

                    default:
                        fieldLabel = QUILocale.get(lg, 'tpl.search.field.' + field);
                }

                self.$SearchFieldSelect.appendChild(
                    fieldLabel,
                    field,
                    false
                );

                if (Input.checked) {
                    setValues.push(field);
                }
            });

            this.$SearchFieldSelect.inject(
                this.$Elm.getElement(
                    '.quiqqer-search-searchinput-submit'
                ),
                'after'
            );

            this.$SearchFieldSelect.setValues(setValues);
        },

        /**
         * Parses search params from settings
         *
         * @return {Object} - Search params
         */
        $parseSearchParams: function () {
            var SearchParams = {
                searchType  : 'OR',
                searchFields: [],
                sheet       : 1 // start search from beginning
            };

            // search fields
            if (this.$SearchFieldSelect) {
                var searchFields = this.$SearchFieldSelect.getValue();

                if (searchFields.contains('AND')) {
                    SearchParams.searchType = 'AND';
                }

                SearchParams.searchFields = searchFields;
            } else {
                var settingInputs = this.$SettingsElm.getElements(
                    '.qui-search-searchinput-settings-setting'
                );

                settingInputs.each(function (Input) {
                    switch (Input.getProperty('name')) {
                        case 'searchType':
                            if (Input.checked) {
                                SearchParams.searchType = 'AND';
                            }
                            break;

                        default:
                            if (Input.checked) {
                                SearchParams.searchFields.push(Input.value);
                            }
                    }
                });
            }

            return SearchParams;
        },

        /**
         * Parse the current input value into search terms
         *
         * @return {Array}
         */
        $parseSearchTerms: function () {
            var search = this.$SearchInput.value.trim();

            if (!search) {
                return [];
            }

            return search.split(/\s+/);
        },

        /**
         * Submit search
         */
        $submit: function () {
            var self = this;

            this.$searchTerms = this.$parseSearchTerms();

            this.Loader.show();

            this.$Search.setSearchParams(this.$parseSearchParams());

            this.$Search.search().then(function () {
                self.$setUri({
                    searchterms: self.$searchTerms.join(',')
                });

                self.Loader.hide();
            });
        },

        /**
         * Toggle display of search settings
         */
        $toggleSettings: function () {
            var displayStatus = this.$SettingsElm.getStyle('display');

            if (displayStatus === 'none') {
                this.$SettingsElm.setStyle('display', 'block');

                return;
            }

            this.$SettingsElm.setStyle('display', 'none');
        }
    });
});
