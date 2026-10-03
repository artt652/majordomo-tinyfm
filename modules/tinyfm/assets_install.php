<?php
/**
 * Дополнительные библиотеки Tiny File Manager (modules/tinyfm/assets-extra/) из npm:
 * редактор кода ace («Улучшенный редактор») и все темы подсветки highlight.js.
 *
 * Основные библиотеки (bootstrap, jquery, font-awesome, dropzone, datatables,
 * highlight.js) входят в модуль (assets/) — без дополнительных всё работает и офлайн,
 * а ace без них грузится с CDN. Ставятся кнопкой «Скачать» в настройках модуля
 * (или вручную: php assets_install.php <module_dir> <tmp_dir>).
 *
 * Версии — ровно те, что подключает Tiny File Manager 2.6. Каждый пакет проверяется
 * по SHA-512 из npm-реестра (dist.integrity); опубликованные в npm версии неизменяемы.
 * Из пакетов берутся только нужные файлы.
 */

// Прямой вызов по HTTP запрещён: из веба файл только подключается модулем.
if (PHP_SAPI !== 'cli' && !defined('DIR_MODULES')) {
    http_response_code(403);
    exit;
}

if (!function_exists('tinyfm_assets_install')) {
    /**
     * Пакеты и что из них взять: 'путь в пакете' => 'путь в assets/'.
     * Путь с «/» на конце — папка целиком; 'exclude' — какие файлы пропустить.
     */
    function tinyfm_assets_packages()
    {
        return array(
            array(
                'name' => 'ace-builds 1.32.2',
                'path' => 'ace-builds/-/ace-builds-1.32.2.tgz',
                'integrity' => 'sha512-mnJAc803p+7eeDt07r6XI7ufV7VdkpPq4gJZT8Jb3QsowkaBTVy4tdBgPrVT0WbXLm0toyEQXURKSVNj/7dfJQ==',
                'files' => array(
                    'package/src-min-noconflict/' => 'ace/',
                    'package/LICENSE' => 'ace/LICENSE',
                ),
            ),
            array(
                'name' => 'highlight.js 11.9.0',
                'path' => '@highlightjs/cdn-assets/-/cdn-assets-11.9.0.tgz',
                'integrity' => 'sha512-F1vJKVAkLwj2Uz2ik1PDc+mDbkrecLI6gcBlAxSRUjyDpMPJjeDBanT9Y2B+xpNe1MT6zSG204Ohm/+nUMCApQ==',
                'files' => array(
                    'package/styles/' => 'highlight/styles/',
                    'package/LICENSE' => 'highlight/LICENSE',
                ),
                // в styles/ есть и несжатые .css — нужны только .min.css (и картинки тем)
                'exclude' => '#(?<!\.min)\.css$#',
            ),
        );
    }

    /**
     * Рекурсивное удаление — только внутри разрешённых папок
     */
    function tinyfm_assets_rmdir($dir, $allowed)
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $real = realpath($dir);
        $ok = false;
        foreach ($allowed as $root) {
            $root = realpath($root);
            if ($root && $real && $real !== $root && strpos($real . '/', rtrim($root, '/') . '/') === 0) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return;
        }
        foreach (scandir($real) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $real . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                tinyfm_assets_rmdir($path, $allowed);
            } else {
                @unlink($path);
            }
        }
        @rmdir($real);
    }

    function tinyfm_assets_download($url, $file, $proxy, $proxy_auth)
    {
        $fh = @fopen($file, 'wb');
        if (!$fh) {
            return 'не удалось создать временный файл';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MajorDoMo tinyfm');
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if ($proxy_auth !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);
            }
        }
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        return $ok ? '' : $err;
    }

    /**
     * Распаковывает из .tgz только нужные пути (файлы и папки) в $dest.
     * Сначала tar, иначе PharData.
     */
    function tinyfm_assets_extract($archive, $dest, $members)
    {
        $names = array();
        foreach ($members as $m) {
            $names[] = rtrim($m, '/');
        }
        if (function_exists('exec')) {
            $cmd = 'tar xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dest);
            foreach ($names as $n) {
                $cmd .= ' ' . escapeshellarg($n);
            }
            $out = array();
            $res = 1;
            @exec($cmd . ' 2>&1', $out, $res);
            if ($res === 0) {
                return true;
            }
        }
        if (!class_exists('PharData')) {
            return false;
        }
        // В npm-архивах нет записей для папок, поэтому перебираем файлы
        try {
            $phar = new PharData($archive);
            $prefix = 'phar://' . $phar->getPath() . '/';
            $found = array_fill_keys($names, false);
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                $rel = substr($entry->getPathname(), strlen($prefix));
                foreach ($names as $n) {
                    if ($rel === $n || strpos($rel, $n . '/') === 0) {
                        $target = $dest . '/' . $rel;
                        if (!is_dir(dirname($target))) {
                            @mkdir(dirname($target), 0777, true);
                        }
                        if (@file_put_contents($target, file_get_contents($entry->getPathname())) === false) {
                            return false;
                        }
                        $found[$n] = true;
                        break;
                    }
                }
            }
            unset($phar);
            return !in_array(false, $found, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    function tinyfm_assets_copy($src, $dst, $exclude)
    {
        if (is_dir($src)) {
            foreach (scandir($src) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (!tinyfm_assets_copy($src . '/' . $item, $dst . '/' . $item, $exclude)) {
                    return false;
                }
            }
            return true;
        }
        if (!is_file($src)) {
            return false;
        }
        if ($exclude !== '' && preg_match($exclude, basename($src))) {
            return true;
        }
        if (!is_dir(dirname($dst))) {
            @mkdir(dirname($dst), 0777, true);
        }
        return @copy($src, $dst);
    }

    /**
     * Скачивает пакеты из npm, проверяет SHA-512 и собирает <module_dir>/assets-extra.
     * Старая папка assets-extra/ заменяется только после успешной сборки.
     * Возвращает '' при успехе или текст ошибки.
     */
    function tinyfm_assets_install($module_dir, $tmp_dir, $registry = '', $proxy = '', $proxy_auth = '')
    {
        $module_dir = rtrim($module_dir, '/');
        $tmp_dir = rtrim($tmp_dir, '/');
        $registry = rtrim($registry !== '' ? $registry : 'https://registry.npmjs.org', '/');
        $work = $tmp_dir . '/tinyfm_assets_' . getmypid() . '_' . time();
        $allowed = array($module_dir, $tmp_dir);

        @set_time_limit(900);
        @ignore_user_abort(true);
        if (!preg_match('#^https?://#i', $registry)) {
            return 'некорректный адрес npm-реестра';
        }
        if (!function_exists('curl_init')) {
            return 'в PHP нет расширения curl';
        }
        if (!is_dir($tmp_dir)) {
            @mkdir($tmp_dir, 0777, true);
        }
        if (!is_writable($module_dir) || !is_writable($tmp_dir)) {
            return 'нет прав на запись в ' . $module_dir . ' или ' . $tmp_dir;
        }
        if (!@mkdir($work . '/assets-extra', 0777, true)) {
            return 'не удалось создать временную папку';
        }

        $names = array();
        foreach (tinyfm_assets_packages() as $i => $pkg) {
            $names[] = $pkg['name'];
            $archive = $work . '/pkg' . $i . '.tgz';
            $unpack = $work . '/pkg' . $i;
            $err = tinyfm_assets_download($registry . '/' . $pkg['path'], $archive, $proxy, $proxy_auth);
            if ($err === '') {
                $sri = 'sha512-' . base64_encode((string)hash_file('sha512', $archive, true));
                if (!hash_equals($pkg['integrity'], $sri)) {
                    $err = 'контрольная сумма не совпадает';
                }
            }
            if ($err === '') {
                @mkdir($unpack, 0777, true);
                if (!tinyfm_assets_extract($archive, $unpack, array_keys($pkg['files']))) {
                    $err = 'не удалось распаковать';
                }
            }
            if ($err === '') {
                $exclude = isset($pkg['exclude']) ? $pkg['exclude'] : '';
                foreach ($pkg['files'] as $from => $to) {
                    if (!tinyfm_assets_copy($unpack . '/' . rtrim($from, '/'), $work . '/assets-extra/' . rtrim($to, '/'), $exclude)) {
                        $err = 'нет файла ' . $from;
                        break;
                    }
                }
            }
            if ($err !== '') {
                tinyfm_assets_rmdir($work, $allowed);
                return $pkg['name'] . ': ' . $err;
            }
            @unlink($archive);
            tinyfm_assets_rmdir($unpack, $allowed);
        }
        @file_put_contents($work . '/assets-extra/VERSION', "Tiny File Manager 2.6 extra assets (npm)\n" . implode("\n", $names) . "\n");

        // Замена assets-extra/
        $target = $module_dir . '/assets-extra';
        $old = $module_dir . '/assets-extra.old';
        tinyfm_assets_rmdir($old, $allowed);
        if (is_dir($target) && !@rename($target, $old)) {
            tinyfm_assets_rmdir($work, $allowed);
            return 'не удалось заменить папку assets-extra';
        }
        if (!@rename($work . '/assets-extra', $target)) {
            if (is_dir($old)) {
                @rename($old, $target);
            }
            tinyfm_assets_rmdir($work, $allowed);
            return 'не удалось переместить папку assets-extra';
        }
        tinyfm_assets_rmdir($old, $allowed);
        tinyfm_assets_rmdir($work, $allowed);
        return '';
    }
}

// Ручной запуск: php assets_install.php <module_dir> <tmp_dir> [registry]
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (count($argv) < 3) {
        fwrite(STDERR, "usage: php assets_install.php <module_dir> <tmp_dir> [registry]\n");
        exit(2);
    }
    $err = tinyfm_assets_install($argv[1], $argv[2], isset($argv[3]) ? $argv[3] : '');
    echo ($err === '' ? "OK\n" : $err . "\n");
    exit($err === '' ? 0 : 1);
}
