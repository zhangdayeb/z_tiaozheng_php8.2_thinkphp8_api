<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class LuZhuList extends Base
{
    /**
     * 获取历史露珠列表
     * 
     * @return string JSON响应
     */
    public function luzhu_list()
    {
        LogHelper::debug('获取历史露珠列表请求', [
            'user_id' => self::$user['id'],
            'user_name' => self::$user['user_name']
        ]);
        
        // 获取参数
        $table_id = $this->request->param('table_id', 0);
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 50);
        
        // 参数验证
        if (empty($table_id) || !is_numeric($table_id)) {
            LogHelper::warning('台桌ID参数无效', ['table_id' => $table_id]);
            return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
        }
        
        $table_id = intval($table_id);
        $page = max(1, intval($page));
        $limit = max(1, min(200, intval($limit))); // 限制每页最多200条
        
        LogHelper::debug('查询参数', [
            'table_id' => $table_id,
            'page' => $page,
            'limit' => $limit
        ]);
        
        try {
            // 构建查询条件
            $where = [
                'table_id' => $table_id,
                'status' => 1 // 只查询有效的露珠记录
            ];
            
            // 获取当前时间，控制开奖结果显示 - 强制使用东八区时间
            date_default_timezone_set('Asia/Shanghai');
            $current_time = date('Y-m-d H:i:s');
            
            LogHelper::debug('查询条件（东八区时间）', [
                'where' => $where,
                'current_time' => $current_time
            ]);
            
            // 查询总记录数 - 只显示已到开奖时间的数据
            $total = Db::table('ntp_dianji_lu_zhu')
                ->where($where)
                ->where(function($query) use ($current_time) {
                    $query->whereNull('show_time')
                          ->whereOr('show_time', '<=', $current_time);
                })
                ->count();
            
            LogHelper::debug('查询到总记录数', ['total' => $total]);
            
            // 查询露珠记录 - 移除联表查询，返回所有字段
            $records = Db::table('ntp_dianji_lu_zhu')
                ->where($where)
                ->where(function($query) use ($current_time) {
                    $query->whereNull('show_time')
                          ->whereOr('show_time', '<=', $current_time);
                })
                ->order('create_time desc, id desc') // 按时间倒序，时间相同时按ID倒序
                ->page($page, $limit)
                ->select()
                ->toArray();
            
            LogHelper::debug('查询露珠记录完成', [
                'record_count' => count($records)
            ]);
            
            // 计算分页信息
            $total_pages = ceil($total / $limit);
            $has_more = $page < $total_pages;
            
            $result = [
                'records' => $records,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => $total_pages,
                    'has_more' => $has_more
                ]
            ];
            
            LogHelper::debug('历史露珠查询成功', [
                'table_id' => $table_id,
                'total' => $total,
                'current_page' => $page,
                'record_count' => count($records)
            ]);
            
            return show($result, 1, '获取历史露珠成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取历史露珠失败', [
                'table_id' => $table_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取历史露珠失败：' . $e->getMessage());
        }
    }
}