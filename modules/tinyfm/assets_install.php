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
 *
 * Из панели скачивание идёт фоновым процессом (php assets_install.php --job <файл>):
 * ход пишется в cms/cached/tinyfm_assets/install.log, итог — в state.json; страница
 * настроек показывает журнал и обновляет его, пока скачивание не закончится.
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

    function tinyfm_assets_mb($bytes)
    {
        return number_format($bytes / 1048576, 1, ',', '') . ' МБ';
    }

    function tinyfm_assets_download($url, $file, $proxy, $proxy_auth, $progress = null)
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
        if (is_callable($progress)) {
            // не чаще раза в 2 секунды: «скачано X из Y»
            $last = microtime(true);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $total, $done) use ($progress, &$last) {
                if ($done > 0 && microtime(true) - $last >= 2) {
                    $last = microtime(true);
                    call_user_func($progress, $done, $total);
                }
                return 0;
            });
        }
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
     * Способы по очереди: tar, PharData, встроенный разбор tar на PHP (gzopen) — последний
     * не зависит ни от внешних программ, ни от расширения phar (важно для Windows, где
     * tar.exe может отсутствовать или быть GNU tar из Git, а phar — вести себя иначе).
     * Возвращает '' при успехе или описание ошибок всех способов.
     */
    function tinyfm_assets_extract($archive, $dest, $members, $say = null)
    {
        $names = array();
        foreach ($members as $m) {
            $names[] = rtrim($m, '/');
        }
        $errors = array();

        // 1. tar. Запуск из папки архива с относительными путями: GNU tar (в т.ч. из Git
        // для Windows) принимает «C:\...» за адрес удалённого хоста.
        if (function_exists('exec')) {
            $cwd = getcwd();
            if (@chdir(dirname($archive))) {
                $dest_arg = dirname($dest) === dirname($archive) ? basename($dest) : $dest;
                $cmd = 'tar xzf ' . escapeshellarg(basename($archive)) . ' -C ' . escapeshellarg($dest_arg);
                foreach ($names as $n) {
                    $cmd .= ' ' . escapeshellarg($n);
                }
                $out = array();
                $res = 1;
                @exec($cmd . ' 2>&1', $out, $res);
                if ($cwd !== false) {
                    @chdir($cwd);
                }
                if ($res === 0 && tinyfm_assets_members_exist($dest, $names)) {
                    return '';
                }
                $errors[] = 'tar: ' . ($res === 0 ? 'нет нужных файлов после распаковки'
                    : 'код ' . $res . (empty($out) ? '' : ', ' . trim((string)end($out))));
            } else {
                $errors[] = 'tar: нет доступа к папке архива';
            }
        } else {
            $errors[] = 'tar: exec недоступен';
        }

        // 2. PharData. В npm-архивах нет записей для папок, поэтому перебираем файлы;
        // относительный путь — всё после «<имя архива>/» (на Windows phar:// пишет путь
        // со своими разделителями, поэтому длину префикса не считаем).
        if (class_exists('PharData')) {
            try {
                $phar = new PharData($archive);
                $base = basename($archive) . '/';
                foreach (new RecursiveIteratorIterator($phar) as $entry) {
                    $pn = str_replace('\\', '/', $entry->getPathname());
                    $pos = strpos($pn, $base);
                    if ($pos === false) {
                        continue;
                    }
                    $rel = substr($pn, $pos + strlen($base));
                    if (tinyfm_assets_wanted($rel, $names)
                        && !tinyfm_assets_put($dest . '/' . $rel, file_get_contents($entry->getPathname()))
                    ) {
                        throw new Exception('не удалось записать ' . $rel);
                    }
                }
                unset($phar);
                if (tinyfm_assets_members_exist($dest, $names)) {
                    if (is_callable($say)) {
                        call_user_func($say, 'распаковано через phar (' . implode('; ', $errors) . ')');
                    }
                    return '';
                }
                $errors[] = 'phar: нет нужных файлов после распаковки';
            } catch (\Throwable $e) {
                $errors[] = 'phar: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'phar: расширение не установлено';
        }

        // 3. Разбор tar на PHP
        $err = tinyfm_assets_untar($archive, $dest, $names);
        if ($err === '' && tinyfm_assets_members_exist($dest, $names)) {
            if (is_callable($say)) {
                call_user_func($say, 'распаковано встроенным способом (' . implode('; ', $errors) . ')');
            }
            return '';
        }
        $errors[] = 'php: ' . ($err !== '' ? $err : 'нет нужных файлов после распаковки');
        return implode('; ', $errors);
    }

    function tinyfm_assets_wanted($rel, $names)
    {
        foreach ($names as $n) {
            if ($rel === $n || strpos($rel, $n . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    function tinyfm_assets_put($target, $data)
    {
        if ($data === false || strpos($target, '/../') !== false) {
            return false;
        }
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0777, true);
        }
        return @file_put_contents($target, $data) !== false;
    }

    function tinyfm_assets_members_exist($dest, $names)
    {
        clearstatcache();
        foreach ($names as $n) {
            if (!file_exists($dest . '/' . $n)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Минимальный распаковщик .tgz (ustar + длинные имена GNU «L» и pax «x»):
     * только обычные файлы из нужных путей. Возвращает '' или текст ошибки.
     */
    function tinyfm_assets_untar($archive, $dest, $names)
    {
        if (!function_exists('gzopen')) {
            return 'нет расширения zlib';
        }
        $gz = @gzopen($archive, 'rb');
        if (!$gz) {
            return 'не удалось открыть архив';
        }
        $long = null;
        $ok = '';
        while (!gzeof($gz)) {
            $h = gzread($gz, 512);
            if ($h === false || strlen($h) < 512) {
                break;
            }
            if (trim($h, "\0") === '') {
                break; // конец архива
            }
            $name = rtrim(substr($h, 0, 100), "\0");
            $size = octdec(trim(substr($h, 124, 12), "\0 "));
            $type = substr($h, 156, 1);
            $prefix = rtrim(substr($h, 345, 155), "\0");
            if (substr($h, 257, 5) === 'ustar' && $prefix !== '') {
                $name = $prefix . '/' . $name;
            }
            $data = '';
            $left = $size;
            while ($left > 0) {
                $chunk = gzread($gz, min(65536, $left));
                if ($chunk === false || $chunk === '') {
                    gzclose($gz);
                    return 'архив обрывается';
                }
                $data .= $chunk;
                $left -= strlen($chunk);
            }
            if ($size % 512) {
                gzread($gz, 512 - $size % 512);
            }
            if ($type === 'L') {
                $long = rtrim($data, "\0");
                continue;
            }
            if ($type === 'x' && preg_match('/\d+ path=([^\n]+)\n/', $data, $m)) {
                $long = $m[1];
                continue;
            }
            if ($long !== null) {
                $name = $long;
                $long = null;
            }
            if (($type === '0' || $type === "\0") && tinyfm_assets_wanted($name, $names)
                && !tinyfm_assets_put($dest . '/' . $name, $data)
            ) {
                $ok = 'не удалось записать ' . $name;
                break;
            }
        }
        gzclose($gz);
        return $ok;
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
    function tinyfm_assets_install($module_dir, $tmp_dir, $registry = '', $proxy = '', $proxy_auth = '', $log = null)
    {
        $say = function ($msg) use ($log) {
            if (is_callable($log)) {
                call_user_func($log, $msg);
            }
        };
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

        $say('Реестр: ' . $registry . ($proxy !== '' ? ' (через прокси ' . preg_replace('#//[^@/]*@#', '//', $proxy) . ')' : ''));
        $names = array();
        foreach (tinyfm_assets_packages() as $i => $pkg) {
            $names[] = $pkg['name'];
            $archive = $work . '/pkg' . $i . '.tgz';
            $unpack = $work . '/pkg' . $i;
            $name = $pkg['name'];
            $say($name . ': скачивание…');
            $t0 = microtime(true);
            $err = tinyfm_assets_download($registry . '/' . $pkg['path'], $archive, $proxy, $proxy_auth,
                function ($done, $total) use ($say, $name) {
                    $say($name . ': ' . tinyfm_assets_mb($done) . ($total > 0 ? ' из ' . tinyfm_assets_mb($total) : ''));
                });
            if ($err === '') {
                $say($name . ': скачано ' . tinyfm_assets_mb((int)@filesize($archive)) . ' за '
                    . max(1, (int)round(microtime(true) - $t0)) . ' с');
                $sri = 'sha512-' . base64_encode((string)hash_file('sha512', $archive, true));
                if (!hash_equals($pkg['integrity'], $sri)) {
                    $err = 'контрольная сумма не совпадает';
                } else {
                    $say($name . ': контрольная сумма SHA-512 совпадает');
                }
            }
            if ($err === '') {
                @mkdir($unpack, 0777, true);
                $xerr = tinyfm_assets_extract($archive, $unpack, array_keys($pkg['files']),
                    function ($msg) use ($say, $name) {
                        $say($name . ': ' . $msg);
                    });
                if ($xerr !== '') {
                    $err = 'не удалось распаковать (' . $xerr . ')';
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
            $say($name . ': распаковано');
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
        $say('Установлено в ' . $target);
        return '';
    }

    /**
     * Запуск команды в фоне без ожидания. Не execInBackground() MajorDoMo: на Windows без
     * COM он вызывает system() — запрос ждал бы конца скачивания.
     * Linux/Android: «cmd > /dev/null 2>&1 &»; Windows: «start "" /B cmd >NUL 2>&1».
     */
    function tinyfm_assets_spawn($cmd)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if (!function_exists('popen')) {
                return false;
            }
            $h = @popen('start "" /B ' . $cmd . ' >NUL 2>&1', 'r');
            if ($h === false) {
                return false;
            }
            pclose($h);
            return true;
        }
        if (!function_exists('exec')) {
            return false;
        }
        @exec($cmd . ' > /dev/null 2>&1 &');
        return true;
    }

    /**
     * Папка журнала: <tmp_dir>/tinyfm_assets/ (install.log, state.json, job.json)
     */
    function tinyfm_assets_status_dir($tmp_dir)
    {
        return rtrim($tmp_dir, '/') . '/tinyfm_assets';
    }

    function tinyfm_assets_state_write($dir, $state)
    {
        $tmp = $dir . '/state.json.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state)) !== false) {
            @rename($tmp, $dir . '/state.json');
        }
    }

    /**
     * Выполняет задание (job.json пишет модуль): скачивание с журналом и итогом.
     * Файл задания удаляется сразу после чтения (в нём могут быть логин/пароль прокси).
     */
    function tinyfm_assets_run_job($job_file)
    {
        $job = @json_decode((string)@file_get_contents($job_file), true);
        @unlink($job_file);
        if (!is_array($job) || empty($job['module_dir']) || empty($job['tmp_dir'])) {
            return 'некорректное задание';
        }
        $dir = tinyfm_assets_status_dir($job['tmp_dir']);
        $started = isset($job['started']) ? (int)$job['started'] : time();
        tinyfm_assets_state_write($dir, array('status' => 'running', 'started' => $started, 'pid' => getmypid()));
        $log = function ($msg) use ($dir) {
            @file_put_contents($dir . '/install.log', date('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
        };
        $err = tinyfm_assets_install($job['module_dir'], $job['tmp_dir'],
            isset($job['registry']) ? (string)$job['registry'] : '',
            isset($job['proxy']) ? (string)$job['proxy'] : '',
            isset($job['proxy_auth']) ? (string)$job['proxy_auth'] : '', $log);
        $log($err === '' ? 'Готово.' : 'Ошибка: ' . $err);
        tinyfm_assets_state_write($dir, array('status' => $err === '' ? 'ok' : 'error', 'started' => $started,
            'finished' => time(), 'error' => $err));
        return $err;
    }
}

// Ручной запуск: php assets_install.php <module_dir> <tmp_dir> [registry]
// Фоновый из модуля: php assets_install.php --job <job.json>
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (isset($argv[1], $argv[2]) && $argv[1] === '--job') {
        exit(tinyfm_assets_run_job($argv[2]) === '' ? 0 : 1);
    }
    if (count($argv) < 3) {
        fwrite(STDERR, "usage: php assets_install.php <module_dir> <tmp_dir> [registry]\n");
        exit(2);
    }
    $err = tinyfm_assets_install($argv[1], $argv[2], isset($argv[3]) ? $argv[3] : '', '', '', function ($msg) {
        echo date('H:i:s') . ' ' . $msg . "\n";
    });
    echo ($err === '' ? "OK\n" : $err . "\n");
    exit($err === '' ? 0 : 1);
}
