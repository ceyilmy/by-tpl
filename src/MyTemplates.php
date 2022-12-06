<?php
declare(strict_types=1);

namespace think;

use think\App;
use think\helper\Str;
use think\facade\Config;
use think\facade\View;

abstract class MyTemplates
{
    // app 容器
    protected $app;
    // 请求对象
    protected $request;
    // 当前模板标识
    protected $name;
    // 模板路径
    protected $tpl_path;
    // 视图模型
    protected $view;
    // 模板配置
    protected $tpl_config;
    // 模板信息
    protected $tpl_info;

    /**
     * 模板构造函数
     * Addons constructor.
     * @param \think\App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->name = $this->getName();
        $this->tpl_path = root_path().'template'.DIRECTORY_SEPARATOR. $this->name . DIRECTORY_SEPARATOR;
        $this->tpl_config = "tpl_{$this->name}_config";
        $this->tpl_info = "tpl_{$this->name}_info";
        $this->view = clone View::engine('Think');
        $this->view->config([
            'view_path' => $this->tpl_path . 'view' . DIRECTORY_SEPARATOR
        ]);

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}


    //必须实现安装
    abstract public function install();

    //必须卸载插件方法
    abstract public function uninstall();


    /**
     * 获取当前模块名
     * @return string
     */
    final public function getName()
    {
        $class = get_class($this);
        list(, $name, ) = explode('\\', $class);
        $this->request->tpl = $name;
        return $name;
    }
    /**
     * 设置插件标识
     * @param $name
     */
    final public function setName($name)
    {
        $this->name = $name;
    }


    /**
     * 读取基础配置信息
     * @param string $name
     * @return array
     */
    final public function getInfo($name = '', $force = false)
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        if (!$force) {
            $info = Config::get($this->tpl_info, []);

            if ($info) {
                return $info;
            }
        }
        // 文件属性
        $info = $this->info ?? [];
        // 文件配置
        $info_file = $this->tpl_path . 'info.ini';
        if (is_file($info_file)) {
            $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            $_info['url'] = addons_url();
            $info = array_merge($_info, $info);
        }
        Config::set($info,$this->tpl_info);
        return isset($info) ? $info : [];
    }

    /**
     * 获取插件的配置数组
     * @param string $name 可选模块名
     * @return array
     */
    final public function getConfig($name = '', $force = false)
    {

        if (empty($name)) {
            $name = $this->getName();
        }
        if (!$force) {
            $config = Config::get($this->tpl_config, []);
            if ($config) {
                return $config;
            }
        }
        $config = [];
        $config_file = $this->tpl_path . 'config.php';
        if (is_file($config_file)) {
            $configArr = include $config_file;
            if (is_array($configArr)) {
                foreach ($configArr as $key => $value) {
                    $config[$value['name']] = $value['value'];
                }
                unset($configArr);
            }
        }
        Config::set($config, $this->tpl_config);
        return $config;
    }

    /**
     * 设置配置数据
     * @param       $name
     * @param array $value
     * @return array
     */
    final public function setConfig($name = '', $value = [])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $config = $this->getConfig($name);
        $config = array_merge($config, $value);
        Config::set($config, $this->addon_config);
        return $config;
    }
    /**
     * 设置插件信息数据
     * @param       $name
     * @param array $value
     * @return array
     */
    final public function setInfo($name = '', $value = ['1'])
    {
        if (empty($name)) {
            $name = $this->getName();
        }
        $info = $this->getInfo($name);
        $info = array_merge($info,$value);
//        var_dump($info);exit;
        Config::set($info,$this->tpl_info);
        return $info;
    }

    /**
     * 获取完整配置列表
     * @param string $name
     * @return array
     */
    final public function getFullConfig($name = '')
    {
        $fullConfigArr = [];
        if (empty($name)) {
            $name = $this->getName();
        }
        $configFile = $this->tpl_path . 'config.php';
        if (is_file($configFile)) {
            $fullConfigArr = include $configFile;
        }
        return $fullConfigArr;
    }

    /**
     * 检查基础配置信息是否完整
     * @return bool
     */
    final public function checkInfo()
    {
        $info = $this->getInfo();
        $info_check_keys = ['name', 'title', 'intro', 'author', 'version', 'state', 'type'];
        foreach ($info_check_keys as $value) {
            if (!array_key_exists($value, $info)) {
                return false;
            }
        }
        return true;
    }

}
