<?php

namespace app\model;

use think\Model;

class UserModel extends Model
{
    // 设置表名（不含前缀）
    protected $name = 'common_user';
    
    /**
     * 根据ID查询用户信息
     * @param int $id
     * @return array
     */
    public static function page_one(int $id)
    {
        try {
            $find = self::where('id', $id)->find();
            if (empty($find)) {
                return [];
            }
            return $find->toArray();
        } catch (\Exception $e) {
            // 记录错误日志
            trace('查询用户失败：' . $e->getMessage(), 'error');
            return [];
        }
    }
}