<?php

use app\business\RequestUrl;
use app\business\Curl;
use app\controller\common\LogHelper;


function redis()
{
    return think\facade\Cache::store('redis');
}

/**
 * @param string $type 类型
 * @param string $name 集合名称
 * @param int $key 集合key
 * @param int $val 集合sort
 */
function redis_sort_set(string $type = 'set', string $name = '', int $key = 0, int $val = 0)
{
    if ($type == 'set') {//存入 有序集合
        return redis()->ZADD($name, $key, $val);
    }
    if ($type == 'get') {//获取有序集合指定的值
        return redis()->ZSCORE($name, $key);
    }

    if ($type == 'del') {//删除有序结合指定的key
        return redis()->ZREM($name, $key);
    }

}
// 统一API返回格式
function show($data = [], int $code = 200, string $message = 'ok！', int $httpStatus = 0)
{
    $result = [
        'code' => $code,
        'message' => lang($message),
        'data' => $data,
    ];
    header('Access-Control-Allow-Origin:*');
    if ($httpStatus != 0) {
        return json($result, $httpStatus);
    }
    echo json_encode($result);
    exit();
}
// 获取系统配置
function get_config($name = null)
{
    if ($name == null) {
        return \app\model\SysConfig::select();
    }
    return \app\model\SysConfig::where('name', $name)->find();
}

/**
 * ===============================================================
 * 彩票结算函数 统一区间    开始
 * ===============================================================
 */
