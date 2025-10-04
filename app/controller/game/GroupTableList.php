<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class GroupTableList extends Base
{
    /**
     * 获取集团台桌列表
     */
    public function group_table_list()
    {
        try {
            // 获取请求参数
            $params = $this->request->param();
            
            // 验证必须的 group_prefix 参数
            if (empty($params['group_prefix'])) {
                LogHelper::error('集团前缀参数缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '集团前缀(group_prefix)不能为空');
            }
            
            // 分页参数
            $page = isset($params['page']) ? intval($params['page']) : 1;
            $limit = isset($params['limit']) ? intval($params['limit']) : 20;
            
            // 查询条件 - group_prefix 是必须条件
            $where = [
                ['group_prefix', '=', $params['group_prefix']]
            ];
            
            // 状态筛选
            if (isset($params['status']) && $params['status'] !== '') {
                $where[] = ['status', '=', intval($params['status'])];
            }
            
            // 游戏类型筛选
            if (isset($params['game_type_id']) && $params['game_type_id']) {
                $where[] = ['game_type_id', '=', intval($params['game_type_id'])];
            }
            
            // 台桌名称搜索
            if (!empty($params['table_title'])) {
                $where[] = ['table_title', 'like', '%' . $params['table_title'] . '%'];
            }
            
            // 查询台桌列表
            $list = Db::name('dianji_table')
                ->where($where)
                ->order('list_order DESC, id DESC')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
            
            // 获取总记录数
            $total = $list->total();
            $data = $list->items();
            
            // 获取游戏类型映射
            if (!empty($data)) {
                $gameTypeIds = array_unique(array_column($data, 'game_type_id'));
                $gameTypes = Db::name('dianji_game_type')
                    ->whereIn('id', $gameTypeIds)
                    ->column('type_name', 'id');
                
                // 补充游戏类型名称和格式化时间
                foreach ($data as &$item) {
                    $item['game_type_name'] = $gameTypes[$item['game_type_id']] ?? '';
                    $item['status_text'] = $item['status'] == 1 ? '正常运行' : '维护中';
                    
                    // 解析配置JSON
                    if (!empty($item['peilv_config'])) {
                        $item['peilv_config'] = json_decode($item['peilv_config'], true);
                    }
                    
                    // 格式化时间
                    $item['create_time_formatted'] = date('Y-m-d H:i:s', strtotime($item['create_time']));
                    $item['update_time_formatted'] = date('Y-m-d H:i:s', strtotime($item['update_time']));
                    
                    // 格式化开始结束时间
                    $item['start_time_formatted'] = !empty($item['start_time']) ? 
                        date('H:i:s', strtotime($item['start_time'])) : null;
                    $item['close_time_formatted'] = !empty($item['close_time']) ? 
                        date('H:i:s', strtotime($item['close_time'])) : null;
                }
            }
            
            $result = [
                'list' => $data,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ];
            
            LogHelper::debug('集团台桌列表查询成功', [
                'group_prefix' => $params['group_prefix'],
                'total' => $total,
                'page' => $page
            ]);
            
            return show($result, 1, '获取成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取集团台桌列表失败', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '获取失败');
        }
    }
}