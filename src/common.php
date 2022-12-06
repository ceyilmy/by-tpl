<?php
declare(strict_types=1);

use Symfony\Component\VarExporter\VarExporter;
use think\facade\Event;
use think\facade\Route;
use think\Config as ConfigM;
use \think\facade\Config;
use think\helper\{
    Str, Arr
};



// 模板类库自动载入
spl_autoload_register(function ($class) {
    $class = ltrim($class, '\\');

    $dir = app()->getRootPath();
    $namespace = 'template';

    if (strpos($class, $namespace) === 0) {
        $class = substr($class, strlen($namespace));
        $path = '';
        if (($pos = strripos($class, '\\')) !== false) {
            $path = str_replace('\\', '/', substr($class, 0, $pos)) . '/';
            $class = substr($class, $pos + 1);
        }
        $path .= str_replace('_', '/', $class) . '.php';
        $dir .= $namespace . $path;
        if (file_exists($dir)) {
            include $dir;
            return true;
        }
        return false;
    }
    return false;

});


/***************一下是模板使用的************/
if (!function_exists('get_tpl_list')) {
    /**
     * 获得模板列表
     * @return array
     */
    function get_tpl_list()
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;

        $results = scandir($tpl_path);

        $list = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($tpl_path . $name)) {
                continue;
            }

            $tplDir = $tpl_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($tplDir)) {
                continue;
            }
            if (!is_file($tplDir . ucfirst($name) . '.php')) {
                continue;
            }
            //这里不采用get_addon_info是因为会有缓存
            //$info = get_addon_info($name);
            $info_file = $tplDir . 'info.ini';
            if (!is_file($info_file)) {
                continue;
            }
            $config = new ConfigM();
            $info = $config->load($info_file,$name);
            if (!isset($info['name'])) {
                continue;
            }
            $list[$name] = $info;
        }
        return $list;
    }
}

if (!function_exists('get_tpl_config')) {
    /**
     * 获取模板类的配置值值
     * @param string $name 模板名
     * @return array
     */
    function get_tpl_config($name)
    {
        $tpl = get_tpl_instance($name);
        if (!$tpl) {
            return [];
        }
        return $tpl->getConfig($name);
    }
}
if (!function_exists('set_tpl_fullconfig')) {
    /**
     * 写入配置文件
     *
     * @param string $name  插件名
     * @param array  $array 配置数据
     * @return boolean
     * @throws Exception
     */
    function set_tpl_fullconfig($name, $array)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        $file = $tpl_path . $name . DIRECTORY_SEPARATOR . 'config.php';
        $ret = file_put_contents($file, "<?php\n\n" . "return " . VarExporter::export($array) . ";\n", LOCK_EX);
        if (!$ret) {
            throw new Exception("配置写入失败");
        }
        return true;
    }
}

if (!function_exists('get_tpl_fullconfig')) {
    /**
     * 获取模板类的配置数组
     * @param string $name 模板名
     * @return array
     */
    function get_tpl_fullconfig($name)
    {
        $tpl = get_tpl_instance($name);
        if (!$tpl) {
            return [];
        }
        return $tpl->getFullConfig($name);
    }
}

if (!function_exists('get_tpl_instance')) {
    /**
     * 获取模板的单例
     * @param string $name 模板名
     * @return mixed|null
     */
    function get_tpl_instance($name)
    {

        static $_tpls = [];
        if (isset($_tpls[$name])) {
            return $_tpls[$name];
        }
        $class = get_tpl_class($name);
        if (class_exists($class)) {
            $_tpls[$name] = new $class(app());
            return $_tpls[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('get_tpl_class')) {
    /**
     * 获取模板类的类名
     * @param string $name 模板名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_tpl_class($name, $type = 'tpl', $class = null)
    {
         $name = trim($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = Str::studly(end($class));
            $class = implode('\\', $class);
        } else {
            $class = Str::studly(is_null($class) ? $name : $class);
        }

        switch ($type) {
            case 'controller':
                $namespace = '\\template\\' . $name . '\\controller\\' . $class;
                break;
            default:
                $namespace = '\\template\\' . $name . '\\'. $class;
        }
        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('get_tpl_info')) {
    /**
     * 读取模板的基础信息
     * @param string $name 模板名
     * @return array
     */
    function get_tpl_info($name)
    {
        $tpl = get_tpl_instance($name);
        if (!$tpl) {
            return [];
        }
        return $tpl->getInfo($name);
    }
}

if (!function_exists('set_tpl_info')) {
    /**
     * 设置基础配置信息
     * @param string $name  模板名
     * @param array  $array 配置数据
     * @return boolean
     * @throws Exception
     */
    function set_tpl_info($name, $array)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        $file = $tpl_path . $name . DIRECTORY_SEPARATOR . 'info.ini';
        $tpl = get_tpl_instance($name);
        $array = $tpl->setInfo($name, $array);
        if (!isset($array['name']) || !isset($array['title']) || !isset($array['version'])) {
            throw new Exception("模板配置写入失败");
        }
        $res = array();
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $res[] = "[$key]";
                foreach ($val as $skey => $sval) {
                    $res[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
                }
            } else {
                $res[] = "$key = " . (is_numeric($val) ? $val : $val);
            }
        }
//            var_dump($res);exit;
        if (file_put_contents($file, implode("\n", $res) . "\n", LOCK_EX)) {
            //清空当前配置缓存
            Config::set([], "tpl_{$name}_info");
        } else {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }
}
