<template id="vue-chart-template">
    <div class="container-fluid d-flex flex-column ">
        <hr class="w-100 mb--20"/>
        <div class="row">
{{--            <div class="col-12">--}}
{{--                <h4>@{{ description }}</h4>--}}
{{--            </div>--}}
            <div class="col-12  mb-3" :class="Object.keys(seriesContext).length > 0?'col-lg-9':'col-lg-12'">
                <div class="row border border-primary mb-2" v-if="Object.keys(filtersContext).length > 0">
                    <div class="col col-12 text-center">
                        <h6 class="my-2 font-weight-bold">Filtra per</h6>
                    </div>
                    <div class="col col-12 col-lg-6 mb-2" v-for="(ctx,key) in filtersContext">
                        <div class="text-center font-weight-medium">@{{key}}</div>
                        <select class="form-control" :name="key" v-model="filters[key]" v-on:change="changeMisura($event)"
                                filtro-type="left" :multiple="isMultidimensionale('left',key)">
                            <option v-for="(label,keyLabel) in ctx.domainValues" :value="keyLabel">@{{label}}</option>
                        </select>
                    </div>

                </div>
                <div class="row">
                    <div class="col-12 bg-white shadow-primary-xs rounded p-2 ">

                        <div :id="chart_id" class="w-100 rounded">

                        </div>

                    </div>
                    <div class="col-12 pt--2" v-for="nota in getNote()">
                        <i>@{{nota}}</i>
                    </div>
                </div>
            </div>
            <div v-if="Object.keys(seriesContext).length > 0" class="col-12 col-lg-3 d-flex flex-column" >
                <div class="border border-success" >
                    <h6 class="text-center my-2 font-weight-bold">Serie visualizzate</h6>
                    <ul class="list-group overflow-auto border-none dpa-chart">

                        <li v-for="(serieContext,serieName) in seriesContext" class="list-group-item pt-3 pb-4" :key="serieName">
                            <h6 class="text-center mb-2 pb-1 border-bottom">
                                <span  v-if="isMultidimensionale('top',serieName)" title="lascia selezionato solo un valore">
                                    <button style="font-size: 0.7rem;" class="btn btn-sm btn-outline-success py-0 px-1 m-0" v-on:click="deselezionaTutto(serieName)">
                                        <span class="text-italic" >-</span>
                                    </button>&nbsp;
                                    @{{serieName}}
                                </span>
                            </h6>
                            <template v-if="isMultidimensionale('top',serieName)">
                                <div v-if="Object.keys(serieContext.domainValues).length == 1">
                                    @{{Object.values(serieContext.domainValues)[0]}}
                                </div>
                                <div v-else class="d-flex" v-for="(serieLabel,serieValue) in serieContext.domainValues">

                                    <div class="badge badge-success badge-soft badge-ico-sm rounded-circle float-start"></div>

                                    <label class="form-checkbox form-checkbox-success">
                                        <input type="checkbox" :name="serieName"  :value="serieValue"  v-on:change="changeMisura($event)" v-model="series[serieName]" filtro-type="top">
                                        <i></i> <img src="">
                                    </label>


                                    <div class="pl--12">
                                        <p class="text-dark font-weight-medium m-0">
                                            @{{serieLabel}}
                                        </p>
                                    </div>

                                </div>
                            </template>
                            <template v-else>
                                <div v-if="Object.keys(serieContext.domainValues).length == 1">
                                    @{{Object.values(serieContext.domainValues)[0]}}
                                </div>
                                <div v-else class="d-flex" v-for="(serieLabel,serieValue) in serieContext.domainValues">

                                    <div class="badge badge-success badge-soft badge-ico-sm rounded-circle float-start"></div>

                                    <label class="form-radio form-radio-success">
                                        <input type="radio" :serie-name="serieName" :value="serieValue" v-model="series[serieName]"  v-on:change="changeMisura($event)" :filtro-type="top">
                                        <i></i> <img src="">
                                    </label>


                                    <div class="pl--12">
                                        <p class="text-dark font-weight-medium m-0">
                                            @{{serieLabel}}
                                        </p>
                                    </div>

                                </div>
                            </template>

                        </li>
                        <li v-for="(serieValue,serieName) in getFixedSeries()" class="list-group-item pt-3 pb-3" :key="serieName">
                            <h6 class="text-center mb-2 pb-1 border-bottom">@{{serieName}}</h6>
                            <div>@{{serieValue}}</div>
                        </li>
                    </ul>
                    <!-- /Aero List -->

                </div>

