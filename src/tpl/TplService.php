<?php
declare(strict_types=1);

namespace think\tpl;

use Symfony\Component\VarExporter\VarExporter;
//use think\Db;
use think\facade\Db;
use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use think\Exception;
/**
 * 模板服务
 * Class Service
 * @package think\tpl
 */
class TplService extends \think\Service
{
    protected $tpl_path;

    public function register()
    {
        var_dump(55);exit;
        $this->$tpl_path = $this->getTplPath();
        // 加载系统语言包
        Lang::load([
            $this->app->getRootPath() . '/vendor/bbstudio/by-tpl/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
        $this->app->bind('tpl', TplService::class);
    }

    /**
     * 启用
     * @param string  $name  插件名称
     * @param boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($tpl_path . $name)) {
            throw new Exception('模板不存在');
        }

        $tplDir = self::getTplDir($name);
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        $files = self::getGlobalFiles($name);

        if ($files) {
            //刷新插件配置缓存
            TplService::config($name, ['files' => $files]);
        }

        // 复制文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }

        // 复制app和public到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($tplDir . $dir)) {
                copydirs($tplDir . $dir, root_path() . $dir);
            }
        }

        // 删除模板目录已复制到全局的文件
        @rmdirs($sourceAssetsDir);
        foreach (self::getCheckDirs() as $k => $dir) {
            @rmdirs($tplDir . $dir);
        }


        $info = get_tpl_info($name);
        $info['state'] = 1;
        unset($info['url']);
        set_tpl_info($name, $info);
        return true;
    }

    /**
     * 禁用
     *
     * @param string  $name  模板名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        $addon_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($addon_path . $name)) {
            throw new Exception('模板不存在');
        }

        $config = TplService::config($name);
        $tplDir = self::getTplDir($name);
        //模板资源目录
        $destAssetsDir = self::getDestAssetsDir($name);
        // 移除模板全局文件
        $list = TplService::getGlobalFiles($name);
        //插件纯净模式时将原有的文件复制回模板目录
        //当无法获取全局文件列表时也将列表复制回模板目录
        if (!$list) {
            if ($config && isset($config['files']) && is_array($config['files'])) {
                foreach ($config['files'] as $index => $item) {
                    //避免切换不同服务器后导致路径不一致
                    $item = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item);
                    //插件资源目录，无需重复复制
                    if (stripos($item, str_replace(root_path(), '', $destAssetsDir)) === 0) {
                        continue;
                    }
                    //检查目录是否存在，不存在则创建
                    $itemBaseDir = dirname($tplDir . $item);
                    if (!is_dir($itemBaseDir)) {
                        @mkdir($itemBaseDir, 0755, true);
                    }
                    if (is_file(root_path() . $item)) {
                        @copy(root_path() . $item, $tplDir . $item);
                    }
                }
                $list = $config['files'];
            }
            //复制目录资源
            if (is_dir($destAssetsDir)) {
                @copydirs($destAssetsDir, $tplDir . 'assets' . DIRECTORY_SEPARATOR);
            }
        }

        $dirs = [];
        foreach ($list as $k => $v) {
            $file = root_path() . $v;
            $dirs[] = dirname($file);
            @unlink($file);
        }

        // 移除模板空目录
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $k => $v) {
            remove_empty_folder($v);
        }

        $info = get_tpl_info($name);
        $info['state'] = 0;
        unset($info['url']);
        set_tpl_info($name, $info);
        return true;
    }


    /**
     * 获取指定模板的目录
     */
    public static function getTplDir($name)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        $dir = $tpl_path . $name . DIRECTORY_SEPARATOR;
        return $dir;
    }

    /**
     * 获取模板源资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getSourceAssetsDir($name)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        return $tpl_path . $name . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
    }

    /**
     * 获取模板目标资源文件夹
     * @param string $name 模板名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = root_path() . str_replace("/", DIRECTORY_SEPARATOR, "public/assets/theme/{$name}/");
        return $assetsDir;
    }

    /**
     * 获取模板在全局的文件
     *
     * @param string  $name         模板名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $tplDir = self::getTplDir($name);
        $checkDirList = self::getCheckDirs();
        $checkDirList = array_merge($checkDirList, ['assets']);
//        $checkDirList = ['assets'];
        $assetDir = self::getDestAssetsDir($name);

        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $k => $dirName) {
            //检测目录是否存在
            if (!is_dir($tplDir . $dirName)) {
                continue;
            }

//            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tplDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );


            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    //如果名称为assets需要做特殊处理
                    if ($dirName === 'assets') {
                        $path = str_replace(root_path(), '', $assetDir) . str_replace($tplDir . $dirName . DIRECTORY_SEPARATOR, '', $filePath);
                    } else {
                        $path = str_replace($tplDir, '', $filePath);
                    }
//                    var_dump($path);exit();
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {//
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        $list = array_filter(array_unique($list));
        return $list;
    }

    /**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
    {
        return [
            'public'
        ];
    }

    /**
     * 读取或修改模板配置
     * @param string $name
     * @param array  $changed
     * @return array
     */
    public static function config($name, $changed = [])
    {
        $tplDir = self::getTplDir($name);
        $tplConfigFile = $tplDir . '.tplrc';
        $config = [];
        if (is_file($tplConfigFile)) {
            $config = (array)json_decode(file_get_contents($tplConfigFile), true);
        }
        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($tplConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }
        return $config;
    }

    /**
     * 获取 templates 路径
     * @return string
     */
    public function getTplPath()
    {
        // 初始化插件目录
        $tpl_path = $this->app->getRootPath() . 'templates' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($tpl_path)) {
            @mkdir($tpl_path, 0755, true);
        }
        return $tpl_path;
    }


