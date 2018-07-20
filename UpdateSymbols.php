<?php

namespace App\Console\Commands\Binance;

use Illuminate\Console\Command;
use App\Interactions\Binance\Client as Binance;
use App\ExchangesRule;

class UpdateSymbols extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'binance:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the symbols from Binance';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new Binance();
        $client->initAPI(['key' => getenv('BINANCE_KEY'), 'secret' => getenv('BINANCE_SECRET')]);
        $exchangeInfo = $client->exchangeInfo();

        /**
         * Check if all tickers from the database match
         * a. Delete the ones that are in the database and not in the array
         * b. Add the ones that are in the array but not in the database
         * c. Update the ones that changed from last time
         */
        
        
        foreach ($exchangeInfo['result']['symbols'] as $info) {
            $symbols[$info['symbol']] = [
                'exchange_id'       =>  1,
                'market'            =>  $info['baseAsset'],
                'base'              =>  $info['quoteAsset'],
                'min_amount'        =>  $info['filters'][1]['minQty'],
                'max_amount'        =>  $info['filters'][1]['maxQty'],
                'step_size'         =>  $info['filters'][1]['stepSize'],
                'min_price'         =>  $info['filters'][0]['minPrice'],
                'max_price'         =>  $info['filters'][0]['maxPrice'],
                'tick_size'         =>  $info['filters'][0]['tickSize'],
                'min_order_value'   =>  $info['filters'][2]['minNotional'],
            ];
        }

        $exchangeRules = ExchangesRule::where('exchange_id', 1)->get();
        foreach ($exchangeRules as $key => $rule) {
            if(!isset($symbols[$rule->market.$rule->base])) {
                $rule->delete();
                continue;
            }

            $info = $symbols[$rule->market.$rule->base];
            
            if ($info['min_amount'] != $rule->min_amount) {
                $rule->min_amount = $info['min_amount'];
            }

            if ($info['max_amount'] != $rule->max_amount) {
                $rule->max_amount = $info['max_amount'];
            }

            if ($info['step_size'] != $rule->step_size) {
                $rule->step_size = $info['step_size'];
            }

            if ($info['max_price'] != $rule->max_price) {
                $rule->max_price = $info['max_price'];
            }

            if ($info['tick_size'] != $rule->tick_size) {
                $rule->tick_size = $info['tick_size'];
            }

            if ($info['min_order_value'] != $rule->min_order_value) {
                $rule->min_order_value = $info['min_order_value'];
            }

            $rule->save();
            unset($symbols[$rule->market.$rule->base]);
        }

        foreach ($symbols as $rule) {
            ExchangesRule::firstOrCreate($rule);
        }
    }
}
