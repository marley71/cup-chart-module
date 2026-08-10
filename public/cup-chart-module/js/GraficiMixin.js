const GraficiMixin = {
    methods: {
        getSelectedSeries() {
            var that = this;
            var s = {

            }
            for (var k in that.series) {
                if (that.isMultidimensionale('top',k))
                    s[k] = that.series[k].join(',');
                else
                    s[k] = that.series[k]
            }

            console.log('s',s,'series');
            return s;
        },
        getSelectedFilters() {
            var that = this;
            var s = {

            }
            for (var k in that.filters) {
                if (that.isMultidimensionale('left',k))
                    s[k] = that.filters[k].join(',');
                else
                    s[k] = that.filters[k]
            }

            console.log('s',s,'filters');
            return s;
        },
        isMultidimensionale(type,keyName) {
            var that = this;
            var ctx = '';

            switch (type) {
                case 'top':
                    ctx =  that.cardinalitaSerie[keyName]?that.cardinalitaSerie[keyName]:'';
                    break;
                case 'left':
                    ctx =  that.cardinalitaFiltri[keyName]?that.cardinalitaFiltri[keyName]:'';
                    break;
            }
            //console.log('ctx',ctx);
            var multi = (ctx.indexOf('*') >= 0)
            //console.log('type',type,keyName,'ismulti',multi);
            return multi;
        },
        getNote() {
            if (this.json.result) {
                return this.json.result.extra.note;
            }
            return [];
        },
        enableMultiSelect() {
            var that = this;
            for (var k in that.leftContext) {
                var keyglob = k.toLowerCase();
                if (that.isMultidimensionale('left',keyglob)) {
                    const key = keyglob;
                    
                    jQuery('select[name="' + key + '"]').addClass('d-none');
                    jQuery('select[name="' + key + '"]').multiselect({
                        onChange: function(option, checked, select) {
                            console.log('key',key,checked,select,option.val());
                            if (checked)
                                that.filters[key].push(option.val());
                            else {
                                var selectedOptions = $('select[name="' + key + '"] option:selected');
                                if (selectedOptions.length == 0) {
                                    console.warn('selectOptions lenght zero',selectedOptions)
                                    jQuery('select[name="' + key + '"]').multiselect('select', option.val());
                                    return ;
                                }
                                // trasformo in stringhe per evitare l'errore di indexOf in caso di interi con option.val() che e' sempre una stringa
                                var ff = that.filters[key].map(String);
                                var ind = ff.indexOf(option.val());
                                //var ind = that.filters[key].indexOf(option.val());
                                console.log('ind',ind);
                                if (ind >= 0) {
                                    that.filters[key].splice(ind,1);
                                }
                            }
                            that.load();
                        },
                        buttonText: function(options, select) {
                            if (options.length === 0) {
                                return 'Nessuna opzione selezionata';
                            }
                            else if (options.length > 3) {
                                return '(' + options.length + ') voci selezionate';
                            }
                            else {
                                var labels = [];
                                options.each(function() {
                                    if ($(this).attr('label') !== undefined) {
                                        labels.push($(this).attr('label'));
                                    }
                                    else {
                                        labels.push($(this).html());
                                    }
                                });
                                return labels.join(', ') + '';
                            }
                        }
                    });
                    jQuery('.multiselect-native-select').find('.btn-group').addClass('w-100');
                    jQuery('.multiselect-container').addClass('w-100');
                }
            }

        },
        load() {
            var that = this;
            console.log(that.primaVolta,'load distribuzione filters',that.filters,'series',that.series);
            var series = that.topContext;
            var filters = that.leftContext;
            if (! that.primaVolta) {
                series = that.getSelectedSeries();
                filters = that.getSelectedFilters();
            }
            console.log('load',series,filters);
            jQuery.get('/distribuzione/'+that.resourceId+'/'+that.resourceType,{filters : filters,series:series},function (json) {
                if (json.error) {
                    console.error('errore',json.msg);
                    return ;
                }
                that.json = json;
                console.log('ricevuto',json);

                if (that.primaVolta) {
                    that.initObjectData();
                }
                that.showChart();


            }).fail(function (e) {
                console.error(e);
            })
        },
        changeMisura(event) {
            var that = this;
            // evito che l'ultima checkbox venga uncheccata
            var serieName = jQuery(event.target).attr('name');
            var type = jQuery(event.target).attr('filtro-type');
            console.log('changeMisura',type,serieName);
            if (type == 'top') {
                if (that.isMultidimensionale(type,serieName)) {
                    var len = jQuery("input[name='" +  serieName + "']:checked").length;
                    if (len === 0) {
                        jQuery(event.target).prop('checked', true);
                        return ;
                    }
                }
            }

            that.load();

        },
        /**
         * ritorna le serie che non possono essere scelte ma sono fissate
         */
        getFixedSeries(){
            var that = this;
            var seriesContextKeys = Object.keys(that.seriesContext);
            var fixedSeries = {};
            var currentSeries = that.json.result.currentSeries;
            for (var k in currentSeries) {
                if (seriesContextKeys.indexOf(k) < 0) {
                    fixedSeries[k] = currentSeries[k][0]
                }
            }
            console.log('fixedSeries',fixedSeries);
            return fixedSeries;
        }
    }
}