{{--                <div class="flex-grow-1"></div>--}}
{{--                <div class="pb-4 mt--10">--}}
{{--                    <label class="form-switch form-switch-pill form-switch-danger d-block">--}}
{{--                        <input type="checkbox" value="1" v-on:change="toggleLayer('zone')">--}}
{{--                        <i data-on="&#10004;" data-off="&#10005;"></i>--}}
{{--                        <span>Mostre tutte le zone interdette</span>--}}
{{--                    </label>--}}
{{--                </div>--}}
            </div>

            <div class="flex-grow-1">

            </div>
        </div>
    </div>
</template>

<script>
    function vueChartInit(container,data) {
        //var c = new Vue.options.components['vue-map']();
        var id = 'vue-chart-' + Math.floor(Math.random() * 10000);
        //console.log('conatiner',container);//jQuery(container).length,jQuery(container).html())
        jQuery(container).attr('id',id)
        var vChart = new Vue({
            el : '#'+id,
            template : '#vue-chart-template',
            mixins: [GraficiMixin],
            mounted() {
                var that = this;
                that.load();
            },
            data() {
                var d = {}
                for (var k in data) {
                    d[k] = data[k];
                }
                var defaultData = {
                    type : 'chart',
                    comuni: [],
                    description:'',
                    filters : {},
                    series: {},
                    json : {},
                    name : null,
                    chart_id : id + '_chart',
                    colors : {},
                    filtersContext : {},
                    seriesContext : {},
                    primaVolta : true,
                    token_split_filters:'###',
                    cardinalitaSerie:{},
                    cardinalitaFiltri:{},
                }
                var mergedData = Object.assign(defaultData,d);
                console.log('mergedData',mergedData)
                return mergedData;
            },
            methods : {
                deselezionaTutto(serieName) {
                    var that = this;
                    let first = that.series[serieName][0]
                    that.series[serieName] = [first];
                    that.load();
                },
                imageData() {
                    return this.chart.getImageURI();
                },
                showChart() {
                    var that = this;
                    var json = that.json;
                    console.log('JSON CHART',json);
                    var graphicData = [];
                    var keys = Object.keys(json.result.values);
                    var title = ('titolo' in that)?that.titolo:json.result.description;
                    graphicData.push([''].concat(keys));
                    var keyValues = Object.keys(json.result.values[keys[0]]);
                    for (var kv=0;kv<keyValues.length;kv++) {
                        graphicData.push(that._getLeftValues(keyValues[kv]))
                        //graphicData.push([keyValues[kv]]);
                        for (var k in json.result.values) {
                            graphicData[kv+1].push(parseFloat(json.result.values[k][keyValues[kv]].total));
                        }
                    }
                    var height = 600;
                    if (that.chartType =='chart-o') {
                        var len = graphicData.length;
                        if (len > 7){
                            height += (len-7)*25;
                        }
                        console.log('len',len,height);
                    }

                    jQuery('#'+that.chart_id).css('height',height+'px');

                    function __drawVisualization() {
                        // Some raw data (not necessarily accurate)

                        var leftSeries = json.result.leftSeries;
                        var leftKeys = Object.keys(leftSeries);
                        var topSeries = json.result.topSeries;
                        console.log(topSeries,leftSeries,'graphicData',graphicData)
                        var data = google.visualization.arrayToDataTable(graphicData);

                        var domContainer = jQuery('#'+that.chart_id)[0];
                        //that.jQe(that.container).css('width',w).css('height',h);
                        var googleChartType = {
                            'chart-o':'BarChart',
                            'chart' : 'ComboChart',
                            'line' : 'ComboChart'
                        }
                        var chartType = that.chartType;
                        var vAxis = json.result.extra.tipo_valore;
                        var hAxis = leftKeys[0] ;
                        var seriesType = 'bars';
                        switch (chartType) {
                            case 'chart-o':
                                var tmp = vAxis;
                                vAxis = hAxis;
                                hAxis = tmp
                                break;
                            case 'line':
                                seriesType = 'line'
                                break;
                        }
                        console.log('google chart type ',googleChartType[chartType],seriesType)
                        console.log('hAixs',hAxis,'vAx',vAxis);
                        var options = {
                            title: title,
                            vAxis: {
                                title: vAxis,
                                minValue : (json.result.min+"")?json.result.min:0
                            },
                            hAxis: {
                                title: hAxis,
                                //slantedText : true,
                                slantedTextAngle : 90,
                                titleTextStyle : {
                                    bold:true
                                }
                            },
                            seriesType: seriesType,
                            //curveType : 'function',
                            //series: {5: {type: 'bar'}},
                            //width : w,
                            height : height,
                            colors : schema_colori[that.schemaColor] || schema_colori['default']

                        };

                        if (json.result.max) {
                            options.vAxis.maxValue = json.result.max
                        }
                        console.log('OPTIONS',options);
                        var chart = new google.visualization[googleChartType[chartType]](domContainer);

                        that.chart = chart;


                        switch (json.result.extra.tipo_valore) {
                            // case 'percentuale':
                            // case 'valore':
                            default:
                                var pattern = '0.';
                                var extra = json.result.extra;
                                if (extra.tipo_valore.toLowerCase() == 'percentuale') {
                                    pattern = pattern.padEnd(2+extra.decimali,'0');
                                } else {
                                    if (extra.tipo=='integer' || extra.decimali === 0) {
                                        pattern = '0'
                                    } else {
                                        pattern = pattern.padEnd(2+extra.decimali,'0');
                                    }
                                }

                                //alert(pattern + json.result.extra.decimali);
                                var formatter = new google.visualization.NumberFormat({
                                    pattern: pattern,
                                    suffix: extra.suffisso,
                                    prefix : extra.prefisso,
                                });

                                // format number columns
                                for (var i = 1; i < data.getNumberOfColumns(); i++) {
                                    formatter.format(data, i);
                                }
                        }

                        chart.draw(data, options);

                        that.imageLink = chart.getImageURI();
                        // plugin multiselect
                        that.enableMultiSelect();
                    }


                    google.charts.load('current', {'packages':['corechart']});
                    google.charts.setOnLoadCallback(__drawVisualization);
                },
                // changeContext(event) {
                //     var that = this;
                //     var target = event.target;
                //     console.log('name',target.name,target.value);
                //     //that.context[target.name] = target.value;
                //     that.filters[target.name] = target.value;
                //     that.load();
                // },

                /**
                 * in caso la tabella excel ha piu' di una serie left va splittato il valore usando il separatoreLeft
                 * @param compactValue
                 * @return {*[]|*}
                 * @private
                 */
                _getLeftValues(compactValue) {
                    var that = this;
                    var tmp= compactValue.split(that.json.result.separatoreLeft);
                    var val = "";
                    for (var i in tmp) {
                        val += tmp[i] + ' ';
                    }
                    return [val];
                },


                initObjectData() {
                    var that = this;
                    var json = that.json;
                    that.description = that.titolo?that.titolo:json.result.description;
                    that.filtersContext = json.result.filtersContext || {};
                    that.seriesContext = json.result.seriesContext || {}; //Object.keys(that.json.result.values);
                    for (var k in that.seriesContext) {
                        that.cardinalitaSerie[k] = that.seriesContext[k].cardinalita;
                    }
                    for (var k in that.filtersContext) {
                        that.cardinalitaFiltri[k] = that.filtersContext[k].cardinalita;
                    }
                    that.series = {};
                    // topContext e leftContext sono i filtri che mi arrivano dall'attributo del div
                    for (var k in that.topContext) {
                        var key = k.toLowerCase();
                        console.log('serie',key,'multidimensionale',that.isMultidimensionale('top',key),that.topContext)
                        if (that.isMultidimensionale('top',key))
                            that.series[key] = json.result.currentSeries[key]
                        else
                            that.series[key] = json.result.currentSeries[key][0]
                    }
                    console.log('OKKKKK')
                    for (var k in that.leftContext) {
                        k = k.toLowerCase();
                        console.log('k',k)
                        if (that.isMultidimensionale('left',k))
                            that.filters[k] = json.result.currentFilters[k]
                        else
                            that.filters[k] = json.result.currentFilters[k][0]
                    }
                    that.primaVolta = false;
                }
            }
        })
        return vChart;
    }
</script>
