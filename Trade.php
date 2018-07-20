<?php

namespace App;

use Binance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Monolog\Logger;
use App\Monologger\Handlers\PDOHandler;
use App\Events\TradeTargetOrderFillingStarted;
use App\Events\TradeTargetOrderFillingFinished;
use App\Events\TradeOriginStopLossOrderFillingStarted;
use App\Events\TradeOriginStopLossOrderFillingFinished;
use App\Events\TradeFinished;

class Trade extends Model
{
    use SoftDeletes;

    protected $log;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->log = new Logger('trade-model');
        $this->log->pushHandler(new PDOHandler(new \PDO(
            env('DB_CONNECTION').':host='.env('DB_HOST').';dbname='.env('DB_DATABASE'),
            env('DB_USERNAME'), env('DB_PASSWORD')
        )));
    }

    protected $fillable = [
        'id',
    ];

    protected $dates = ['deleted_at', 'finished_at'];

    /*Start relations*/
    public function targets()
    {
        return $this->hasMany(TradesTarget::class);
    }

    public function tradeApi()
    {
        return $this->hasMany(TradesApi::class);
    }

    public function call()
    {
        return $this->hasOne(TradesCall::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rule()
    {
        return $this->belongsTo(ExchangesRule::class);
    }

    public function buyOrder()
    {
        return $this->hasOne(TradesApi::class)->whereSide('BUY');
    }

    public function originStopLossOrder()
    {
        return $this->hasOne(TradesApi::class)->whereSide('SELL')->whereType('STOP_LOSS_LIMIT')->orderBy('id', 'desc')->trailing(false);
    }

    public function trailingStopLossOrder()
    {
        return $this->hasOne(TradesApi::class)->whereSide('SELL')->whereType('STOP_LOSS_LIMIT')->trailing(true);
    }

    public function takeProfitOrders()
    {
        return $this->hasMany(TradesApi::class)->whereSide('SELL')->whereType('TAKE_PROFIT_LIMIT');
    }
    /*End relations*/

    /*Start scopes*/

    /*End scopes*/

    /*Start mutators*/

    /*End mutators*/

    /*Start helper function*/
    public function currentTarget($bestBid)
    {
        return $this->targets->last(function ($target) use ($bestBid) {
            return $target->bid < $bestBid;
        }) ?? false;
    }

    public function nextTarget($bestBid)
    {
        return $this->targets->first(function ($target) use ($bestBid) {
            return $bestBid < $target->bid;
        }) ?? false;
    }

    public function reachedTargets($bestBid)
    {
        return $this->targets->where('bid', '<', $bestBid)->where('reached', false)->all() ?? false;
    }

    public function reachedTargetsPercentageSum($bestBid)
    {
        return $this->targets->where('reached', false)->reduce(function ($carry, $target) use ($bestBid) {
            return $bestBid >= $target->bid ? $carry + $target->amount : $carry;
        });
    }

    public function remainingTargetsPercentageSum()
    {
        return $this->targets->where('reached', false)->sum('amount');
    }

    public function _initApiBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $exchangeParams = $this->user->getExchangeParams('binance');

        if (empty($exchangeParams) || empty($exchangeParams['key']) || empty($exchangeParams['secret'])) {
            $this->log->error('ExchangeParams failed', [
                'trade' => $this->id,
                'trace' => __CLASS__.'@'.__FUNCTION__,
                'exchangeParams' => $exchangeParams
            ]);

            return false;
        }

        return Binance::initAPI($exchangeParams);
    }

    public function getOrderStatusBinance($symbol, $orderId)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        return Binance::getOrderStatus($symbol, $orderId);
    }

    public function cancelBuyOrderBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $buyOrder = $this->buyOrder;

        if (!empty($buyOrder)) {
            return $this->cancelOrderBinance($buyOrder);
        }

        $this->log->info('Empty BuyOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        return false;
    }

    /**
     * As a system I want to cancel the Stop Loss order on Binance
     * so that I can prevent double selling
     *
     * When trailing is active..
     * .. then collect correct Stop Loss order
     *
     * When Stop Loss order exists..
     * .. then cancel the order on Binance
     */
    public function cancelStopLossOrderBinance($trailing)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $stopLossOrder = $trailing ? $this->trailingStopLossOrder : $this->originStopLossOrder;

        if (!empty($stopLossOrder)) {
            return $this->cancelOrderBinance($stopLossOrder);
        }

        $this->log->info('Empty StopLossOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        return true;
    }

    /**
     * As a system I want to cancel Take Profits order on Binance
     * so that I can prevent double selling
     *
     * When Take Profit order exists..
     * .. then cancel the Take Profit order on Binance
     */
    public function cancelTakeProfitOrdersBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $takeProfitOrders = $this->takeProfitOrders;

        $takeProfitOrders->each(function($takeProfitOrder) {
            if (!empty($takeProfitOrder)) {
                return $this->cancelOrderBinance($takeProfitOrder);
            } else {
                $this->log->info('Empty TakeProfitOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);
            }
        }); 

        return true;
    }

    /**
     * As a system I want to place a Stop Loss order on Binance
     * so that I can sell for the right amounts at the right moment
     *
     * When Stop Loss order is placed..
     * .. compare the quantity decimals to the rules and correct (rounded up)
     * .. compare the price decimials to the rules and correct (rounded up)
     * .. then place Stop Loss order on Binance
     *
     * When Stop Loss order is placed on Binance..
     * .. then check if successful
     *
     * When Stop Loss order is successful
     * .. then check if Stop Loss order exists
     *
     * When Stop Loss order exists
     * .. then update existing Stop Loss order to the new one
     *
     * When Stop Loss order doesn't exist
     * .. then create a new Stop Loss order
     * .. then connect order to the current trade
     *
     * When Stop Loss order failed
     * .. then check if error is quantity
     *
     * When error is quantity
     * .. then check available funds
     * .. then check if available funds is under quantity
     *
     * When available funds is under quantity
     * .. then set quantity to available funds
     * .. then place Stop Loss order on Binance
     *
     * When available funds is over quantity
     * .. then round quantity down
     * .. then place Stop Loss order on Binance
     *
     * When Stop Loss order keeps failing
     * .. then stop process
     */
    public function placeStopLossOrderBinance($quantity, $price, $stopPrice, $trailing = false)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        // Set quantity right
        $quantityDecimals = strpos(number_format($this->rule->min_amount, 9), '1') - 1;
        $quantity = number_format($quantity, $quantityDecimals, ".", "");

        // Set price right
        $priceDecimals = strpos(number_format($this->rule->min_price, 9), '1') - 1;
        $price = number_format($price, $priceDecimals, ".", "");
        $stopPrice = number_format($stopPrice, $priceDecimals, ".", "");

        $placeStopLossOrder = Binance::stopLossSell($this->buyOrder->symbol, $quantity, $price, $stopPrice);

        if ($placeStopLossOrder['success'] && !empty($placeStopLossOrder['result'])) {
            $placeStopLossOrder['result']['extra']['trailing'] = $trailing;

            $stopLossOrder = $trailing ? $this->trailingStopLossOrder : $this->originStopLossOrder;
            
            if (!empty($stopLossOrder)) {
                $this->log->info('Updated StopLossOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                $stopLossOrder->update($this->toSnakeCase($placeStopLossOrder['result']));
                return $stopLossOrder;
            } else {
                $this->log->info('Created StopLossOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                return $this->tradeApi()->create($this->toSnakeCase($placeStopLossOrder['result']));
            }

            return true;
        } elseif (!empty($placeStopLossOrder['errors']) && $placeStopLossOrder['errors']['code'] = -1013) {
            $this->log->error('Insufficient Balance', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

            $balances = Binance::getBalances();
            $availableFunds = $balances['result'][$this->rule->market]['available'];

            if ($availableFunds < $quantity) {
                $quantity = $this->numberFormatPrecision($availableFunds, $quantityDecimals, '.');
            } else {
                $quantity = $this->numberFormatPrecision($quantity, $quantityDecimals, '.');
            }

            $placeStopLossOrder = Binance::stopLossSell($this->buyOrder->symbol, $quantity, $price, $stopPrice);

            if ($placeStopLossOrder['success'] && !empty($placeStopLossOrder['result'])) {
                $placeStopLossOrder['result']['extra']['trailing'] = $trailing;

                $stopLossOrder = $trailing ? $this->trailingStopLossOrder : $this->originStopLossOrder;
                
                if (!empty($stopLossOrder)) {
                    $this->log->info('Updated StopLossOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);
                    $stopLossOrder->update($this->toSnakeCase($placeStopLossOrder['result']));
                    return $stopLossOrder;
                } else {
                    $this->log->info('Created StopLossOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);
                    return $this->tradeApi()->create($this->toSnakeCase($placeStopLossOrder['result']));
                }

                return true;
            }
        }

        return false;
    }

    /**
     * As a system I want to (re)place a Stop Loss order on Binance
     * so that Binance can sell for the system
     *
     * When old Stop Loss order is succesfully cancelled
     * .. then place a new Stop Loss order
     */
    public function replaceStopLossOrderBinance($quantity, $price, $stopPrice, $trailing = false)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        if ($this->cancelStopLossOrderBinance($trailing)) {
            return $this->placeStopLossOrderBinance($quantity, $price, $stopPrice, $trailing);
        }

        $this->log->info('Cancel Failed', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        return false;
    }

    public function placeTakeProfitOrderBinance($quantity, $price, $stopPrice)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        // Set quantity right
        $quantityDecimals = strpos(number_format($this->rule->min_amount, 9), '1') - 1;
        $quantity = number_format($quantity, $quantityDecimals, ".", "");

        // Set price right
        $priceDecimals = strpos(number_format($this->rule->min_price, 9), '1') - 1;
        $price = number_format($price, $priceDecimals, ".", "");
        $stopPrice = number_format($stopPrice, $priceDecimals, ".", "");
        
        $placeTakeProfitOrder = Binance::takeProfitSell($this->buyOrder->symbol, $quantity, $price, $stopPrice);

        if ($placeTakeProfitOrder['success'] && !empty($placeTakeProfitOrder['result'])) {
            $this->log->info('Created TakeProfitOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

            return $this->tradeApi()->create($this->toSnakeCase($placeTakeProfitOrder['result']));
        } elseif (!empty($placeTakeProfitOrder['errors']) && $placeTakeProfitOrder['errors']['code'] = -1013) {
            $this->log->error('Insufficient Balance', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

            $balances = Binance::getBalances();
            $availableFunds = $balances['result'][$this->rule->market]['available'];

            if ($availableFunds < $quantity) {
                $quantity = $this->numberFormatPrecision($availableFunds, $quantityDecimals, '.');
            } else {
                $quantity = $this->numberFormatPrecision($quantity, $quantityDecimals, '.');
            }

            $placeTakeProfitOrder = Binance::takeProfitSell($this->buyOrder->symbol, $quantity, $price, $stopPrice);

            if ($placeTakeProfitOrder['success'] && !empty($placeTakeProfitOrder['result'])) {
                $this->log->info('Created TakeProfitOrder', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                return $this->tradeApi()->create($this->toSnakeCase($placeTakeProfitOrder['result']));
            }
        }

        return false;
    }

    /**
     * As a system I want to cancel order on Binance
     * so that I don't place double sells
     *
     * When current order exists
     * .. then cancel the order
     * .. then update database
     * .. then return notice to continue
     *
     * When current order does not exist
     * .. then return notice to continue
     */
    public function cancelOrderBinance($order)
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $cancelOrder = Binance::cancelOrder($order->symbol, $order->order_id);

        if ($cancelOrder['success']) {
            $this->log->info('Cancelled Order', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

            $order->update($this->toSnakeCase($cancelOrder['result']));
            return true;
        } elseif ($cancelOrder['errors']['code'] === -2011) {
            $this->log->info('Order Does Not Exist', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

            return true;
        }

        return $cancelOrder;
    }

    /**
     * As a system I want to track targets that were not reached
     * so that I can update their status if reached
     *
     * When target is not reached
     * .. then check if there is an order for this target
     * .. then check order status on Binance
     * .. then check if order is successful
     * .. then check if order is FILLED
     *
     * When order is FILLED
     * .. then update the price sold for in the database
     * .. then set the target to reached in the database
     * .. then update the order with executed quantity
     * .. then update the order with the status
     *
     * When all targets are reached
     * .. then finish the trade
     */
    public function trackUnreachedTargetsBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $unreachedTargets = $this->targets->where('reached', false);

        $unreachedTargets->each(function($target, $key) use ($unreachedTargets) { 
            $targetOrder = $target->tradeApi->last();

            if (!$targetOrder->isEmpty()) {
                $orderStatus = $this->getOrderStatusBinance($targetOrder->symbol, $targetOrder->order_id);

                if ($targetOrder->status == 'NEW' && $orderStatus['result']['status'] == 'PARTIALLY_FILLED') {
                    $this->log->info('Selling Started', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                    event(new TradeTargetOrderFillingStarted($this->id, $key, $orderStatus['result']['executed_qty']));
                } elseif ($targetOrder->status == 'PARTIALLY_FILLED' && $orderStatus['result']['status'] == 'FILLED') {
                    $this->log->info('Selling Finished', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                    event(new TradeTargetOrderFillingFinished($this->id, $key));
                }

                if ($orderStatus['success'] && !empty($orderStatus['result']) && $orderStatus['result']['status'] === 'FILLED') {
                    $this->log->info('Target Updated', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                    $target->update([
                        'sell_price' => $orderStatus['result']['price'],
                        'reached' => true
                    ]);

                    $targetOrder->update([
                        'executed_qty' => $orderStatus['result']['executedQty'],
                        'status' => $orderStatus['result']['status'],
                    ]);

                    if (count($this->targets)-1 == $key) {
                        $this->log->info('Trade Finished', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                        $this->deactivate();

                        event(new TradeFinished($this->id));
                    }
                }
            }
        });
    }

    /**
     * As a system I want to know if the Stop Loss is FILLED
     * so that I can update the database
     *
     * When Stop Loss order exists
     * .. then track if it has FILLED for an hour
     * .. or track if the original quantity is the same as executed quantity
     * .. then finish the trade
     *
     * When Stop Loss order exists and has not tracked for an hour and 
     * original quantity is not excecuted quantity
     * .. then track order status on Binance
     * .. then if order exits
     * .. then update the database
     */
    public function trackOriginStopLossBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        $stopLossOrder = $this->originStopLossOrder;

        $prevTrackTime = isset($stopLossOrder->extra['last_track']) ? $stopLossOrder->extra['last_track'] : 0;
        $tracksQty = isset($stopLossOrder->extra['tracks_qty']) ? $stopLossOrder->extra['tracks_qty'] : 0;

        if ($tracksQty > 12 || (!empty($stopLossOrder) && $stopLossOrder->orig_qty === $stopLossOrder->executed_qty)) {
            $this->deactivate();
            return;
        } elseif (time() - $prevTrackTime >= 300 && !empty($stopLossOrder) && !empty($stopLossOrder->executed_qty)) {
            $orderStatus = $this->getOrderStatusBinance($stopLossOrder->symbol, $stopLossOrder->order_id);

            if ($orderStatus['success'] && !empty($orderStatus['result'])) {
                if ($stopLossOrder->status == 'NEW' && $orderStatus['result']['status'] == 'PARTIALLY_FILLED') {
                    $this->log->info('Selling Started', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                    event(new TradeOriginStopLossOrderFillingStarted($this->id, $orderStatus['result']['executedQty']));
                } elseif ($stopLossOrder->status == 'PARTIALLY_FILLED' && $orderStatus['result']['status'] == 'FILLED') {
                    $this->log->info('Selling Finished', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

                    event(new TradeOriginStopLossOrderFillingFinished($this->id));
                }

                $orderStatus['result']['extra']['trailing'] = $stopLossOrder->extra['trailing'];
                $orderStatus['result']['extra']['last_track'] = time();
                $orderStatus['result']['extra']['tracks_qty'] = $tracksQty + 1;

                $this->log->info('Updated Order', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);
                
                $stopLossOrder->update($this->toSnakeCase($orderStatus['result']));
            }
        }
    }

    public function deactivate()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);
        
        $this->active = false;
        $this->finished_at = \Carbon\Carbon::now()->format('Y-m-d H:i:s');

        $this->save();
    }

    public function toSnakeCase($array) {
        foreach ($array as $key => $item) {
            $result[snake_case($key)] = $item;
        }

        return $result;
    }

    public function numberFormatPrecision($number, $precision = 2, $separator = '.') {
        $numberParts = explode($separator, $number);
        $response = $numberParts[0];
        if(count($numberParts)>1 && $precision > 0){
            $response .= $separator;
            $response .= substr($numberParts[1], 0, $precision);
        }
        return $response;
    }

    public function cancelActiveOrdersBinance()
    {
        $this->log->info('Method called', ['trade' => $this->id, 'trace' => __CLASS__.'@'.__FUNCTION__]);

        // error messaging? Yes, how? What?

        if (!empty($this->buyOrder)) {
            if ($this->cancelBuyOrderBinance()) {
                $this->buyOrder->delete();
            }
        }

        if (!empty($this->originStopLossOrder)) {
            if ($this->cancelStopLossOrderBinance(false)) {
                $this->originStopLossOrder->delete();
            }
        }

        if (!empty($this->trailingStopLossOrder)) {
            if ($this->cancelStopLossOrderBinance(true)) {
                $this->trailingStopLossOrder->tradeTarget()->detach();
                $this->trailingStopLossOrder->delete();
            }
        }
        
        if ($this->takeProfitOrders->isNotEmpty()) {
            if ($this->cancelTakeProfitOrdersBinance()) {
                $this->takeProfitOrders->each(function($order) {
                    $order->tradeTarget()->detach();
                    $order->delete();
                });
            }
        }

        return true;
    }

    public function getBuyingStartedAttribute()
    {
        $buyOrder = $this->buyOrder;

        return !empty($buyOrder) && ($buyOrder->status == 'PARTIALLY_FILLED' || $buyOrder->status == 'FILLED');
    }

    public function getSellingStartedAttribute()
    {
        $sellOrder = $this->originStopLossOrder;

        return !empty($sellOrder) && ($sellOrder->status == 'PARTIALLY_FILLED' || $sellOrder->status == 'FILLED');
    }

    public function getGainLossAttribute()
    {
        if (!$this->buy || !$this->amount || !$this->finished_at) {
            return;
        }

        $remainingPerc = $this->remainingTargetsPercentageSum();
        $boughtFor = $this->amount * $this->buy;

        $targetSum = 0;
        $targetSum = $this->targets->sum(function ($target) {
            if ($target->reached) {
                return $target->sell_price * ($this->amount * ($target->amount / 100));
            }
        });

        $stopLossSum = 0;
        if (!empty($remainingPerc)) {
            $stopLossOrder = $this->originStopLossOrder;

            if (!empty($stopLossOrder)) {
                $stopLossSum = ($stopLossOrder->price) * ($this->amount * ($remainingPerc / 100));
            }
        }

        return ((($targetSum + $stopLossSum) / $boughtFor) - 1) * 100;
    }
    /*End helper function*/
}
