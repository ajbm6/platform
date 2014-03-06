/*global define*/
define(['jquery', 'underscore'
    ], function ($, _) {
    'use strict';

    /**
     * @export  oroform/js/select2-config
     * @class   oroform.Select2Config
     */
    var Select2Config = function (config, url, perPage, excluded) {
        this.config = config;
        this.url = url;
        this.perPage = perPage;
        this.excluded = excluded;
    };

    Select2Config.prototype = {
        getConfig: function () {
            var self = this;
            // create default AJAX object for AJAX based Select2
            // and if this object was not created in extra config block
            if (this.config.ajax === undefined && this.url) {
                this.config.ajax = {
                    'url': this.url,
                    'data': function (query, page) {
                        return {
                            'page': page,
                            'per_page': self.perPage,
                            'query': query
                        };
                    },
                    'results': function (data, page) {
                        return data;
                    }
                };
            }
            // configure AJAX object if it exists
            if (this.config.ajax !== undefined) {
                if (this.config.initSelection === undefined) {
                    this.config.initSelection = _.bind(this.initSelection, this);
                }
                var filterData = function(data) {
                    if (self.excluded) {
                        var forRemove = [];
                        var results = data.results;
                        for (var i = 0; i < results.length; i++) {
                            if (results[i].hasOwnProperty('id') && self.excluded.indexOf(results[i].id) > -1) {
                                forRemove.push(i);
                            }
                        }
                        for (i = 0; i < forRemove.length; i++) {
                            results.splice(forRemove[i], 1);
                        }
                        data.results = results;
                    }
                    return data;
                };
                var resultsMethod = this.config.ajax.results;
                this.config.ajax.results = function(data, page) {
                    return filterData(resultsMethod(data, page));
                };
                if (this.config.ajax.quietMillis === undefined) {
                    this.config.ajax.quietMillis = 700;
                }
            } else {
                // configure non AJAX based Select2
                if (this.config.minimumResultsForSearch === undefined) {
                    this.config.minimumResultsForSearch = 7;
                }
            }
            // set default values for other Select2 options
            if (this.config.formatResult === undefined) {
                this.config.formatResult = this.format(this.config.result_template || false);
            }
            if (this.config.formatSelection === undefined) {
                this.config.formatSelection = this.format(this.config.selection_template || false);
            }
            if (this.config.escapeMarkup === undefined) {
                this.config.escapeMarkup = function (m) { return m; };
            }
            if (this.config.dropdownAutoWidth === undefined) {
                this.config.dropdownAutoWidth = true;
            }
            return this.config;
        },

        format: function(jsTemplate) {
            var self = this;
            // pre-compile template if it exists
            if (jsTemplate) {
                jsTemplate = _.template(jsTemplate);
            }

            return function (object, container, query) {
                if ($.isEmptyObject(object)) {
                    return undefined;
                }
                var result = '',
                    highlight = function (str) {
                        return object.children ? str : self.highlightSelection(str, query);
                    };
                if (object._html !== undefined) {
                    result = object._html;
                } else if (jsTemplate) {
                    object = _.clone(object);
                    object.highlight = highlight;
                    if (self.config.formatContext !== undefined) {
                        object.context = self.config.formatContext();
                    }
                    result = jsTemplate(object);
                } else {
                    result = highlight(self.getTitle(object, self.config.properties));
                }
                return result;
            };
        },

        initSelection: function(element, callback) {
            var data = element.data('selected-data')
                ? element.data('selected-data')
                : [{'id': element.val(), 'text': element.val()}];

            if (this.config.multiple === true) {
                callback(data);
            } else {
                callback(data.pop());
            }
        },

        highlightSelection: function(str, selection) {
            return str && selection && selection.term ?
                str.replace(new RegExp(selection.term, 'ig'), '<span class="select2-match">$&</span>') : str;
        },

        getTitle: function(data, properties) {
            var title = '', result;
            if (data) {
                if (properties === undefined) {
                    if (data.text !== undefined) {
                        title = data.text;
                    }
                } else {
                    result = [];
                    _.each(properties, function(property) {
                        result.push(data[property]);
                    });
                    title = result.join(' ');
                }
            }
            return title;
        }
    };

    return Select2Config;
});
