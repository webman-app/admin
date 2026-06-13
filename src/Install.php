<?php
declare(strict_types=1);

namespace Webman\Admin;

class Install
{
    const bool WEBMAN_PLUGIN = true;

    /**
     * @var array
     */
    protected static array $pathRelation = array (
        'plugin/admin' => 'plugin/admin',
    );

    /**
     * 需要在卸载/安装时保留的配置文件
     * @var array
     */
    protected static array $preserveConfigFiles = ['database.php', 'thinkorm.php'];

    /**
     * Install
     * @return void
     */
    public static function install(): void
    {
        // 安装前检测是否有备份的配置文件
        $backupDir = runtime_path() . '/plugin/admin/config';
        foreach (static::$preserveConfigFiles as $file) {
            $backupFile = $backupDir . '/' . $file;
            if (is_file($backupFile)) {
                echo "Found backup config/$file, will restore after install.\n";
            }
        }

        static::installByRelation();

        // 安装后恢复备份的配置文件（覆盖新安装的默认配置）
        foreach (static::$preserveConfigFiles as $file) {
            $backupFile = $backupDir . '/' . $file;
            $targetFile = base_path() . '/plugin/admin/config/' . $file;
            if (is_file($backupFile)) {
                copy($backupFile, $targetFile);
                echo "Restore config/$file from backup.\n";
            }
        }
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall(): void
    {
        // 备份需要保留的配置文件到 runtime 目录
        $configPath = base_path() . '/plugin/admin/config';
        $backupDir = runtime_path() . '/plugin/admin/config';

        foreach (static::$preserveConfigFiles as $file) {
            $sourceFile = $configPath . '/' . $file;
            $backupFile = $backupDir . '/' . $file;
            if (is_file($sourceFile)) {
                if (!is_dir($backupDir)) {
                    mkdir($backupDir, 0755, true);
                }
                copy($sourceFile, $backupFile);
                echo "Backup config/$file to runtime.\n";
            }
        }

        self::uninstallByRelation();
    }

    /**
     * installByRelation
     * @return void
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path().'/'.substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0755, true);
                }
            }
            //symlink(__DIR__ . "/$source", base_path()."/$dest");
            copy_dir(__DIR__ . "/$source", base_path()."/$dest", true);
            echo "Create $dest
";
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $dest) {
            $path = base_path()."/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            echo "Remove $dest
";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }

}
