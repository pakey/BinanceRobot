<?php

namespace App\Component\Exchange;

use Kuxin\Config;
use Kuxin\Loader;

/**
 * Class Exchange
 * @package App\Component\Exchange
 * @author  Pakey <pakey@qq.com>
 * @property \App\Component\Exchange\Provider\Binance $binance
 * @property \App\Component\Exchange\Provider\Huobi   $huobi
 */
class Exchange
{

    protected $apikey;

    protected $secret;

    /**
     * @var \App\Component\Exchange\Provider\Huobi
     */
    protected $api;

    public function __construct($apikey = '', $secret = '')
    {
        $this->apikey = $apikey;
        $this->secret = $secret;

        Config::set('http.proxy.power', PROXY_POWER);
        Config::set('http.proxy.host', PROXY_HOST);
        Config::set('http.proxy.port', PROXY_PORT);
        Config::set('http.proxy.type', PROXY_TYPE);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        $class = "\\App\\Component\\Exchange\\Provider\\" . ucfirst($name);
        if (class_exists($class)) {
            return Loader::instance($class, [$this->apikey, $this->secret]);
        } else {
            trigger_error('未定义的交易Provider', E_USER_ERROR);
        }
    }

    public function init()
    {

    }

    public function setExchange($name)
    {
        $this->api = $this->$name;
    }

    public function buyWithMoney($coin, $money)
    {
        $api  = $this->api;
        $info = $api->getSymbolInfo($coin);
        do {
            $depths = $api->getTradeDepth($coin);
            $prices = $depths['bids'][0];
            if ($prices[0] > 0) {
                break;
            }
        } while (true);
        $originalPrice = $prices[0];
        $price         = $prices[0] + (1 / pow(10, $info['price']));
        $amount        = floor($money / $price * pow(10, $info['amount'])) / pow(10, $info['amount']);
        echo '买单', $prices[0], '---', $price, '---', $amount, PHP_EOL;
        $orderId = $api->orderSubmit($coin, $amount, $price);
        if ($orderId) {
            do {
                $res = $api->orderInfo($orderId);
                if (!empty($res['data'])) {
                    switch ($res['data']['state']) {
                        case 'filled':
                            return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                        case 'submitting';
                        case 'submitted':
                            $depths = $api->getTradeDepth($coin);
                            $buy    = $depths['bids'][0];
                            if ($buy['0'] > $price) {
                                $res = $api->orderCancel($orderId);
                                if ($res['status'] == 'ok') {
                                    $balance = $api->getBalance($coin);
                                    if ($balance['trade'] < $amount / 5) {
                                        $nowprice = $buy['0'] + 1 / pow(10, $info['price']);
                                        if ($nowprice / $originalPrice - 1 > 0.01) {
                                            echo '买单单价上浮超过1% 放弃挂单', PHP_EOL;
                                            return false;
                                        }
                                        $amount     = floor($money / $nowprice * pow(10, $info['amount'])) / pow(10, $info['amount']);
                                        $newOrderId = $api->orderSubmit($coin, $amount, $nowprice);
                                        if ($newOrderId) {
                                            $orderId = $newOrderId;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'partial-filled':
                            if (microtime(true) - 20 > $res['data']['created-at'] / 1000) {
                                $res = $api->orderCancel($orderId);
                                if ($res['status'] == 'ok') {
                                    return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                                }
                            }
                            break;
                        case 'canceled':
                        case 'partial-canceled':
                            if ($res['data']['field-amount'] > 0) {
                                return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                            } else {
                                echo '取消后未挂买单', PHP_EOL;
                                return false;
                            }
                    }
                }
                sleep(0.5);
            } while (true);
        } else {
            echo '下单失败', PHP_EOL;
            return false;
        }
    }

    public function sell($coin, $amount)
    {
        $api  = $this->api;
        $info = $api->getSymbolInfo($coin);
        do {
            $depths = $api->getTradeDepth($coin);
            $prices = $depths['asks'][0];
            if ($prices[0] > 0) {
                break;
            }
        } while (true);
        $price   = $prices['0'] - 1 / pow(10, $info['price']);
        $balance = $api->getBalance($coin);
        $amount  = round(($balance['trade'] > $amount ? $balance['trade'] : $amount), $info['amount']);
        echo '卖单 ', $prices[0], '---', $price, '---', $amount, PHP_EOL;
        $orderId = $api->orderSubmit($coin, $amount, $price, $api::SELL);
        if ($orderId) {
            do {
                $res = $api->orderInfo($orderId);
                if (!empty($res['data'])) {
                    switch ($res['data']['state']) {
                        case 'filled':
                            return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                        case 'submitting';
                        case 'submitted':
                            $depths = $api->getTradeDepth($coin);
                            $prices = $depths['asks'][0];
                            if ($prices['0'] < $price) {
                                $res = $api->orderCancel($orderId);
                                if ($res['status'] == 'ok') {
                                    echo('有价更低的 取消卖单等待到账 ' . $prices['0']), PHP_EOL;
                                    do {
                                        $balance = $api->getBalance($coin);
                                        if ($balance['trade'] > 0 || $balance['frozen'] == 0) {
                                            break;
                                        }
                                        sleep(0.2);
                                    } while (true);
                                    if ($balance['trade'] >= $amount) {

                                    } elseif ($balance['trade'] > 0) {
                                        $amount = $balance['trade'];
                                    } elseif ($balance['trade'] + $balance['frozen'] < $amount) {
                                        echo('取消订单但是余额不足 怀疑是取消提交的时候已经部分成交了'), PHP_EOL;
                                        break;
                                    }
                                    $depths     = $api->getTradeDepth($coin);
                                    $prices     = $depths['asks'][0];
                                    $nowprice   = $prices['0'] - 1 / pow(10, $info['price']);
                                    $newOrderId = $api->orderSubmit($coin, $amount, $nowprice, $api::SELL);
                                    if ($newOrderId) {
                                        $orderId = $newOrderId;
                                    }
                                    break;
                                }
                            }
                            break;
                        case 'partial-filled':
                            if (microtime(true) - 20 > $res['data']['created-at'] / 1000) {
                                $res = $api->orderCancel($orderId);
                                if ($res['status'] == 'ok') {
                                    return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                                }
                            }
                            break;
                        case 'canceled':
                        case 'partial-canceled':
                            if ($res['data']['field-amount'] > 0) {
                                return ['orderid' => $orderId, 'price' => $res['data']['price'], 'amount' => $res['data']['field-amount'], 'money' => $res['data']['field-cash-amount']];
                            } else {
                                echo '取消后未挂卖单', PHP_EOL;
                                return false;
                            }
                    }
                }
                usleep(1000000);
            } while (true);
        } else {
            echo '下卖单失败', PHP_EOL;
            return false;
        }
    }
}