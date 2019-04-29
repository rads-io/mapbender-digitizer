(function ($) {
    "use strict";

    Mapbender.Digitizer = Mapbender.Digitizer || {};

    $.fn.dataTable.ext.errMode = 'throw';


    /**
     * Escape HTML chars
     * @returns {string}
     */
    String.prototype.escapeHtml = function () {

        return this.replace(/[\"&'\/<>]/g, function (a) {
            return {
                '"': '&quot;',
                '&': '&amp;',
                "'": '&#39;',
                '/': '&#47;',
                '<': '&lt;',
                '>': '&gt;'
            }[a];
        });
    };


    $.widget("mapbender.mbDigitizer", {

        options:  {
            classes: {},
            create: null,
            debug: false,
            disabled: false,
            fileURI: "uploads/featureTypes",
            schemes: {},
            target: null,
        },
        schemes: null,
        map: null,

        styles: {
            'default': {
                strokeWidth: 1,
                strokeColor: '#6fb536',
                fillColor: "#6fb536",
                fillOpacity: 0.3
                //, label: '${label}'
            },
            'select': {
                strokeWidth: 3,
                fillColor: "#F7F79A",
                strokeColor: '#6fb536',
                fillOpacity: 0.5,
                graphicZIndex: 15
            },
            // 'selected': {
            //     strokeWidth: 3,
            //     fillColor: "#74b1f7",
            //     strokeColor: '#b5ac14',
            //     fillOpacity: 0.7,
            //     graphicZIndex: 15
            // },
            'copy': {
                strokeWidth: 5,
                fillColor: "#f7ef7e",
                strokeColor: '#4250b5',
                fillOpacity: 0.7,
                graphicZIndex: 1000
            },
            'unsaved': {
                strokeWidth: 3,
                fillColor: "#FFD14F",
                strokeColor: '#F5663C',
                fillOpacity: 0.5
            },

            'invisible': {
                strokeWidth: 1,
                fillColor: "#F7F79A",
                strokeColor: '#6fb536',
                display: 'none'
            },

            'labelText': {
                strokeWidth: 0,
                fillColor: '#cccccc',
                fillOpacity: 0,
                strokeColor: '#5e1a2b',
                strokeOpacity: 0,
                pointRadius: 15,
                label: '${label}',
                fontSize: 15
            },
            'labelTextHover': {
                strokeWidth: 0,
                fillColor: '#cccccc',
                strokeColor: '#2340d3',
                fillOpacity: 1,
                pointRadius: 15,
                label: '${label}',
                fontSize: 15
            }

        },
        printClient: null,

        _create: function () {

            var widget = this.widget = this;
            var element = widget.element;

            widget.id = element.attr("id");

            if (!Mapbender.checkTarget("mbDigitizer", widget.options.target)) {
                return;
            }

            Mapbender.elementRegistry.onElementReady(widget.options.target, $.proxy(widget._setup, widget));
            Mapbender.elementRegistry.waitCreated('.mb-element-printclient').then(function(printClient){
                widget.printClient = printClient;
                $.extend(widget.printClient ,Mapbender.Digitizer.printPlugin);
            }.bind(this));


            widget.dataManager = Mapbender.elementRegistry.listWidgets()['mapbenderMbDataManager'];

            var qe = new Mapbender.Digitizer.QueryEngine(widget);
            widget.query = qe.query;

            widget.spinner = new function() {
                var spinner = this;

                spinner.openRequests = 0;

                var $parent = $('#'+widget.id).parents('.container-accordion').prev().find('.tablecell').prepend("<div class='spinner' style='display:none'></div>");
                spinner.$element = $parent.find(".spinner");

                spinner.addRequest = function() {
                    spinner.openRequests++;
                    if (spinner.openRequests >= 1) {
                        spinner.$element.show();
                    }
                };

                spinner.removeRequest = function() {
                    spinner.openRequests--;
                    if (spinner.openRequests === 0) {
                        spinner.$element.hide();
                    }
                };


            };


        },


        _setup: function () {
            var widget = this;
            var element = $(widget.element);
            var options = widget.options;

            widget.selector = $("select.selector", element);

            console.log(widget.selector,1);

            widget.map = $('#' + options.target).data('mapbenderMbMap').map.olMap;

            // TODO Kanonen->Spatzen: refactoring
            var initializeActivationContainer = function () {

                var containerInfo = new MapbenderContainerInfo(widget, {
                    onactive: function () {
                        widget.activate();
                    },
                    oninactive: function () {
                        widget.deactivate();
                    }
                });

                return containerInfo;

            };

            var initializeSelectorOrTitleElement = function () {

                var options = widget.options;
                var element = $(widget.element);
                var titleElement = $("> div.title", element);


                widget.hasOnlyOneScheme = _.size(options.schemes) === 1;

                if (widget.hasOnlyOneScheme) {
                    titleElement.html(_.toArray(options.schemes)[0].label);
                    widget.selector.hide();
                } else {
                    titleElement.hide();
                }
            };


            var createSchemes = function () {

                var rawSchemes = widget.options.schemes;
                widget.schemes = {};
                _.each(rawSchemes, function (rawScheme, schemaName) {
                    rawScheme.schemaName = schemaName;
                    widget.schemes[schemaName] = new Mapbender.Digitizer.Scheme(rawScheme, widget);
                });

                if (!widget.hasOnlyOneScheme) {
                    widget.schemes['all'] = new Mapbender.Digitizer.AllScheme({label: 'all geometries', schemaName: 'all'}, widget);
                }
            };

            var createMapContextMenu = function () {
                var map = widget.map;


                var options = {
                    selector: 'div',
                    events: {
                        show: function (options) {
                            return widget.allowUseMapContextMenu(options);
                        }
                    },
                    build: function (trigger, e) {
                        return widget.buildMapContextMenu(trigger, e);
                    }
                };

                $(map.div).contextMenu(options);

            };

            var createElementContextMenu = function () {
                var element = $(widget.element);

                var options = {
                    position: function (opt, x, y) {
                        opt.$menu.css({top: y, left: x + 10});
                    },
                    selector: '.mapbender-element-result-table > div > table > tbody > tr',
                    events: {
                        show: function (options) {
                            return widget.allowUseElementContextMenu(options);
                        }
                    },
                    build: function (trigger, e) {
                        return widget.buildElementContextMenu(trigger, e);
                    }
                };

                $(element).contextMenu(options);

            };

            var initializeSelector = function () {
                var selector = widget.selector;

                selector.on('change', widget.onSelectorChange);

            };

            var initializeMapEvents = function () {
                var map = widget.map;

                map.events.register("moveend", this, function () {
                    widget.getData();
                });
                map.events.register("zoomend", this, function () {
                    widget.getData(true);
                });
                map.resetLayersZIndex();
            };

            initializeActivationContainer();

            initializeSelectorOrTitleElement();

            createSchemes();

            createMapContextMenu();

            createElementContextMenu();

            widget.onSelectorChange = function () { // Do not implement in prototype because of static widget access
                var selector = widget.selector;

                var option = selector.find(":selected");
                var newSchema = option.data("schemaSettings");

                widget.deactivateSchema();

                newSchema.activateSchema();
            };

            initializeSelector();

            initializeMapEvents();

            widget._trigger('ready');

        },

        buildMapContextMenu: function () {
            console.warn("This method should be overwritten");
        },

        allowUseMapContextMenu: function () {
            console.warn("This method should be overwritten");
        },



        buildElementContextMenu: function (trigger, e) {
            console.warn("This method should be overwritten");

        },

        allowUseElementContextMenu: function (options) {
            console.warn("This method should be overwritten");
        },

        getData: function(zoom) { },


        getBasicSchemes: function() {
            var widget = this;

            return _.pick(widget.schemes,function(value,key){
                return key !== "all";
            });
        },


        getSchemaByName: function (schemaName) {
            var widget = this;
            var scheme = widget.getBasicSchemes()[schemaName];
            if (!scheme) {
                throw new Error("No Basic Scheme exists with the name "+schemaName);
            }
            return scheme;
        },


        deactivateSchema: function() {},

        activate: function () {
            var widget = this;
            widget.options.__disabled = false;
            widget.onSelectorChange();
        },

        deactivate: function () {
            var widget = this;
            widget.options.__disabled = true;
            widget.deactivateSchema();
        },


        // TODO muss getetest werden
        refreshConnectedDigitizerFeatures: function (featureTypeName) {
            var widget = this;
            $(".mb-element-digitizer").not(".mb-element-data-manager").each(function (index, element) {
                var schemes = widget.schemes;
                schemes[featureTypeName] && schemes[featureTypeName].layer && schemes[featureTypeName].layer.getData();
            })


        },


    });

})(jQuery);
