module.exports = {
   data() {
      return {
        form: new SparkForm({
          isReady: false,
          isPrefill: (!!data && !!data.trade && !!data.trade.hash),
          saveAs: 'trade',
          exchangeId: null,

          ruleId: null,

          buy: 0,
          buyzone: [],

          stoploss: {
            active: true,
            value: 15,
            price: null,
          },

          targets: [
            {
              active: true,
              bid: numeral(0).format('0[.][00000000]'),
              value: 20,
              amount: 100,
            },
            {
              active: false,
              bid: numeral(0).format('0[.][00000000]'),
              value: 30,
              amount: 0,
            },
            {
              active: false,
              bid: numeral(0).format('0[.][00000000]'),
              value: 40,
              amount: 0,
            },
          ],

          trailing: {
            active: true,
            value: 10
          },

          funds: 0,
          percentage: 10.0,
          amount: 0,
          notes: null,
          postTo: '412769817154813952',
          chart: "",
        }),

        limitTarget : 100,

        timeout: null,

        ambitious: false,

        targets: {
          options: {
            height: 10,
            dotSize: 24,

            min: 0,
            max: 100,
            interval: 1,

            speed: 0.2,

            tooltip: 'hover',
            tooltipDir: 'bottom',
            formatter: '{value}%',

            processStyle: {
              'backgroundColor': '#297dd7',
            },
          }
        },

        fund: {
          options: {
            height: 8,
            dotSize: 24,

            min: 0,
            max: 100,
            interval: 25,

            speed: 0.2,

            piecewise: true,
            piecewiseLabel: true,

            tooltip: false,
            formatter: '{value}%',

            processStyle: {
              'backgroundColor': '#297dd7',
            },

            piecewiseStyle: {
              'backgroundColor': '#e9ecef',
              'visibility': 'visible',
              'width': '14px',
              'height': '14px'
            },

            piecewiseActiveStyle: {
              'backgroundColor': '#297dd7'
            },

            labelActiveStyle: {
              "color": "#297dd7"
            },
          }
        },

        stoploss: {
          options: {
            height: 10,
            dotSize: 24,

            min: 1,
            max: 75,
            interval: 1,
            reverse: true,

            speed: 0.2,

            tooltip: 'hover',
            tooltipDir: 'bottom',
            formatter: '-{value}%',

            processStyle: {
              'backgroundColor': '#dc3545',
            },
          },
          value: 0,
          set: false
        },

        exchanges: data.exchanges,

        exchangeRules: [],
        
        rule: false,

        balances: {},

        prices: {},

        errors: {},

        dataLoaded: false,

        validated: false,

        hash: false,

        id: 0,

        signature: false,

        target_0: 0,

        target_1: 0,

        target_2: 0,

        chRule: false,

        once: false,
      };
    },

    created() {
      this.form.targets.forEach(function (item, key) {
        let bid = this.form.buy * ((item.value / 100) + 1);
        this.form.targets[key].bid = numeral(bid).format('0[.][00000000]')
      }.bind(this))
    },

    mounted() {
      if (this.exchanges.length) {
        this.form.exchangeId = this.exchanges[0].id;
        
        if (this.exchanges[0].rules) {
          this.exchangeRules = this.exchanges[0].rules.map((rule) => { 
            rule.name = rule.market + rule.base;
            return rule;
          }).sort(function(a, b) {
            return (a.market + a.base) > (b.market + b.base) ? 1 : -1;
          });
        }

        this.setActiveRule(this.exchangeRules[0]);
        this.balances = this.getBalances(this.form.exchangeId);
        this.prices = this.getPrices(this.form.exchangeId);
        
      } else {
        this.errors = {
          msg: 'No active exchanges'
        };
        $('#modal-trade-error').modal('show')
      } 
            
      this.prefillForm();
      this.listenPrice();
    },

    methods: {
      listenPrice: function() {

        var socket = new WebSocket("wss://stream.binance.com:9443/ws/!ticker@arr");
        
        let self = this;
        socket.onmessage = function(event) {
          if(event.data) {
            let data = JSON.parse(event.data);
            for(var item of data) {
              if (item.s == self.rule.name) {
                self.prices[self.rule.name] = item.c;
              }
            }
          }
        };

        socket.onerror = function(error) {
          console.log("Error ws: " + error.message);
        };
      },

      changeStoploss: function(value, change_sell = false) {
        this.form.stoploss.value = value;
        if (change_sell) {
          this.stoploss.value = numeral(this.form.buy * ((100 - this.form.stoploss.value) / 100)).format('0[.][00000000]');      
        }
      },

      prefillForm: function() {
        if (data.trade) {
          this.form.saveAs = data.trade.saveAs;
          this.form.exchangeId = data.trade.exchange_id;

          exchangeRule = data.trade.rule;
          exchangeRule.name = exchangeRule.market + exchangeRule.base;
          this.changeRule(exchangeRule);

          if (this.form.saveAs != 'trade') {
            this.form.buyzone = data.trade.buyzone;
            this.form.buy = numeral((data.form.buyzone[0] + data.form.buyzone[1]) / 2).format('0[.][00000000]');
          } else {
            this.form.buy = numeral(data.trade.buy).format('0[.][00000000]');
          }
          
          if (this.form.isPrefill) {
            this.form.amount = data.trade.amount;
          }
          // this.setAmount({target: { value: data.trade.amount }});
          
          this.form.stoploss.active = !!data.trade.stoploss_active;
          this.setStoploss({target: { value: numeral(data.trade.stoploss_value).format('0[.][00000000]')}}, true);

          for(let i in data.trade.targets) {
            if (data.trade.targets[i]) {
              this.form.targets[i].active = true;
              this.setTarget({target: {  value: numeral(data.trade.targets[i].bid).format('0[.][00000000]'), dataset: { key: i }}}, true);
              this.form.targets[i].amount =  data.trade.targets[i].amount;
            }
          }

          this.form.trailing.active = data.trade.trailing_active;
          this.form.trailing.value = data.trade.trailing_value;

          if (this.form.isPrefill) {
            this.hash = data.trade.hash;
            this.id = data.trade.id;
            this.signature = data.trade.signature;
          }
          this.form.notes = data.trade.notes;
          this.form.chart = data.trade.chart;
        }
      },
      
      pushTarget : function(target) {
        if (this.form.targets[target].active) {
          for(let index = 0; index < target; index ++) {
            this.form.targets[index].active = true;
          }
        } else {
          for(let index = target; index < this.form.targets.length; index ++) {
            this.form.targets[index].active = false;
          }
        }
        
        // set default amount 
        let actives = this.form.targets.filter(a => a.active == true ).length;
        
        if (actives <= 2) {
          let amount = 100 / actives;
          for(let i in this.form.targets) {
            this.form.targets[i].amount = amount;
          }
        } else {
          this.form.targets[0].amount = 30;
          this.form.targets[1].amount = 30;
          this.form.targets[2].amount = 40;
        }

        this.limitTarget = 0;
        this.form.targets.map(i => { this.limitTarget += i.active ? i.amount * 1 : 0 });
      },
      
      calcTarget : function(target) {
        
        this.form.targets[target].amount = Math.abs(this.form.targets[target].amount);

        let actives = this.form.targets.filter((value, index) => index != target && value.active);
        if (actives && actives.length != 2) {
          let amount = 100 - this.form.targets[target].amount;
          if (this.form.targets[target].amount <= 100 && this.form.targets[target].amount > 0) {
            for(let i in actives) {
              actives[i].amount = amount / actives.length;
            }
          }
        }

        this.limitTarget = 0;
        this.form.targets.map(i => { this.limitTarget += i.active ? i.amount * 1 : 0 });
      },

      setAmount: function(e, now = false) {
        clearTimeout(this.timeout);
        
        var value = e.target.value
        
        let set = (() => {
          if (value >= this.form.funds / this.form.buy) {
            value = this.form.funds / this.form.buy
            e.target.value = value
          } 
          var amount = (value * 100 / (this.form.funds * 1 / this.form.buy));
          this.form.percentage = numeral(amount ? amount : 0).format("0[.][00]")
        }).bind(this)

        if (now) {
          set();
          return
        }

        this.timeout = setTimeout(function () {
          set();
        }.bind(this), 600);

      },

      setTotal: function(e, now = false) {
        clearTimeout(this.timeout);

        var value = e.target.value

        let set = (() => {
          var value = e.target.value
          if (value >= this.form.funds) value = this.form.funds
          var amount = (value / this.form.funds) * 100
          this.form.percentage = numeral(amount ? amount : 0).format("0[.][00]")
        }).bind(this);

        if (now) {
          set();
        }
        
        this.timeout = setTimeout(function () {
          set();
        }.bind(this), 600);
      },

      setStoploss: function(e, now = false) {
        clearTimeout(this.timeout);

        let set = (() => {
          var value = numeral((1 - (e.target.value / this.form.buy)) * 100).format('0[.][00]')
          if (value >= this.stoploss.options.max) value = this.stoploss.options.max
          else if (value <= this.stoploss.options.min) value = this.stoploss.options.min
          this.form.stoploss.value = value;
        }).bind(this)

        if (now) {
          set();
        }

        this.timeout = setTimeout(function () {
          set();
        }.bind(this), 600);
      },

      setTarget: function(e, now = false) {

        clearTimeout(this.timeout);

        let set = (() => {
          var value = numeral((e.target.value / this.form.buy - 1) * 100).format('0[.][00]')
          if (value >= this.targets.options.max) value = this.targets.options.max
          else if (value <= this.targets.options.min) value = this.targets.options.min
          
          this.form.targets[e.target.dataset.key].value = value
          
        }).bind(this)

        if (now) {
          set();
        }

        this.timeout = setTimeout(function () {
          set();
        }.bind(this), 1000);
      },

      submitTrade: function(e) {
        this.validated = false;

        // Set the variables to the correct values
        this.form.amount = this.amount;
        this.form.stoploss.price = this.stoploss.value;
        this.form.targets[0].bid = this.targetOne;
        this.form.targets[1].bid = this.targetTwo;
        this.form.targets[2].bid = this.targetThree;

        if (this.form.targets[0].value * 1 > this.form.targets[1].value * 1 && this.form.targets[0].active && this.form.targets[1].active) {
          this.errors = {
            msg: "You have set target 2 lower than target 1 — Set target 1 lower or target 2 higher and try again"
          };
          $('#modal-trade-error').modal('show')
          return
        } else if (this.form.targets[2].value * 1 < this.form.targets[1].value * 1 && this.form.targets[2].active && this.form.targets[1].active) {
          this.errors = {
            msg: "You have set target 3 lower than target 2 — Set target 2 lower or target 3 higher and try again"
          };
          $('#modal-trade-error').modal('show')
          return
        }
        
        Spark.post('/trade/validate', this.form)
          .then(response => {
            this.validated = true
            return
          })
          .catch(response => {
            if (response.errors) {
              this.errors = response.errors
            } else {
              this.errors = {
                msg: "Something went wrong. Please contact support if this issue keeps popping up"
              };
            }

            $('#modal-trade-error').modal('show')
            return
          })
      },

      send: function(e) {
        let url = '/trade/'

        if (this.form.isPrefill) {
          url += 'update/' + data.trade.id
        } else {
          url += 'send'
        }

        Spark.post(url, this.form)
          .then(response => {
            console.log(response);

            if (response.success) {
              if (response.hash !== undefined) {
                this.id = response.result.id
                this.signature = response.result.signature
                this.hash = response.hash
              }
              this.form.amount = response.result.amount
              $('#modal-trade-save').modal('show')
              this.balances = this.getBalances(this.form.exchangeId);
            } else {
              let message = response.errors.specific_error !== '' ? response.errors.specific_error : response.errors.msg;
              this.errors = {
                msg: message
              };
              $('#modal-trade-error').modal('show');
            }
          })
          .catch(response => {
            if (response.errors['amount'][0]) {
              this.errors = {
                msg: response.errors['amount'][0]
              };
            }

            $('#modal-trade-error').modal('show');
          })
      },

      setActiveRule: function(rule) {
        this.rule = rule;
        this.form.ruleId = rule.id;

        this.computeAmountTarget();
      },

      computeAmountTarget: function() {
        this.$watch('form.buy', function () {
          this.target_0 = numeral(this.form.buy * ((this.form.targets[0].value / 100) + 1)).format('0[.][00000000]')
          this.target_1 = numeral(this.form.buy * ((this.form.targets[1].value / 100) + 1)).format('0[.][00000000]')
          this.target_2 = numeral(this.form.buy * ((this.form.targets[2].value / 100) + 1)).format('0[.][00000000]')
        });
      },

      changeRule: function(rule) {
          this.setActiveRule(rule);
          this.setBalance();
          if (!data.call && !data.trade)
            this.setPrice();
      },

      getBalances: function(exchangeId) {
        axios.get('/exchange/getBalances/'+exchangeId)
          .then(response => {
            if (response.data.success) {
              this.balances = response.data.result;
              this.setBalance();
              this.setBalancesetBalance();
            }
          })
      },

      getPrices: function(exchangeId) {
        axios.get('/exchange/getPrices/'+exchangeId)
          .then(response => {
              if (response.data.success) {
                this.prices = response.data.result;
                if (!data.call && !data.trade)
                  this.setPrice();
                this.dataLoaded = true;
              }
          })
      },

      setBalance: function() {
        if (this.rule && this.balances) {
          this.form.funds = this.balances[this.rule.base].available;
          if (!this.isReady && data.trade) {
            this.setAmount({target: { value: data.trade.amount }});
            this.isReady = true;
          }
        }
      },

      setPrice: function() {
        if (this.rule && this.prices) {
          this.form.buy =  numeral(this.prices[this.rule.market+this.rule.base]).format("0[.][00000000]");
        }
      },

      setDefaultStopLoss: function() {
        if ( ! this.stoploss.set) {
          this.stoploss.value = numeral(this.form.buy * ((100 - this.form.stoploss.value) / 100)).format('0[.][00000000]');
          this.stoploss.set = true;
        }
      },

      calcStopLossInterest: function() {
        if ( ! this.chRule) {
          this.form.stoploss.value = numeral((1 - (this.stoploss.value / this.form.buy)) * 100).format('0[.][00]');
          return;
        }

        this.stoploss.value = numeral(this.form.buy * ((100 - this.form.stoploss.value) / 100)).format('0[.][00000000]');
        this.chRule = false;
      },
      
      setT: function(target, amount) {
        this['target_' + target] = numeral(this.form.buy * ((amount / 100) + 1)).format('0[.][00000000]')
      },

      changeTargets: function() {

        if ( ! this.target_0 || ! this.target_1 || ! this.target_2) {
          this.target_0 = numeral(this.form.buy * ((this.form.targets[0].value / 100) + 1)).format('0[.][00000000]')
          this.target_1 = numeral(this.form.buy * ((this.form.targets[1].value / 100) + 1)).format('0[.][00000000]')
          this.target_2 = numeral(this.form.buy * ((this.form.targets[2].value / 100) + 1)).format('0[.][00000000]')
        }

        for(var i in [0, 1, 2]) {
          var amount = (this['target_' + i] / this.form.buy - 1) * 100;
          this.form.targets[i].value = numeral(amount).format('0[.][00]'); 
        }
      },

      checkRuleInUrl: function() {
        let segments = window.location.pathname.split('/'),
            rule = segments.pop(),
            check = this.exchangeRules.filter((r) => r.name.toLowerCase() == rule.toLowerCase());

        if (check.length) {
          return check.pop();
        }
      },

      setRuleInUrl: function() {
        let check = this.checkRuleInUrl();
        if (check) {
          this.rule = check;
        }
      }

    },

    watch: {
      ambitious: function() {
        if (this.ambitious) this.targets.options.max = 500
        else this.targets.options.max = 100
      },

      rule: function(value) {
          this.setActiveRule(value);
          this.setBalance(); 
          this.setRuleInUrl();
          this.chRule = true;
          if (!data.call)
            this.setPrice();
      },

      validated: function(value) {
          if (value) {
              this.send()
          }
      },

      'form.buy': function() {
        this.setDefaultStopLoss();
        this.calcStopLossInterest();
        
        if ( ! this.once) {
          this.changeTargets();
          this.once = true;
        }
        
      }
    },

    computed: {
      amount: function() {
        return numeral((this.form.funds * (this.form.percentage / 100)) / this.form.buy).format('0[.][00]')
      },

      total: function() {
        return numeral(this.amount * this.form.buy).format("0[.][00000000]");
      },

      targetOne: function() {
        return numeral(this.form.buy * ((this.form.targets[0].value / 100) + 1)).format('0[.][00000000]')
      },

      targetTwo: function() {
        return numeral(this.form.buy * ((this.form.targets[1].value / 100) + 1)).format('0[.][00000000]')
      },

      targetThree: function() {
        return numeral(this.form.buy * ((this.form.targets[2].value / 100) + 1)).format('0[.][00000000]')
      },

      targetOneRRR: function() {
        var gain = (this.targetOne / this.form.buy) - 1
        var loss = (this.form.buy - this.stoploss.value) / this.form.buy
        var result = (gain / loss)
        return result ? numeral(result).format('0[.][00]') : 0
      },

      targetTwoRRR: function() {
        var gain = (this.targetTwo / this.form.buy) - 1
        var loss = (this.form.buy - this.stoploss.value) / this.form.buy
        var result = (gain / loss)
        return result ? numeral(result).format('0[.][00]') : 0
      },

      targetThreeRRR: function() {
        var gain = (this.targetThree / this.form.buy) - 1
        var loss = (this.form.buy - this.stoploss.value) / this.form.buy
        var result = (gain / loss)
        return result ? numeral(result).format('0[.][00]') : 0
      },

      RRR: function() {
        var loss = (this.form.buy - this.stoploss.value) / this.form.buy

        if (this.form.targets[1].active && this.form.targets[2].active) {
          var target1 = ((this.targetOne / this.form.buy) - 1) * (this.form.targets[0].amount / 100)
          var target2 = ((this.targetTwo / this.form.buy) - 1) * (this.form.targets[1].amount / 100)
          var target3 = ((this.targetThree / this.form.buy) - 1) * (this.form.targets[2].amount / 100)
          var gain = target1 + target2 + target3
          var result = (gain/loss)
          return result ? numeral(result).format('0[.][00]') : 0
        } else if (this.form.targets[1].active) {
          var target1 = ((this.targetOne / this.form.buy) - 1) * (this.form.targets[0].amount / 100)
          var target2 = ((this.targetTwo / this.form.buy) - 1) * (this.form.targets[1].amount / 100)
          var gain = target1 + target2
          var result = (gain/loss)
          return result ? numeral(result).format('0[.][00]') : 0
        } else {
          return numeral(Number(this.targetOneRRR)).format('0[.][00]')
        }
      },

      RRRpercentage: function() {
        return numeral((1 / (Number(this.RRR) + 1)) * 100).format('0')
      }
    }  
}