    /**
     * 远程下载模板
     *
     * @param string $name   模板名称
     * @param string $url  远程链接
     * @return  string
     */
    public static function download($name,$url='', $extend = [])
    {
        $tplTempDir = self::getTplBackupDir();
        $tmpFile = $tplTempDir . $name . ".zip";
        try {
            $url = urldecode($url);
            if(false === @file_put_contents($tmpFile, file_get_contents($url))){
                return false;
            }
            return $tmpFile;
        } catch (TransferException $e) {
            throw new Exception("模板下载失败");
        }

    }

    /**
     * 解压插件
     *
     * @param string $name 模板名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {

        if (!$name) {
            throw new Exception('无效参数');
        }
        $tplBackupDir = self::getTplBackupDir();
        $file = $tplBackupDir . $name . '.zip';

        // 打开插件压缩包
        $zip = new ZipFile();
        $zip->openFile($file);
        try {
            $zip->openFile($file);
        } catch (ZipException $e) {
            $zip->close();
            throw new Exception('无法打开zip文件');
        }
        $dir = self::getTplDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }
        // 解压插件压缩包
        try {
            $zip->extractTo($dir);
        } catch (ZipException $e) {
            throw new Exception('无法解压ZIP文件');
        } finally {
            $zip->close();
        }
        return $dir;
    }

    /**
     * 检测模板是否完整
     *
     * @param string $name 模板名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($tpl_path . $name)) {
            throw new Exception('模板不存在');
        }
        $tplClass = get_tpl_class($name);
        if (!$tplClass) {
            throw new Exception("模板主启动程序不存在");
        }
        $tpl = new $tplClass(app());
        if (!$tpl->checkInfo()) {
            throw new Exception("配置文件不完整");
        }
        return true;
    }

    /**
     * 是否有冲突
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new Exception("发现冲突文件");
        }
        return true;
    }

    /**
     * 安装
     *
     * @param string  $name   模板名称
     * @param boolean $force  是否覆盖
     * @param array   $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $url='',$force = false, $extend = [])
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        if (!$name || (is_dir($tpl_path . $name) && !$force)) {
            throw new Exception('模板目录已存在');
        }

        $extend['domain'] = request()->host(true);

        // 远程下载
        $tmpFile = TplService::download($name,$url);

        if(!$tmpFile) exit;
            $tplDir = self::getTplDir($name);
        try {
            // 解压插件压缩包到模板目录
            TplService::unzip($name);
            // 检查模板是否完整
            TplService::check($name);
            if (!$force) {
                TplService::noconflict($name);
            }
        } catch (Exception $e) {
            @rmdirs($tplDir);
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }
        $info['config'] = get_tpl_config($name) ? 1 : 0;
        return $info;
    }

    /**
     * 卸载
     *
     * @param string  $name
     * @param boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        $tpl_path = app()->getRootPath() . 'template' . DIRECTORY_SEPARATOR;
        if (!$name || !is_dir($tpl_path . $name)) {
            throw new Exception('模板不存在');
        }

        // 移除全局资源文件
        if ($force) {
            $list = TplService::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink($tpl_path . $v);
            }
        }

        // 移除目录
        rmdirs($tpl_path . $name);
        return true;
    }

    /**
     * 获取顶级域名
     * @param $domain
     * @return string
     */
    public static function getRootDomain($domain)
    {
        $host = strtolower(trim($domain));
        $hostArr = explode('.', $host);
        $hostCount = count($hostArr);
        $cnRegex = '/\w+\.(gov|org|ac|mil|net|edu|com|bj|tj|sh|cq|he|sx|nm|ln|jl|hl|js|zj|ah|fj|jx|sd|ha|hb|hn|gd|gx|hi|sc|gz|yn|xz|sn|gs|qh|nx|xj|tw|hk|mo)\.cn$/i';
        $countryRegex = '/\w+\.(\w{2}|com|net)\.\w{2}$/i';
        if ($hostCount > 2 && (preg_match($cnRegex, $host) || preg_match($countryRegex, $host))) {
            $host = implode('.', array_slice($hostArr, -3, 3, true));
        } else {
            $host = implode('.', array_slice($hostArr, -2, 2, true));
        }
        return $host;
    }

    /**
     * 获取插件备份目录
     */
    public static function getTplBackupDir()
    {
          $dir = app()->getRuntimePath() . 'template' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return config('app.web_market.official_url');
    }

    /**
     * 匹配配置文件中info信息
     * @param ZipFile $zip
     * @return array|false
     * @throws Exception
     */
    protected static function getInfoIni($zip)
    {
        $config = [];
        // 读取信息
        try {
            $info = $zip->getEntryContents('info.ini');
            $config = parse_ini_string($info);
        } catch (ZipException $e) {
            throw new Exception('无法解压文件');
        }
        return $config;
    }

}
