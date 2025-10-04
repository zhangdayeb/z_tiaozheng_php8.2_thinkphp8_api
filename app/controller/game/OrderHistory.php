<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class OrderHistory extends Base
{
    /**
     * 获取用户投注历史记录
     * 
     * @return string JSON响应
     */
    public function order_history_list()
    {
        LogHelper::debug('获取用户投注历史记录请求', [
            'user_id' => self::$user['id'],
            'user_name' => self::$user['user_name']
        ]);
        
        // 获取参数
        $table_id = $this->request->param('table_id', 0);
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 20);
        
        // 参数验证
        $page = max(1, intval($page));
        $limit = max(1, min(100, intval($limit))); // 限制每页最多100条
        
        LogHelper::debug('查询参数', [
            'table_id' => $table_id,
            'page' => $page,
            'limit' => $limit
        ]);
        
        try {
            // 构建查询条件
            $where = ['user_id' => self::$user['id']];
            
            // 如果指定了台桌ID，添加到查询条件
            if (!empty($table_id) && is_numeric($table_id)) {
                $where['table_id'] = intval($table_id);
                LogHelper::debug('按台桌ID筛选', ['table_id' => $table_id]);
            }
            
            // 查询总记录数
            $total = Db::table('ntp_dianji_records')
                ->where($where)
                ->count();
            
            LogHelper::debug('查询到总记录数', ['total' => $total]);
            
            // 查询投注记录 - 移除联表查询，返回所有字段
            $records = Db::table('ntp_dianji_records')
                ->where($where)
                ->order('created_at desc')
                ->page($page, $limit)
                ->select()
                ->toArray();
            
            LogHelper::debug('查询投注记录完成', [
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
            
            LogHelper::debug('投注历史查询成功', [
                'user_id' => self::$user['id'],
                'total' => $total,
                'current_page' => $page,
                'record_count' => count($records)
            ]);
            
            return show($result, 1, '获取投注历史成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取投注历史失败', [
                'user_id' => self::$user['id'],
                'table_id' => $table_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取投注历史失败：' . $e->getMessage());
        }
    }
}