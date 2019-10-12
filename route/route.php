<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

//use think\facade\Route;
use Route;


// 微信预支付订单接口
Route::post('api/:version/pay/pre_order', 'api/:version.Pay/getPreOrder');
// 微信支付结果 异步通知接口 让微信端调用
// 微信通过 post 方式调用该接口
// 微信通知结果数据是 xml 格式
// 该接口对应的 url 不能携带参数 如 http://test?a=1 错误的
Route::post('api/:version/pay/notify', 'api/:version.Pay/receiveNotify');


