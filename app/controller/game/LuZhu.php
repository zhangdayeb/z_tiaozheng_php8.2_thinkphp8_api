<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class LuZhu extends Base
{
    /**
     * 获取单个露珠信息
     * 
     * @return string JSON响应
     */
    public function luzhu_info()
    {
        LogHelper::debug('获取单个露珠信息请求', [
            'user_id' => self::$user['id'],
            'user_name' => self::$user['user_name']
        ]);
        
        // 获取参数
        $table_id = $this->request->param('table_id', 0);
        $qihao_number = $this->request->param('qihao_number', '');
        
        // 参数验证
        if (empty($table_id) || !is_numeric($table_id)) {
            LogHelper::warning('台桌ID参数无效', ['table_id' => $table_id]);
            return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
        }
        
        if (empty($qihao_number)) {
            LogHelper::warning('期号参数无效', ['qihao_number' => $qihao_number]);
            return show([], config('ToConfig.http_code.error'), '期号必填');
        }
        
        $table_id = intval($table_id);
        
        LogHelper::debug('查询参数', [
            'table_id' => $table_id,
            'qihao_number' => $qihao_number
        ]);
        
        try {
            // 获取当前时间 - 强制使用东八区时间
            date_default_timezone_set('Asia/Shanghai');
            $current_time = date('Y-m-d H:i:s');
            
            LogHelper::debug('使用东八区时间', ['current_time' => $current_time]);
            
            // 先获取记录，然后用PHP判断时间
            LogHelper::debug('开始查询露珠记录', [
                'table_id' => $table_id,
                'qihao_number' => $qihao_number
            ]);
            
            $record = Db::table('ntp_dianji_lu_zhu')
                ->where('table_id', $table_id)
                ->where('qihao_number', $qihao_number)
                ->where('status', 1)
                ->find();
            
            LogHelper::debug('原始查询结果', [
                'record_found' => !empty($record),
                'record_data' => $record
            ]);
            
            if (empty($record)) {
                LogHelper::warning('露珠记录不存在', [
                    'table_id' => $table_id,
                    'qihao_number' => $qihao_number
                ]);
                return show([], config('ToConfig.http_code.error'), '露珠记录不存在');
            }
            
            // PHP时间判断：检查是否到了开奖时间
            if (!empty($record['show_time'])) {
                if ($record['show_time'] > $current_time) {
                    LogHelper::warning('未到开奖时间', [
                        'current_time' => $current_time,
                        'show_time' => $record['show_time'],
                        'qihao_number' => $qihao_number
                    ]);
                    return show([], config('ToConfig.http_code.error'), '开奖结果尚未公布');
                }
            }
            
            LogHelper::debug('时间检查通过', [
                'current_time' => $current_time,
                'show_time' => $record['show_time'],
                'time_ok' => true
            ]);
            
            // 处理记录数据 - 只保留时间格式化
            $record['create_time_formatted'] = date('Y-m-d H:i:s', strtotime($record['create_time']));
            $record['update_time_formatted'] = date('Y-m-d H:i:s', strtotime($record['update_time']));
            $record['show_time_formatted'] = !empty($record['show_time']) ? 
                date('Y-m-d H:i:s', strtotime($record['show_time'])) : null;
            
            LogHelper::debug('露珠信息查询成功', [
                'table_id' => $table_id,
                'qihao_number' => $qihao_number,
                'luzhu_id' => $record['id']
            ]);
            
            return show($record, 1, '获取露珠信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取露珠信息失败', [
                'table_id' => $table_id,
                'qihao_number' => $qihao_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取露珠信息失败');
        }
    }
}