<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class SysConfi extends Base
{
    /**
     * 获取所有系统配置
     * 返回配置列表供前端使用
     */
    public function get_all_config()
    {
        try {
            // 不需要验证登录，公开接口
            // 如果需要登录验证，可以取消下面的注释
            /*
            if (empty(self::$user)) {
                return json(['code' => 0, 'msg' => '请先登录']);
            }
            */
            
            // 查询所有系统配置
            $configs = Db::name('common_sys_config')
                ->field('id, name, value, remark')
                ->select();
            
            // 将配置转换为键值对格式，方便前端使用
            $configMap = [];
            foreach ($configs as $config) {
                $configMap[$config['name']] = [
                    'id' => $config['id'],
                    'value' => $config['value'],
                    'remark' => $config['remark']
                ];
            }
            
            // 记录日志
            LogHelper::debug('获取系统配置', [
                'config_count' => count($configs),
                'user_id' => self::$user['id'] ?? 0
            ]);
            
            return json([
                'code' => 1,
                'msg' => '获取成功',
                'data' => [
                    'configs' => $configMap,  // 键值对格式
                    'list' => $configs        // 列表格式
                ]
            ]);
            
        } catch (\Exception $e) {
            LogHelper::error('获取系统配置异常', ['error' => $e->getMessage()]);
            return json([
                'code' => 0, 
                'msg' => '获取配置失败',
                'data' => []
            ]);
        }
    }
    

}