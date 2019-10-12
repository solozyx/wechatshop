<?php

namespace app\api\controller\v1;

use app\api\controller\BaseController;
use app\api\service\WxNotify;
use app\api\validate\IDMustBePostiveInt;
use app\api\service\Pay as PayService;
//use think\facade\Env;
use Env;

$extend = Env::get('root_path') . 'extend';
require_once $extend . DIRECTORY_SEPARATOR . 'WxPay' . DIRECTORY_SEPARATOR . 'WxPay.Api.php';

class Pay extends BaseController
{
    protected $beforeActionList = [
        'checkExclusiveScope' => ['only' => 'getPreOrder'],
    ];

    // 入参 `order`表.`id`字段
    public function getPreOrder($id = '')
    {
        // 校验 $id 为正整数
        (new IDMustBePostiveInt())->goCheck();
        // 微信预支付订单
        $pay = new PayService($id);
        // 把微信支付参数 返回给 小程序端
        return $pay->pay();
    }

    // 微信支付结果异步通知回调
    // 微信做异步通知不止1次,会每隔一段时间通知1次
    // 微信异步通知频率为 15 / 15 / 30 / 180 / 1800 / 1800 / 1800 / 1800 / 1800 / 3600 单位秒
    // 微信前1次异步通知调用 没有收到服务端正确的响应结果 才会发送后续的重复异步通知调用
    // 第1次微信异步通知 服务端就告诉微信已经正确接收到支付通知 微信就不做后续通知了
    // 否则微信会按照该频率重复异步通知 直到服务端返回微信正确的应答为止
    // 如果服务端一直没有做出正确应答 3600 秒后 微信也不再做异步通知

    // 微信文档明确指出,不能绝对保证每次异步通知都能发送成功到服务端,而是尽最大可能重试

    public function receiveNotify()
    {
        // 1.检查库存量,超卖(微信支付前检查库存了 这里超卖可能小 但也要检查)
        //  (1)用户正常下单检查库存
        //  (2)用户微信支付检查库存
        //  (3)微信支付结果异步通知检查库存
        // 2.更新这个订单状态 `order`表.`status`字段 1未支付 --> 2已支付
        // 3.减库存 `product`表.`stock`字段
        // 4.应答微信
        //  如果成功处理返回微信成功处理信息,微信终止后续异步通知
        //  否则返回未成功处理信息,微信继续异步通知尝试
        $config = new \WxPayConfig();
        $notify = new WxNotify();
        $notify->Handle($config);
    }
}
