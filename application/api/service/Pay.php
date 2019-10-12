<?php


namespace app\api\service;


use app\lib\enum\OrderStatusEnum;
use app\lib\exception\OrderException;
use app\lib\exception\TokenException;
use think\Exception;
use app\api\service\Order as OrderService;
use app\api\model\Order as OrderModel;
//use think\facade\Env;
use Env;
//use think\facade\Log;
use Log;

//$extend = Env::get('root_path') . 'extend';
//require_once $extend . DIRECTORY_SEPARATOR . 'WxPay' . DIRECTORY_SEPARATOR . 'WxPay.Api.php';

// 引入微信支付SDK 没有namespace命名空间机制
// EXTEND_PATH 指定 extend 文件夹
// WxPay.WxPay 在WxPay文件夹下的WxPay前缀的
// .Api.php 文件 extend/WxPay.WxPay.Api.php
// 最好不要修改 EXTEND_PATH 的值,在 extend 目录下的带有 namespace 的类都可以被 TP5 自动加载
use Loader;
Loader::import('WxPay.WxPay',EXTEND_PATH,'.Api.php');

class Pay
{
    // 私有成员 不能被继承
    // 订单id
    private $orderID;
    // 订单编号
    private $orderNO;

    // 构造函数 接收 订单id
    public function __construct($orderID)
    {
        if (!$orderID) {
            throw new Exception('订单号不允许为 NULL');
        }

        $this->orderID = $orderID;
    }

    // 支付操作
    public function pay()
    {
        // 校验前端传入 订单号
        $this->checkOrderValid();

        // 支付前再次进行库存检测(用户下单没有马上支付而是等了几分钟 在支付前还要检查库存 避免已卖光)
        $orderService = new OrderService();
        $status = $orderService->checkOrderStock($this->orderID);
        if (!$status['pass']) {
            // false
            return $status;
        }
        return $this->makeWxPreOrder($status['orderPrice']);
    }

    // 微信支付 预支付订单 微信返回签名结果
    // 用户订单号 + openid 微信扣款微信端用户标识 业务的uid微信是不认识的
    private function makeWxPreOrder($totalPrice)
    {
        // 当前用户在微信端身份标识
        $openid = Token::getCurrentTokenVar('openid');
        if (!$openid) {
            throw new \TokenException();
        }

        // 微信 统一下单输入对象
        // 微信SDK没有命名空间 在前面加 \
        $wxOrderData = new \WxPayUnifiedOrder();
        // 商户订单号 out_trade_no
        $wxOrderData->SetOut_trade_no($this->orderNO);
        // 交易类型 trade_type 小程序取值 JSAPI
        $wxOrderData->SetTrade_type('JSAPI');
        // 标价金额 total_fee 订单总金额 单位 分
        $wxOrderData->SetTotal_fee($totalPrice * 100);
        // 商品描述 body
        $wxOrderData->SetBody('零食商贩');
        // trade_type=JSAPI 此参数必传 用户在商户 appid 下的唯一标识
        $wxOrderData->SetOpenid($openid);
        // 通知地址 notify_url 异步接收微信支付结果通知的回调地址
        // 通知 url 必须为外网可访问 url 不能携带参数
        $wxOrderData->SetNotify_url(config('http://qq.com'));
        return $this->getPaySignature($wxOrderData);
    }

    // 调用微信预支付订单接口
    private function getPaySignature($wxOrderData)
    {
        $config = new \WxPayConfig();
        // 调用微信预支付订单接口
        $wxOrder = \WxPayApi::unifiedOrder($config, $wxOrderData);
        if ($wxOrder['return_code'] != 'SUCCESS' || $wxOrder['result_code'] != 'SUCCESS') {
            Log::record($wxOrder, 'error');
            Log::record('获取微信预支付订单失败', 'error');
        }
        // 微信返回合法订单支付参数
        // prepay_id 用户支付成功后,通过 prepay_id 主动向用户推送1个微信模板消息
        // sign
        // 保存 $wxOrder['prepay_id'] 到 `order`表.`prepay_id`字段
        $this->recordPreOrder($wxOrder);
        // 拼接微信支付参数 生成签名
        $signature = $this->sign($wxOrder);
        return $signature;
    }

    // https://pay.weixin.qq.com/wiki/doc/api/wxa/wxa_api.php?chapter=7_7&index=5
    private function sign($wxOrder)
    {
        // 小程序微信支付参数
        $jsApiPayData = new \WxPayJsApiPay();
        // 小程序 appId
        $config = new \WxPayConfig();
        // $jsApiPayData->SetAppid($config->GetAppId());
        $jsApiPayData->SetAppid(config('wx.app_id'));
        // 时间戳 timeStamp 服务端生成
        $jsApiPayData->SetTimeStamp((string)time());
        // 随机字符串 nonceStr
        // 当前时间戳 mt_rand(0, 1000) 0-1000 的随机数 最终做md5
        // 也可以用其他方式生成随机字符串
        $rand = md5(time() . mt_rand(0, 1000));
        $jsApiPayData->SetNonceStr($rand);
        // 数据包 package
        // $jsApiPayData->SetPackage($wxOrder['prepay_id']);
        // 不能这样写 小程序拉取支付失败
        // 统一下单接口返回的 prepay_id 参数值 提交格式如 prepay_id=wx2017033010242291fcfe0db70013231072
        $jsApiPayData->SetPackage('prepay_id=' . $wxOrder['prepay_id']);
        // 签名方式	signType
        $jsApiPayData->SetSignType('md5');

        // 签名
        // $sign = $jsApiPayData->MakeSign($config);
        $sign = $jsApiPayData->MakeSign();

        $rawValues = $jsApiPayData->GetValues();
        $rawValues['paySign'] = $sign;
        unset($rawValues['appId']);
        return $rawValues;
    }

    // 入参 微信预支付订单 返回结果待支付订单参数
    private function recordPreOrder($wxOrder)
    {
        // prepay_id 保存到 `order`表.`prepay_id`字段
        OrderModel::where('id', '=', $this->orderID)->update(['prepay_id' => $wxOrder['prepay_id']]);
    }

    private function checkOrderValid()
    {
        $order = OrderModel::where('id', '=', $this->orderID)->find();
        if (!$order) {
            // 订单号不存在 抛出异常 被异常处理机制捕获处理
            throw new \OrderException();
        }

        // 下单用户 和 当前访问用户 不匹配 用户合法但是操作别人的数据
        if (!Token::isValidOperate($order->user_id)) {
            throw new \TokenException([
                'msg' => '订单与用户不匹配',
                'errorCode' => 10003,
            ]);
        }

        // 订单支付状态校验 `order`表.`status`字段
        // 1未支付 2已支付 3已发货 4已支付库存不足
        if ($order->status != OrderStatusEnum::UNPAID) {
            throw new \OrderException([
                'msg' => '订单已经支付过啦',
                'errorCode' => 80003,
                'code' => 400,
            ]);
        }

        // 订单编号
        $this->orderNO = $order->order_no;
        return true;
    }
}