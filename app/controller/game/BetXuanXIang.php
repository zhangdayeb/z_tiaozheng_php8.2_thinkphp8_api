<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class BetXuanXIang extends Base
{
    /**
     * 获取投注选项信息
     * 
     * @return string JSON响应
     */
    public function bet_xuanxiang_info()
    {
        LogHelper::debug('获取投注选项信息请求');
        
        try {
            // 获取参数
            $table_id = $this->request->param('table_id', 0);
            
            // 参数验证
            if (empty($table_id) || !is_numeric($table_id)) {
                LogHelper::warning('台桌ID参数无效', ['table_id' => $table_id]);
                return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
            }
            
            $table_id = intval($table_id);
            
            LogHelper::debug('查询台桌投注选项', ['table_id' => $table_id]);
            
            // Step 1: 查询台桌基础信息
            $tableInfo = $this->getTableInfo($table_id);
            if (!$tableInfo) {
                return show([], config('ToConfig.http_code.error'), '台桌不存在或已停用');
            }
            
            // Step 2: 获取分组和赔率配置（联表查询）
            $peilvList = $this->getPeilvWithGroup($tableInfo['game_type_id']);
            
            // Step 3: 应用个性化配置
            if (!empty($tableInfo['peilv_config'])) {
                $peilvList = $this->applyCustomConfig($peilvList, $tableInfo['peilv_config']);
            }
            
            // Step 4: 数据分组处理
            $groupedData = $this->groupPeilvData($peilvList);
            
            // Step 5: 构建响应数据
            $responseData = [
                'table_id' => $table_id,
                'table_title' => $tableInfo['table_title'],
                'game_type_id' => $tableInfo['game_type_id'],
                'status' => $tableInfo['status'],
                'groups' => $groupedData
            ];
            
            LogHelper::debug('投注选项获取成功', [
                'table_id' => $table_id,
                'groups_count' => count($groupedData),
                'total_options' => $this->countTotalOptions($groupedData)
            ]);
            
            return show($responseData, config('ToConfig.http_code.success'), '获取投注选项成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取投注选项失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '系统错误，请稍后重试');
        }
    }
    
    /**
     * 获取台桌信息
     * 
     * @param int $table_id 台桌ID
     * @return array|false
     */
    private function getTableInfo($table_id)
    {
        $tableInfo = Db::table('ntp_dianji_table')
            ->where('id', $table_id)
            ->where('status', 1) // 只获取正常状态的台桌
            ->find();
            
        if (empty($tableInfo)) {
            LogHelper::warning('台桌不存在或已停用', ['table_id' => $table_id]);
            return false;
        }
        
        LogHelper::debug('台桌基础信息查询成功', [
            'table_id' => $table_id,
            'game_type_id' => $tableInfo['game_type_id'],
            'table_title' => $tableInfo['table_title']
        ]);
        
        return $tableInfo;
    }
    
    /**
     * 获取赔率配置（联表查询分组信息）
     * 
     * @param int $game_type_id 游戏类型ID
     * @return array
     */
    private function getPeilvWithGroup($game_type_id)
    {
        $peilvList = Db::table('ntp_dianji_game_peilv')
            ->alias('p')
            ->leftJoin('ntp_dianji_game_peilv_group g', 'p.game_group_id = g.id')
            ->field([
                'p.id',
                'p.game_type_id',
                'p.game_tip_name',
                'p.game_function',
                'p.game_canshu',
                'p.game_group_id',
                'p.peilv',
                'p.sort',
                'p.xian_hong_max',
                'p.xian_hong_min',
                'g.group_name',
                'g.list_order',
                'g.icon as group_icon'
            ])
            ->where('p.game_type_id', $game_type_id)
            ->order('g.list_order DESC, p.sort ASC, p.id ASC')
            ->select()
            ->toArray();
            
        LogHelper::debug('赔率配置查询完成', [
            'game_type_id' => $game_type_id,
            'peilv_count' => count($peilvList)
        ]);
        
        return $peilvList;
    }
    
    /**
     * 应用台桌个性化配置
     * 
     * @param array $peilvList 原始赔率列表
     * @param string $customConfig 个性化配置JSON
     * @return array
     */
    private function applyCustomConfig($peilvList, $customConfig)
    {
        try {
            $configData = json_decode($customConfig, true);
            if (!is_array($configData)) {
                LogHelper::warning('个性化配置解析失败', ['config' => $customConfig]);
                return $peilvList;
            }
            
            // 构建配置映射
            $configMap = [];
            foreach ($configData as $config) {
                if (isset($config['peilv_id'])) {
                    $configMap[$config['peilv_id']] = $config;
                }
            }
            
            // 应用配置
            foreach ($peilvList as &$peilvItem) {
                $peilvId = $peilvItem['id'];
                
                if (isset($configMap[$peilvId])) {
                    $custom = $configMap[$peilvId];
                    
                    // 覆盖赔率
                    if (isset($custom['peilv'])) {
                        $peilvItem['peilv'] = floatval($custom['peilv']);
                    }
                    
                    // 覆盖最小限红
                    if (isset($custom['xianhong_min'])) {
                        $peilvItem['xian_hong_min'] = intval($custom['xianhong_min']);
                    }
                    
                    // 覆盖最大限红
                    if (isset($custom['xianhong_max'])) {
                        $peilvItem['xian_hong_max'] = intval($custom['xianhong_max']);
                    }
                    
                    LogHelper::debug('应用个性化配置', [
                        'peilv_id' => $peilvId,
                        'custom' => $custom
                    ]);
                }
            }
            
            LogHelper::debug('个性化配置应用完成');
            return $peilvList;
            
        } catch (\Exception $e) {
            LogHelper::error('应用个性化配置失败', ['error' => $e->getMessage()]);
            return $peilvList;
        }
    }
    
    /**
     * 将赔率数据按分组组织
     * 
     * @param array $peilvList 赔率列表
     * @return array
     */
    private function groupPeilvData($peilvList)
    {
        $groups = [];
        $groupsMap = [];
        
        foreach ($peilvList as $item) {
            // 获取分组ID，如果没有分组则设为0
            $groupId = $item['game_group_id'] ?? 0;
            
            // 如果该分组还未创建，则初始化
            if (!isset($groupsMap[$groupId])) {
                $groupsMap[$groupId] = [
                    'group_id' => $groupId,
                    'group_name' => $item['group_name'] ?? '其他',
                    'group_order' => $item['list_order'] ?? 0,
                    'group_icon' => $item['group_icon'] ?? '🎮',
                    'options' => []
                ];
            }
            
            // 添加投注选项到对应分组
            $groupsMap[$groupId]['options'][] = [
                'id' => $item['id'],
                'name' => $item['game_tip_name'],
                'peilv' => floatval($item['peilv']),
                'min_bet' => intval($item['xian_hong_min']),
                'max_bet' => intval($item['xian_hong_max']),
                'game_function' => $item['game_function'],
                'game_canshu' => $item['game_canshu']
            ];
        }
        
        // 转换为索引数组并保持排序
        $groups = array_values($groupsMap);
        
        // 按group_order降序排列（大的在前）
        usort($groups, function($a, $b) {
            return $b['group_order'] - $a['group_order'];
        });
        
        LogHelper::debug('数据分组完成', [
            'groups_count' => count($groups),
            'groups' => array_map(function($g) {
                return [
                    'name' => $g['group_name'],
                    'options_count' => count($g['options'])
                ];
            }, $groups)
        ]);
        
        return $groups;
    }
    
    /**
     * 统计总选项数
     * 
     * @param array $groups 分组数据
     * @return int
     */
    private function countTotalOptions($groups)
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += count($group['options']);
        }
        return $total;
    }
}