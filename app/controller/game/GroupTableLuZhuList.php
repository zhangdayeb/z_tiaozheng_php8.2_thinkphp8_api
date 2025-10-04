<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class GroupTableLuZhuList extends Base
{
    /**
     * 根据台桌ID获取露珠列表
     */
    public function group_lu_zhu_by_table_id_list()
    {
        try {
            $params = $this->request->param();
            
            // 验证必要参数
            if (empty($params['table_id'])) {
                LogHelper::error('台桌ID参数缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '台桌ID不能为空');
            }
            
            // 分页参数
            $page = isset($params['page']) ? intval($params['page']) : 1;
            $limit = isset($params['limit']) ? intval($params['limit']) : 50;
            
            // 查询条件
            $where = [
                ['table_id', '=', intval($params['table_id'])]
            ];
            
            // 期号搜索
            if (!empty($params['qihao_number'])) {
                $where[] = ['qihao_number', 'like', '%' . $params['qihao_number'] . '%'];
            }
            
            // 日期范围筛选
            if (!empty($params['start_date'])) {
                $where[] = ['create_time', '>=', $params['start_date'] . ' 00:00:00'];
            }
            if (!empty($params['end_date'])) {
                $where[] = ['create_time', '<=', $params['end_date'] . ' 23:59:59'];
            }
            
            // 查询露珠数据 - 按开奖时间倒序
            $list = Db::name('dianji_lu_zhu')
                ->where($where)
                ->order('show_time DESC, id DESC')
                ->paginate([
                    'list_rows' => $limit,
                    'page' => $page
                ]);
            
            $total = $list->total();
            $data = $list->items();
            
            // 处理数据
            foreach ($data as &$item) {
                $item['status_text'] = $this->getStatusText($item['status']);
                
                // 格式化时间
                $item['create_time_formatted'] = date('Y-m-d H:i:s', strtotime($item['create_time']));
                $item['update_time_formatted'] = date('Y-m-d H:i:s', strtotime($item['update_time']));
                $item['show_time_formatted'] = !empty($item['show_time']) ? 
                    date('Y-m-d H:i:s', strtotime($item['show_time'])) : null;
            }
            
            $result = [
                'list' => $data,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ];
            
            LogHelper::debug('露珠列表查询成功', [
                'table_id' => $params['table_id'],
                'total' => $total,
                'page' => $page
            ]);
            
            return show($result, 1, '获取成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取露珠列表失败', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '获取失败');
        }
    }
    /**
     * 获取单个露珠详情
     */
    public function group_lu_zhu_info()
    {
        try {
            $params = $this->request->param();
            
            // 验证必要参数
            if (empty($params['id'])) {
                LogHelper::error('获取露珠详情失败：记录ID缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '记录ID不能为空');
            }
            
            // 查询露珠记录
            $info = Db::name('dianji_lu_zhu')
                ->where('id', intval($params['id']))
                ->find();
                
            if (!$info) {
                LogHelper::warning('获取露珠详情失败：记录不存在', ['id' => $params['id']]);
                return show([], config('ToConfig.http_code.error'), '记录不存在');
            }
            
            LogHelper::debug('获取露珠详情成功', ['id' => $params['id']]);
            
            return show($info, 1, '获取成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取露珠详情异常', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '获取失败');
        }
    }
    /**
     * 添加露珠记录
     */
    public function group_lu_zhu_add()
    {
        try {
            $params = $this->request->param();
            
            // 验证必要字段
            if (empty($params['table_id'])) {
                LogHelper::error('添加露珠失败：台桌ID缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '台桌ID不能为空');
            }
            if (empty($params['game_type'])) {
                LogHelper::error('添加露珠失败：游戏类型缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '游戏类型不能为空');
            }
            if (empty($params['qihao_number'])) {
                LogHelper::error('添加露珠失败：期号缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '期号不能为空');
            }
            
            // 检查期号是否重复
            $exists = Db::name('dianji_lu_zhu')
                ->where('table_id', $params['table_id'])
                ->where('qihao_number', $params['qihao_number'])
                ->find();
                
            if ($exists) {
                LogHelper::warning('添加露珠失败：期号重复', [
                    'table_id' => $params['table_id'],
                    'qihao_number' => $params['qihao_number']
                ]);
                return show([], config('ToConfig.http_code.error'), '该期号已存在');
            }
            
            // 准备插入数据
            $data = [
                'table_id' => intval($params['table_id']),
                'game_type' => intval($params['game_type']),
                'qihao_number' => $params['qihao_number'],
                'result' => $params['result'] ?? '',
                'status' => intval($params['status']) ?: 1,
                'show_time' => $params['show_time'] ?? null,
                'remark' => $params['remark'] ?? '',
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s')
            ];
            
            // 插入数据
            $id = Db::name('dianji_lu_zhu')->insertGetId($data);
            
            if ($id) {
                LogHelper::info('露珠记录添加成功', [
                    'id' => $id,
                    'table_id' => $params['table_id'],
                    'qihao_number' => $params['qihao_number']
                ]);
                
                return show(['id' => $id], 1, '添加成功');
            } else {
                LogHelper::error('露珠记录添加失败：数据库插入失败', ['data' => $data]);
                return show([], config('ToConfig.http_code.error'), '添加失败');
            }
            
        } catch (\Exception $e) {
            LogHelper::error('添加露珠记录异常', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '添加失败');
        }
    }

    /**
     * 编辑露珠记录
     */
    public function group_lu_edit()
    {
        try {
            $params = $this->request->param();
            
            // 验证ID
            if (empty($params['id'])) {
                LogHelper::error('编辑露珠失败：记录ID缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '记录ID不能为空');
            }
            
            // 查找记录
            $record = Db::name('dianji_lu_zhu')
                ->where('id', intval($params['id']))
                ->find();
                
            if (!$record) {
                LogHelper::error('编辑露珠失败：记录不存在', ['id' => $params['id']]);
                return show([], config('ToConfig.http_code.error'), '记录不存在');
            }
            
            // 准备更新数据
            $updateData = [
                'update_time' => date('Y-m-d H:i:s')
            ];
            
            // 可更新字段
            if (isset($params['result'])) {
                $updateData['result'] = $params['result'];
            }
            if (isset($params['status'])) {
                $updateData['status'] = intval($params['status']);
            }
            if (isset($params['show_time'])) {
                $updateData['show_time'] = $params['show_time'];
            }
            if (isset($params['remark'])) {
                $updateData['remark'] = $params['remark'];
            }
            if (isset($params['qihao_number'])) {
                // 检查期号唯一性
                $exists = Db::name('dianji_lu_zhu')
                    ->where('table_id', $record['table_id'])
                    ->where('qihao_number', $params['qihao_number'])
                    ->where('id', '<>', $params['id'])
                    ->find();
                    
                if ($exists) {
                    LogHelper::warning('编辑露珠失败：期号已被使用', [
                        'id' => $params['id'],
                        'qihao_number' => $params['qihao_number']
                    ]);
                    return show([], config('ToConfig.http_code.error'), '期号已被使用');
                }
                $updateData['qihao_number'] = $params['qihao_number'];
            }
            
            // 执行更新
            $result = Db::name('dianji_lu_zhu')
                ->where('id', intval($params['id']))
                ->update($updateData);
                
            if ($result !== false) {
                LogHelper::info('露珠记录编辑成功', [
                    'id' => $params['id'],
                    'update_data' => $updateData
                ]);
                
                return show([], 1, '编辑成功');
            } else {
                LogHelper::error('露珠记录编辑失败：数据库更新失败', [
                    'id' => $params['id'],
                    'update_data' => $updateData
                ]);
                return show([], config('ToConfig.http_code.error'), '编辑失败');
            }
            
        } catch (\Exception $e) {
            LogHelper::error('编辑露珠记录异常', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '编辑失败');
        }
    }

    /**
     * 删除露珠记录
     */
    public function group_lu_del()
    {
        try {
            $params = $this->request->param();
            
            // 验证ID
            if (empty($params['id'])) {
                LogHelper::error('删除露珠失败：记录ID缺失', ['params' => $params]);
                return show([], config('ToConfig.http_code.error'), '记录ID不能为空');
            }
            
            // 支持批量删除
            $ids = is_array($params['id']) ? $params['id'] : [$params['id']];
            $ids = array_map('intval', $ids);
            
            // 直接执行删除
            $result = Db::name('dianji_lu_zhu')
                ->whereIn('id', $ids)
                ->delete();
                
            if ($result > 0) {
                LogHelper::info('露珠记录删除成功', [
                    'ids' => $ids,
                    'deleted_count' => $result
                ]);
                
                return show(['deleted' => $result], 1, '删除成功');
            } else {
                LogHelper::warning('露珠记录删除失败：记录不存在', ['ids' => $ids]);
                return show([], config('ToConfig.http_code.error'), '删除失败或记录不存在');
            }
            
        } catch (\Exception $e) {
            LogHelper::error('删除露珠记录异常', [
                'params' => $params ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return show([], config('ToConfig.http_code.error'), '删除失败');
        }
    }
    
    /**
     * 获取状态文本
     */
    private function getStatusText($status): string
    {
        $statusMap = [
            1 => '待开奖',
            2 => '已开奖',
            3 => '已取消',
            4 => '异常'
        ];
        
        return $statusMap[$status] ?? '未知';
    }
}