// 例子：hezhi_check('1,2,3,4,5',15); // 和值15
function hezhi_check($kaijiang_string='',$target_num = 0){
    $is_zhongjiang = false;  // 默认未中奖
    // 计算开奖号码的和值
    $numbers = explode(',', $kaijiang_string); // 假设开奖号码是逗号分隔的字符串
    $sum = array_sum($numbers); // 计算和值    
    if ($sum == $target_num) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// 例子：da_check('1,2,3,4,5',15); // 大 15
function da_check($kaijiang_string='',$target_num = 6){
    $is_zhongjiang = false;  // 默认未中奖
    // 计算开奖号码的和值
    $numbers = explode(',', $kaijiang_string); // 假设开奖号码是逗号分隔的字符串
    $sum = array_sum($numbers); // 计算和值 
    if ($sum >= $target_num) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// 例子：xiao_check('1,2,3,4,5',15); // 小 15
function xiao_check($kaijiang_string='',$target_num = 6){
    $is_zhongjiang = false;  // 默认未中奖
    // 计算开奖号码的和值
    $numbers = explode(',', $kaijiang_string); // 假设开奖号码是逗号分隔的字符串
    $sum = array_sum($numbers); // 计算和值 
    if ($sum <= $target_num) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// 例子：dan_check('1,2,3,4,5',1); // 单
function dan_check($kaijiang_string='',$target_num = 1){
    $is_zhongjiang = false;  // 默认未中奖
    // 计算开奖号码的和值
    $numbers = explode(',', $kaijiang_string); // 假设开奖号码是逗号分隔的字符串
    $sum = array_sum($numbers); // 计算和值 
    if ($sum % 2 == $target_num) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// 例子：shuang_check('1,2,3,4,5',0); // 双 
function shuang_check($kaijiang_string='',$target_num = 0){
    $is_zhongjiang = false;  // 默认未中奖
    // 计算开奖号码的和值
    $numbers = explode(',', $kaijiang_string); // 假设开奖号码是逗号分隔的字符串
    $sum = array_sum($numbers); // 计算和值 
    if ($sum % 2 == $target_num) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// tongpei_check('1,2,3,4,5','1'); // 单选 
// tongpei_check('1,2,3,4,5','1,2,3,4,5'); // 单选 全中
// tongpei_check('1,2,3,4,5','6'); // 单选 不中
function tongpei_check($kaijiang_string='',$target_string = ''){
    $is_zhongjiang = false;  // 默认未中奖
    $kaijiang_array = explode(',', $kaijiang_string);
    $target_array = explode(',', $target_string);   
    // 检查目标号码是否全部在开奖号码中
    $all_found = true;      
    foreach ($target_array as $num) {
        if (!in_array($num, $kaijiang_array)) {
            $all_found = false;
            break;
        }
    }   
    if ($all_found) {
        $is_zhongjiang = true; // 中奖
    }
    return $is_zhongjiang; // 返回是否中奖
}
// baozi_check('1,2,3,4,5','1,2,3'); // 默认的豹子
// baozi_check('1,2,3,4,5','1'); // 默认的豹子
function baozi_check($kaijiang_string='',$target_string = ''){
    $is_zhongjiang = false;  // 默认未中奖
    $kaijiang_array = explode(',', $kaijiang_string);
    $target_array = explode(',', $target_string);
    // 检查目标号码是否全部在开奖号码中
    $all_found = true;  
    foreach ($target_array as $target_num) {
        foreach ($kaijiang_array as $kaijiang_num){
            if ($kaijiang_num != $target_num){
                $all_found = false;
                break 2; // 跳出两层循环
            }
        }
    }
    if ($all_found) {
        $is_zhongjiang = true; // 中奖
    }   
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * ===============================================================
 * 彩票结算函数 统一区间    结束
 * ===============================================================
 */


/**
 * ===============================================================
 * 六合彩 结算函数 统一区间    开始
 * ===============================================================
 */
// 开奖字符串：$kaijiang_string= '08,14,19,24,28,34|特码:39'
/**
 * 六合彩特码结算函数集合
 * 开奖字符串格式：'08,14,19,24,28,34|特码:39'
 */

/**
 * 从开奖字符串中提取特码
 * @param string $kaijiang_string 开奖字符串
 * @return int|null 特码数字，如果提取失败返回null
 */
function extract_tema($kaijiang_string) {
    // 查找"特码:"的位置
    $pos = strpos($kaijiang_string, '特码:');
    if ($pos === false) {
        return null;
    }
    // 提取特码数字
    $tema_str = substr($kaijiang_string, $pos + strlen('特码:'));
    return intval($tema_str);
}

/**
 * 特码精确匹配
 * @param string $kaijiang_string 开奖字符串
 * @param int $target_num 目标特码数字
 * @return bool 是否中奖
 */
function liuhe_tema($kaijiang_string = '', $target_num = 0) {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 判断特码是否匹配目标数字
    if ($tema == $target_num) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码大（25-48为大，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_da($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 25-48为大
    if ($tema >= 25 && $tema <= 48) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码小（1-24为小，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_xiao($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 1-24为小
    if ($tema >= 1 && $tema <= 24) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码大小和（特码为49时为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_daxiaohe($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 特码为49时为和局，算中奖
    if ($tema == 49) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码单（1-48的奇数，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_dan($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 1-48的奇数为单
    if ($tema >= 1 && $tema <= 48 && $tema % 2 == 1) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码双（2-48的偶数，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_shuang($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 2-48的偶数为双
    if ($tema >= 2 && $tema <= 48 && $tema % 2 == 0) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码单双和（特码为49时为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_danshuanghe($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 特码为49时为和局，算中奖
    if ($tema == 49) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码和数单（特码两位数字相加为奇数，49为和局）
 * 例：01→0+1=1(单)，15→1+5=6(双)
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_heshu_dan($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 计算特码的和数（十位+个位）
    if ($tema >= 1 && $tema <= 48) {
        // 转换为两位数格式
        $tema_str = str_pad($tema, 2, '0', STR_PAD_LEFT);
        $shiwei = intval($tema_str[0]);  // 十位
        $gewei = intval($tema_str[1]);   // 个位
        $heshu = $shiwei + $gewei;       // 和数
        
        // 和数为奇数则中奖
        if ($heshu % 2 == 1) {
            $is_zhongjiang = true;
        }
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码和数双（特码两位数字相加为偶数，49为和局）
 * 例：02→0+2=2(双)，28→2+8=10(双)
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_heshu_shuang($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 计算特码的和数（十位+个位）
    if ($tema >= 1 && $tema <= 48) {
        // 转换为两位数格式
        $tema_str = str_pad($tema, 2, '0', STR_PAD_LEFT);
        $shiwei = intval($tema_str[0]);  // 十位
        $gewei = intval($tema_str[1]);   // 个位
        $heshu = $shiwei + $gewei;       // 和数
        
        // 和数为偶数则中奖
        if ($heshu % 2 == 0) {
            $is_zhongjiang = true;
        }
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码和数和（特码为49时为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_heshu_he($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 特码为49时为和局，算中奖
    if ($tema == 49) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码尾数大（尾数5-9为大，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_weishu_da($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 获取特码的尾数（个位数）
    if ($tema >= 1 && $tema <= 48) {
        $weishu = $tema % 10;  // 取个位数
        
        // 尾数5-9为大
        if ($weishu >= 5 && $weishu <= 9) {
            $is_zhongjiang = true;
        }
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码尾数小（尾数0-4为小，49为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_weishu_xiao($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 49为和局，不算中奖
    if ($tema == 49) {
        return $is_zhongjiang;
    }
    
    // 获取特码的尾数（个位数）
    if ($tema >= 1 && $tema <= 48) {
        $weishu = $tema % 10;  // 取个位数
        
        // 尾数0-4为小
        if ($weishu >= 0 && $weishu <= 4) {
            $is_zhongjiang = true;
        }
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * 特码尾数和（特码为49时为和局）
 * @param string $kaijiang_string 开奖字符串
 * @return bool 是否中奖
 */
function liuhe_tema_weishu_he($kaijiang_string = '') {
    $is_zhongjiang = false;  // 默认未中奖
    
    // 提取特码
    $tema = extract_tema($kaijiang_string);
    if ($tema === null) {
        return $is_zhongjiang;
    }
    
    // 特码为49时为和局，算中奖
    if ($tema == 49) {
        $is_zhongjiang = true;
    }
    
    return $is_zhongjiang; // 返回是否中奖
}

/**
 * ===============================================================
 * 六合彩 结算函数 统一区间    结束
 * ===============================================================
 */