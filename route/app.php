<?php
use think\facade\Route;


// 首页路由 
Route::rule('/$', '/index/index');
// 获取单个用户详细信息
Route::rule('caipiao/user_info$', '/game.UserInfo/get_user_info');
// 获取台桌信息
Route::rule('caipiao/table_info$', '/game.TableInfo/table_info');
// 获取投注选项
Route::rule('caipiao/bet_xuanxiang_info$', '/game.BetXuanXIang/bet_xuanxiang_info');
// 用户下注接口
Route::rule('caipiao/order$', '/game.Order/order_add');
// 获取用户投注历史记录
Route::rule('caipiao/order_history$', '/game.OrderHistory/order_history_list');
// 获取当前露珠
Route::rule('caipiao/luzhu$', '/game.LuZhu/luzhu_info');
// 获取历史露珠列表
Route::rule('caipiao/luzhu_list$', '/game.LuZhuList/luzhu_list');

// 集团台桌列表
Route::rule('caipiao/group_table_list$', '/game.GroupTableList/group_table_list');
// 集团台桌露珠列表 已开奖 未开奖的 都在
Route::rule('caipiao/group_lu_zhu_by_table_id_list$', '/game.GroupTableLuZhuList/group_lu_zhu_by_table_id_list');
// 集团台桌露珠列表 详情
Route::rule('caipiao/group_lu_zhu_info$', '/game.GroupTableLuZhuList/group_lu_zhu_info');
// 集团台桌露珠列表 增加
Route::rule('caipiao/group_lu_zhu_add$', '/game.GroupTableLuZhuList/group_lu_zhu_add');
// 集团台桌露珠列表 编辑
Route::rule('caipiao/group_lu_edit$', '/game.GroupTableLuZhuList/group_lu_edit');
// 集团台桌露珠列表 删除
Route::rule('caipiao/group_lu_del$', '/game.GroupTableLuZhuList/group_lu_del');