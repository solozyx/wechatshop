<?php


namespace app\api\service;

use app\api\model\Order as OrderModel;
use app\api\model\Product;
use app\api\service\Order as OrderService;
use app\lib\enum\OrderStatusEnum;
use think\Db;
use think\Exception;
//use think\facade\Env;
use Env;
//use think\facade\Log;
use Log;

// $extend = Env::get('root_path') . 'extend';
// require_once $extend . DIRECTORY_SEPARATOR . 'WxPay' . DIRECTORY_SEPARATOR . 'WxPay.Api.php';

use Loader;
Loader::import('WxPay.WxPay',EXTEND_PATH,'.Api.php');

// 继承 WxPayNotify
class WxNotify extends \WxPayNotify
{
    /*
    <xml>
        <appid><![CDATA[wx2421b1c4370ec43b]]></appid>
        <attach><![CDATA[支付测试]]></attach>
        <bank_type><![CDATA[CFT]]></bank_type>
        <fee_type><![CDATA[CNY]]></fee_type>
        <is_subscribe><![CDATA[Y]]></is_subscribe>
        <mch_id><![CDATA[10000100]]></mch_id>
        <nonce_str><![CDATA[5d2b6c2a8db53831f7eda20af46e531c]]></nonce_str>
        <openid><![CDATA[oUpF8uMEb4qRXf22hE3X68TekukE]]></openid>
        <out_trade_no><![CDATA[1409811653]]></out_trade_no>
        <result_code><![CDATA[SUCCESS]]></result_code>
        <return_code><![CDATA[SUCCESS]]></return_code>
        <sign><![CDATA[B552ED6B279343CB493C5DD0D78AB241]]></sign>
        <time_end><![CDATA[20140903131540]]></time_end>
        <total_fee>1</total_fee>
        <coupon_fee><![CDATA[10]]></coupon_fee>
        <coupon_count><![CDATA[1]]></coupon_count>
        <coupon_type><![CDATA[CASH]]></coupon_type>
        <coupon_id><![CDATA[10000]]></coupon_id>
        <coupon_fee><![CDATA[100]]></coupon_fee>
        <trade_type><![CDATA[JSAPI]]></trade_type>
        <transaction_id><![CDATA[1004400740201409030005092168]]></transaction_id>
    </xml>
    */
    // 覆盖 WxPayNotify 的 NotifyProcess 处理自己的业务逻辑
    public function NotifyProcess($data, &$msg)
    {
        // 判断支付结果是否成功
        if ($data['result_code'] == 'SUCCESS') {
            // 1.通过订单号 检查库存 预支付订单接口把业务订单号传给微信
            // 支付结果微信把订单号带回来
            $orderNo = $data['out_trade_no'];
            // TODO:NOTICE 事务与锁防止多次减库
            // (1) 1次支付成功 应该只做1次 减库存操作
            // (2)不做 Db::startTrans(); 可能会对1次微信支付成功做多次减库存操作 概率小 但是有可能
            // (3) 回调接口没给微信返回true 微信就是重复发送支付结果异步通知 比如微信连续发出 2个支付成功通知
            // (4) 第1次通知结果 if ($order->status == 1) 判断通过 检查库存通常执行是很快的
            //     但是  $stockStatus =  $service->checkOrderStock($order->id); 如果检查库存执行的比较慢
            //     超过了微信异步通知的频次 微信发出第2次支付结果异步通知
            //     这时第1次的代码 走到 if ($stockStatus['pass']) { 第1次没来得及减库存 `order`表.`status`字段值还是1
            // (5) 导致第2次 执行 if ($order->status == 1) { 判断通过
            // (6) 2次支付成功的代码 第1次减1次库存 第2次又减1次库存 导致1次支付扣减2次库存 这种情况发生概率小
            // (7) 如果在高并发下可能导致此种情况
            //
            // 要求把 整个 try{} 代码块执行完成后才允许下一次执行 try{} 代码块
            // 如果上1次没执行完就开始第2次 `order`. status` 是来不及更新的 容易出现连续减库存
            // 剩下的异步通知来了需要排队,等我处理完这次的异步通知后再执行下1次
            //
            // 把 try{} 代码块的数据库操作做成1个事务 1次异步通知没执行完 其他异步通知要等待排队

            // 开启数据库事务
            Db::startTrans();

            try {
                // 数据库事务 把数据库操作锁住
                $order = OrderModel::where('order_no', '=', $orderNo)->find();
                // $order = OrderModel::where('order_no', '=', $orderNo)->lock(true)->find();
                // lock(true) 会把该数据库查询锁住 锁住1个数据库操作不能替代数据库事务
                // 有2个异步通知
                //  第1次把该查询锁住 读取status=1 走完查询 没来得及更新订单状态
                //  第2次进来,第1次已经把该查询走完了把锁放开了,因为第1次的减库存没执行,第2次查出来status还是1
                //  所以只有使用事务锁 把 try{} 的数据库操作作为1个完整的业务逻辑来提交 才能解决减库存问题
                //  锁的是 try{} 整体的数据库操作 不能只锁住1个查询

                // 微信支付结果异步通知失败,是连续尝试通知的,只处理订单状态为1未支付的订单
                // 1未支付 2已支付 3已支付已发货 4已支付库存不足未发货
                if ($order->status == 1) {
                    // 检查库存
                    $service = new OrderService;
                    $stockStatus =  $service->checkOrderStock($order->id);
                    // 通过库存检查
                    if ($stockStatus['pass']) {
                        // 更新订单状态 订单id 库存状态 1 -> 2
                        $this->updateOrderStatus($order->id, true);
                        // 减库存 传入用户购买商品信息
                        $this->reduceStock($stockStatus);
                    } else {
                        // 更新订单状态 订单id 库存状态 1 -> 4
                        $this->updateOrderStatus($order->id, false);
                    }
                }

                // 提交数据库事务 把数据库操作放开 第2次来的异步通知才能开始数据库操作
                Db::commit();

                // 业务正常处理了微信支付成功的异步通知
                // 返回 true 给微信 不让微信再次发送异步通知
                return true;
            } catch (Exception $exception) {
                // 回滚数据库事务
                Db::rollback();

                Log::error($exception);
                return false;
            }
        } else {
            // 业务正常处理了微信支付失败的异步通知
            // 返回 true 给微信 不让微信再次发送异步通知
            return true;
        }
    }

    // 更新订单状态
    private function updateOrderStatus($orderID, $stockSuccess)
    {
        // 有库存 `order`.`status` 1未支付 -> 2已支付
        // 无库存 `order`.`status` 1未支付 -> 4已支付库存不足未发货
        $status = $stockSuccess ? OrderStatusEnum::PAID : OrderStatusEnum::PAID_BUT_OUT_OF;
        OrderModel::where('id', '=', $orderID)->update(['status' => $status]);
    }

    // 减库存
    private function reduceStock($stockStatus)
    {
        // 1个支付订单 用户可能购买多个商品
        foreach ($stockStatus['pStatusArray'] as $singlePStatus) {
            // ->setDec 是TP5框架数据库字段减法的便利方法 当前库存量 减去 用户购买该商品的数量
            // `product`表.`stock`库存字段 - 购买它的count
            Product::where('id', '=', $singlePStatus['id'])->setDec('stock', $singlePStatus['count']);
        }
    }
}