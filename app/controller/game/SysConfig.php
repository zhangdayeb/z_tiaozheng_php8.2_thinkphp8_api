<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\BaseController;
use think\facade\Db;

class SysConfig extends BaseController
{
    /**
     * 获取所有系统配置
     * 不需要认证，公开接口
     */
    public function get_all_config()
    {
        try {
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
            
            // 记录日志（调试级别）
            LogHelper::debug('获取系统配置', [
                'config_count' => count($configs)
            ]);
            
            return json([
                'code' => 1,
                'msg' => '获取成功',
                'data' => $configMap
            ]);
            
        } catch (\Exception $e) {
            LogHelper::error('获取系统配置异常：' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return json([
                'code' => 0, 
                'msg' => '获取配置失败',
                'data' => []
            ]);
        }
    }
}