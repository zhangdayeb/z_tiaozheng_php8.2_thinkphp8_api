<?php
use think\facade\Route;


// 首页路由 
Route::rule('/$', '/index/index');
// 登录
Route::rule('tiaozheng/login$', '/game.Login/login');
// 搜索 
Route::rule('tiaozheng/search$', '/game.Search/search');
// 修改
Route::rule('tiaozheng/change$', '/game.Change/change');
// 获取系统配置
Route::rule('tiaozheng/config$', '/game.SysConfig/get_all_config');