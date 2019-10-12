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

    public function receiveNotify()
    {
        $config = new \WxPayConfig();
        $notify = new WxNotify();
        $notify->Handle($config);
    }
}
