<?php
namespace App\Interactions\Binance;

use Binance\API as BinanceAPI;

class Client {

    private $api;

    public function initAPI($userExchange) {
        $this->api = new BinanceAPI($userExchange['key'], $userExchange['secret']);

        // check that api really initialised???

        return true;
    }

    public function exchangeInfo() {
        $info = $this->api->exchangeInfo();
        return $this->handleResult($info);
    }

    public function buyTest($pair, $quantity, $price)
    {
        $order = $this->api->buyTest($pair, $quantity, $price);
        return $this->handleResult($order);
    }

    public function buy($pair, $quantity, $price)
    {
        $order = $this->api->buy($pair, $quantity, $price);
        return $this->handleResult($order);
    }

    public function stopLossBuy($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->buy($pair, $quantity, $price, 'STOP_LOSS_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function takeProfitBuy($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->buy($pair, $quantity, $price, 'TAKE_PROFIT_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function sellTest($pair, $quantity, $price)
    {
        $order = $this->api->sellTest($pair, $quantity, $price);
        return $this->handleResult($order);
    }

    public function sell($pair, $quantity, $price)
    {
        $order = $this->api->sell($pair, $quantity, $price);
        return $this->handleResult($order);
    }

    public function stopLossTestSell($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->sellTest($pair, $quantity, $price, 'STOP_LOSS_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function stopLossSell($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->sell($pair, $quantity, $price, 'STOP_LOSS_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function takeProfitTestSell($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->sellTest($pair, $quantity, $price, 'TAKE_PROFIT_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function takeProfitSell($pair, $quantity, $price, $stopPrice)
    {
        $order = $this->api->sell($pair, $quantity, $price, 'TAKE_PROFIT_LIMIT', ['stopPrice' => $stopPrice]);
        return $this->handleResult($order);
    }

    public function getPrices()
    {
        $prices = $this->api->prices();
        return $this->handleResult($prices);
    }

    public function getBalances()
    {
        $balances = $this->api->balances();
        return $this->handleResult($balances);
    }

    public function bookPrices()
    {
        $bookPrices = $this->api->bookPrices();
        return $this->handleResult($bookPrices);
    }

    public function getOpenOrders($pair)
    {
        $orders = $this->api->openOrders($pair);
        return $this->handleResult($orders);
    }

    public function getOrderStatus($pair, $orderId)
    {
        $result = $this->api->orderStatus($pair, $orderId);
        return $this->handleResult($result);
    }

    public function cancelOrder($pair, $orderId)
    {
        $result = $this->api->cancel($pair, $orderId);
        return $this->handleResult($result);
    }

    private function handleResult($response = [])
    {
        $sdkEcho = $this->parseEcho(ob_get_clean());

        $success = false;
        $errors = [];
        $result = [];

        $apiInfo = array_merge($response, $sdkEcho);
        if (array_key_exists('code', $apiInfo)) {
            $success = false;
            $errors = $this->handleError($apiInfo);
        } else {
            $success = true;
            $result = $apiInfo;
        }
        return compact('success', 'errors', 'result');
    }

    private function parseEcho($string)
    {
        $result = [];
        $first = strpos($string, '{');
        $last = strrpos($string, '}');
        if ($first && $last) {
            $json = substr($string, $first, $last - $first+1);
            $result = (array)json_decode($json);
        }
        return $result;
    }

    private function handleError($response)
    {
        $error = [
            'code' => $response['code'],
            'code_text' => '',
            'code_message' => '',
            'msg' => $response['msg'],
            'specific_error' => $this->handleSpecificError($response['msg']),
        ];
        $code = $response['code'];
        switch ($code) {
            case '-1000':
                $error['code_text'] = 'UNKNOWN';
                $error['code_message'] = 'An unknown error occured while processing the request.';
                break;
            case '-1001':
                $error['code_text'] = 'DISCONNECTED';
                $error['code_message'] = 'Internal error; unable to process your request. Please try again.';
                break;
            case '-1002':
                $error['code_text'] = 'UNAUTHORIZED';
                $error['code_message'] = 'You are not authorized to execute this request.';
                break;
            case '-1003':
                $error['code_text'] = 'TOO_MANY_REQUESTS';
                $error['code_message'] = 'Too many requests. Too many requests queued. Too many requests; current limit is %s requests per minute. Please use the websocket for live updates to avoid polling the API. Way too many requests; IP banned until %s. Please use the websocket for live updates to avoid bans.';
                break;
            case '-1006':
                $error['code_text'] = 'UNEXPECTED_RESP';
                $error['code_message'] = 'An unexpected response was received from the message bus. Execution status unknown.';
                break;
            case '-1007':
                $error['code_text'] = 'TIMEOUT';
                $error['code_message'] = 'Timeout waiting for response from backend server. Send status unknown; execution status unknown.';
                break;
            case '-1013':
                $error['code_text'] = 'INVALID_MESSAGE';
                $error['code_message'] = 'INVALID_MESSAGE';
                break;
            case '-1014':
                $error['code_text'] = 'UNKNOWN_ORDER_COMPOSITION';
                $error['code_message'] = 'Unsupported order combination.';
                break;
            case '-1015':
                $error['code_text'] = 'TOO_MANY_ORDERS';
                $error['code_message'] = 'Too many new orders. Too many new orders; current limit is %s orders per %s.';
                break;
            case '-1016':
                $error['code_text'] = 'SERVICE_SHUTTING_DOWN';
                $error['code_message'] = 'This service is no longer available.';
                break;
            case '-1020':
                $error['code_text'] = 'UNSUPPORTED_OPERATION';
                $error['code_message'] = 'This operation is not supported.';
                break;
            case '-1021':
                $error['code_text'] = 'INVALID_TIMESTAMP';
                $error['code_message'] = 'Timestamp for this request is outside of the recvWindow. Timestamp for this request was 1000ms ahead of the server\'s time.';
                break;
            case '-1022':
                $error['code_text'] = 'INVALID_SIGNATURE';
                $error['code_message'] = 'Signature for this request is not valid.';
                break;
            case '-1100':
                $error['code_text'] = 'ILLEGAL_CHARS';
                $error['code_message'] = 'Illegal characters found in a parameter. Illegal characters found in parameter \'%s\'; legal range is \'%s\'.';
                break;
            case '-1101':
                $error['code_text'] = 'TOO_MANY_PARAMETERS';
                $error['code_message'] = 'Too many parameters sent for this endpoint. Too many parameters; expected \'%s\' and received \'%s\'. Duplicate values for a parameter detected.';
                break;
            case '-1102':
                $error['code_text'] = 'MANDATORY_PARAM_EMPTY_OR_MALFORMED';
                $error['code_message'] = 'A mandatory parameter was not sent, was empty/null, or malformed. Mandatory parameter \'%s\' was not sent, was empty/null, or malformed. Param \'%s\' or \'%s\' must be sent, but both were empty/null!';
                break;
            case '-1103':
                $error['code_text'] = 'UNKNOWN_PARAM';
                $error['code_message'] = 'An unknown parameter was sent.';
                break;
            case '-1104':
                $error['code_text'] = 'UNREAD_PARAMETERS';
                $error['code_message'] = 'Not all sent parameters were read. Not all sent parameters were read; read \'%s\' parameter(s) but was sent \'%s\'.';
                break;
            case '-1105':
                $error['code_text'] = 'PARAM_EMPTY';
                $error['code_message'] = 'A parameter was empty. Parameter \'%s\' was was empty.';
                break;
            case '-1106':
                $error['code_text'] = 'PARAM_NOT_REQUIRED';
                $error['code_message'] = 'A parameter was sent when not required. Parameter \'%s\' sent when not required.';
                break;
            case '-1112':
                $error['code_text'] = 'NO_DEPTH';
                $error['code_message'] = 'No orders on book for symbol.';
                break;
            case '-1114':
                $error['code_text'] = 'TIF_NOT_REQUIRED';
                $error['code_message'] = 'TimeInForce parameter sent when not required.';
                break;
            case '-1115':
                $error['code_text'] = 'INVALID_TIF';
                $error['code_message'] = 'Invalid timeInForce.';
                break;
            case '-1116':
                $error['code_text'] = 'INVALID_ORDER_TYPE';
                $error['code_message'] = 'Invalid orderType.';
                break;
            case '-1117':
                $error['code_text'] = 'INVALID_SIDE';
                $error['code_message'] = 'Invalid side.';
                break;
            case '-1118':
                $error['code_text'] = 'EMPTY_NEW_CL_ORD_ID';
                $error['code_message'] = 'New client order ID was empty.';
                break;
            case '-1119':
                $error['code_text'] = 'EMPTY_ORG_CL_ORD_ID';
                $error['code_message'] = 'Original client order ID was empty.';
                break;
            case '-1120':
                $error['code_text'] = 'BAD_INTERVAL';
                $error['code_message'] = 'Invalid interval.';
                break;
            case '-1121':
                $error['code_text'] = 'BAD_SYMBOL';
                $error['code_message'] = 'Invalid symbol.';
                break;
            case '-1125':
                $error['code_text'] = 'INVALID_LISTEN_KEY';
                $error['code_message'] = 'This listenKey does not exist.';
                break;
            case '-1127':
                $error['code_text'] = 'MORE_THAN_XX_HOURS';
                $error['code_message'] = 'Lookup interval is too big. More than %s hours between startTime and endTime.';
                break;
            case '-1128':
                $error['code_text'] = 'OPTIONAL_PARAMS_BAD_COMBO';
                $error['code_message'] = 'Combination of optional parameters invalid.';
                break;
            case '-1130':
                $error['code_text'] = 'INVALID_PARAMETER';
                $error['code_message'] = 'Invalid data sent for a parameter. Data sent for paramter \'%s\' is not valid.';
                break;
            case '-2008':
                $error['code_text'] = 'BAD_API_ID';
                $error['code_message'] = 'Invalid Api-Key ID';
                break;
            case '-2009':
                $error['code_text'] = 'DUPLICATE_API_KEY_DESC';
                $error['code_message'] = 'API-key desc already exists.';
                break;
            case '-2012':
                $error['code_text'] = 'CANCEL_ALL_FAIL';
                $error['code_message'] = 'Batch cancel failure.';
                break;
            case '-2013':
                $error['code_text'] = 'NO_SUCH_ORDER';
                $error['code_message'] = 'Order does not exist.';
                break;
            case '-2014':
                $error['code_text'] = 'BAD_API_KEY_FMT';
                $error['code_message'] = 'API-key format invalid.';
                break;
            case '-2015':
                $error['code_text'] = 'REJECTED_MBX_KEY';
                $error['code_message'] = 'Invalid API-key, IP, or permissions for action.';
                break;
        }
        return $error;
    }

    private function handleSpecificError($msg)
    {
        switch ($msg) {
            case 'Unknown order sent.':
                return 'The order (by either orderId, clOrdId, origClOrdId) could not be found';
                break;
            case 'Duplicate order sent.':
                return 'The clOrdId is already in use';
                break;
            case 'Market is closed.':
                return 'The symbol is not trading';
                break;
            case 'Account has insufficient balance for requested action.':
                return 'Not enough funds to complete the action';
                break;
            case 'Market orders are not supported for this symbol.':
                return 'MARKET is not enabled on the symbol';
                break;
            case 'Iceberg orders are not supported for this symbol.':
                return 'icebergQty is not enabled on the symbol';
                break;
            case 'Stop loss orders are not supported for this symbol.':
                return 'STOP_LOSS is not enabled on the symbol';
                break;
            case 'Stop loss limit orders are not supported for this symbol.':
                return 'STOP_LOSS_LIMIT is not enabled on the symbol';
                break;
            case 'Take profit orders are not supported for this symbol.':
                return 'TAKE_PROFIT is not enabled on the symbol';
                break;
            case 'Take profit limit orders are not supported for this symbol.':
                return 'TAKE_PROFIT_LIMIT is not enabled on the symbol';
                break;
            case 'Price * QTY is zero or less.':
                return 'price * quantity is too low';
                break;
            case 'IcebergQty exceeds QTY.':
                return 'icebergQty must be less than the order quantity';
                break;
            case 'This action disabled is on this account.':
                return 'Contact customer support; some actions have been disabled on the account.';
                break;
            case 'Unsupported order combination':
                return 'The orderType, timeInForce, stopPrice, and/or icebergQty combination isn\'t allowed.';
                break;
            case 'Order would trigger immediately.':
                return 'The order\'s stop price is not valid when compared to the last traded price.';
                break;
            case 'Cancel order is invalid. Check origClOrdId and orderId.':
                return 'No origClOrdId or orderId was sent in.';
                break;
            case 'Order would immediately match and take.':
                return 'LIMIT_MAKER order type would immediately match and trade, and not be a pure maker order.';
                break;
            case 'Filter failure: PRICE_FILTER':
                return 'price is too high, too low, and/or not following the tick size rule for the symbol.';
                break;
            case 'Filter failure: LOT_SIZE':
                return 'quantity is too high, too low, and/or not following the step size rule for the symbol.';
                break;
            case 'Filter failure: MIN_NOTIONAL':
                return 'price * quantity is too low to be a valid order for the symbol.';
                break;
            case 'Filter failure: MAX_NUM_ORDERS':
                return 'Account has too many open orders on the symbol.';
                break;
            case 'Filter failure: MAX_ALGO_ORDERS':
                return 'Account has too many open stop loss and/or take profit orders on the symbol.';
                break;
            case 'Filter failure: EXCHANGE_MAX_NUM_ORDERS':
                return 'Account has too many open orders on the exchange.';
                break;
            case 'Filter failure: EXCHANGE_MAX_ALGO_ORDERS':
                return 'Account has too many open stop loss and/or take profit orders on the exchange.';
                break;
            default:
                return '';
                break;
        }
    }




}