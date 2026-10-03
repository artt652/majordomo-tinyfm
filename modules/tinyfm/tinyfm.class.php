<?php
/**
 * Tiny File Manager для MajorDoMo
 *
 * Оболочка над Tiny File Manager (https://github.com/prasathmani/tinyfilemanager).
 * В tinyfilemanager.php — только правка скорости fm_get_size(); интеграция — через
 * его внешний config.php (modules/tinyfm/config.php), который пускает только
 * авторизованных в панели управления MajorDoMo.
 *
 * @package MajorDoMo
 * @version 1.0
 */
class tinyfm extends module
{
    // В lib/module.class.php не объявлены — без этого PHP 8.2+ даёт E_DEPRECATED
    var $title;
    var $module_category;

    // Основные библиотеки (bootstrap, jquery, font-awesome, dropzone, datatables,
    // highlight.js) входят в модуль: modules/tinyfm/assets/ — всё работает и офлайн.
    // Дополнительные (редактор ace, все темы подсветки) — modules/tinyfm/assets-extra/,
    // скачиваются из npm кнопкой в настройках (assets_install.php); без них ace грузится с CDN.
    // Зеркало npm можно задать в htdocs/config.php: Define('TINYFM_NPM_REGISTRY', 'https://...');
    const ASSETS_MARKER = 'assets-extra/ace/ace.js';

    function __construct()
    {
        $this->name = "tinyfm";
        $this->title = "Tiny File Manager";
        $this->module_category = "<#LANG_SECTION_APPLICATIONS#>";
        $this->checkInstalled();
    }

    function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->mode)) {
            $p["mode"] = $this->mode;
        }
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $mode;
        global $view_mode;
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = isset($this->view_mode) ? $this->view_mode : '';
        $out['MODE'] = isset($this->mode) ? $this->mode : '';
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * Панель управления: настройки + iframe с файловым менеджером
     */
    function admin(&$out)
    {
        global $session;

        // Повторяем проверку panel.class.php: admin() должен выдавать доступ
        // только авторизованному администратору (или если панель открыта,
        // т.к. в системе нет ни одного пользователя с IS_ADMIN=1).
        $authorized = !empty($session->data['AUTHORIZED']);
        $open_panel = 0;
        if (!$authorized) {
            $open_panel = $this->noAdminUsers() ? 1 : 0;
        }
        if (!$authorized && !$open_panel) {
            $out['ACCESS_DENIED'] = 1;
            return;
        }

        $this->getConfig();
        if (!is_array($this->config)) {
            $this->config = array();
        }

        if ($this->view_mode == 'assets' && $this->mode == 'download') {
            $this->installAssets();
            // фоновое скачивание идёт — страница покажет журнал; иначе (уже закончилось
            // в этом запросе или не запустилось) — сразу итог
            $this->redirect($this->assetsStatus()['status'] === 'running'
                ? "?view_mode=settings" : "?view_mode=settings&assets_done=1");
        }

        if ($this->view_mode == 'settings' && $this->mode == 'update') {
            $root = gr('root_path');
            $root = is_string($root) ? rtrim(trim($root), '/') : '';
            if ($root !== '' && !is_dir($root)) {
                $out['ERR_ROOT'] = 1;
                $out['ROOT_PATH'] = htmlspecialchars($root);
            } else {
                $this->config['ROOT_PATH'] = $root;
                $this->config['READONLY'] = gr('readonly', 'int') ? 1 : 0;
                $this->config['ADMIN_ONLY'] = gr('admin_only', 'int') ? 1 : 0;
                $this->config['THEME_SYNC'] = gr('theme_sync', 'int') ? 1 : 0;
                $this->config['LANG_SYNC'] = gr('lang_sync', 'int') ? 1 : 0;
                // Темы: пусто = автоматически; иначе только из известных списков
                $ace = gr('ace_theme');
                $this->config['ACE_THEME'] = (is_string($ace) && isset($this->aceThemes()[$ace])) ? $ace : '';
                $hljs = gr('hljs_theme');
                $this->config['HLJS_THEME'] = (is_string($hljs) && in_array($hljs, $this->hljsThemes(), true)) ? $hljs : '';
                $this->saveConfig();
                $this->redirect("?ok=1");
            }
        }

        $root_cfg = isset($this->config['ROOT_PATH']) ? (string)$this->config['ROOT_PATH'] : '';

        if (!isset($out['ROOT_PATH'])) {
            $out['ROOT_PATH'] = htmlspecialchars($root_cfg);
        }
        $out['ROOT_DEFAULT'] = htmlspecialchars(rtrim(ROOT, '/'));
        $out['READONLY'] = !empty($this->config['READONLY']) ? 1 : 0;
        $out['ADMIN_ONLY'] = $this->isAdminOnly() ? 1 : 0;
        $out['THEME_SYNC'] = $this->isThemeSync() ? 1 : 0;
        $out['LANG_SYNC'] = $this->isLangSync() ? 1 : 0;

        // Списки тем для настроек
        $ace_cur = isset($this->config['ACE_THEME']) ? (string)$this->config['ACE_THEME'] : '';
        $out['ACE_LIGHT'] = array();
        $out['ACE_DARK'] = array();
        foreach ($this->aceThemes() as $name => $item) {
            $out[$item[1] ? 'ACE_DARK' : 'ACE_LIGHT'][] = array(
                'VALUE' => $name, 'TITLE' => $item[0], 'SELECTED' => $name === $ace_cur ? 1 : 0,
            );
        }
        $hljs_cur = isset($this->config['HLJS_THEME']) ? (string)$this->config['HLJS_THEME'] : '';
        $out['HLJS_THEMES'] = array();
        foreach ($this->hljsThemes() as $name) {
            $out['HLJS_THEMES'][] = array('VALUE' => $name, 'TITLE' => $name, 'SELECTED' => $name === $hljs_cur ? 1 : 0);
        }
        if (gr('ok')) {
            $out['OK'] = 1;
        }
        $out['ASSETS_LOCAL'] = $this->assetsInstalled() ? 1 : 0;
        // Журнал скачивания библиотек: состояние и последние строки
        $st = $this->assetsStatus();
        $out['ASSETS_STATE'] = $st['status'];
        $out['ASSETS_LOG'] = htmlspecialchars($st['log']);
        $out['ASSETS_TIME'] = $st['time'];
        if (gr('assets_done')) {
            if ($st['status'] === 'ok') {
                $out['ASSETS_OK'] = 1;
            } elseif ($st['status'] === 'error') {
                $out['ASSETS_ERR'] = htmlspecialchars($st['error']);
            }
        }

        // Пропуск для config.php файлового менеджера. Хранится в сессии MajorDoMo
        // и снимается вместе с ней (выход из панели удаляет AUTHORIZED).
        $this->issuePass('admin', '', $open_panel, 'panel');

        $out['FM_URL'] = ROOTHTML . 'modules/' . $this->name . '/tinyfilemanager.php';
    }

    /**
     * Фронтенд: «Приложения» → /apps/tinyfm.html
     * Доступ: администратор всегда; остальные пользователи — только если
     * в настройках снята галочка «Только для администраторов».
     */
    function usual(&$out)
    {
        global $session;

        $this->getConfig();
        if (!is_array($this->config)) {
            $this->config = array();
        }
        $admin_only = $this->isAdminOnly();

        $username = (isset($session->data['SITE_USERNAME']) && is_string($session->data['SITE_USERNAME']))
            ? $session->data['SITE_USERNAME'] : '';
        $user = array();
        if ($username !== '') {
            $user = SQLSelectOne("SELECT ID, USERNAME, IS_ADMIN FROM users WHERE USERNAME='" . DBSafe($username) . "'");
        }

        if (!empty($session->data['AUTHORIZED'])) {
            // Вошёл в панель — те же права, что из панели
            $this->issuePass('admin', '', 0, 'front');
        } elseif ($this->noAdminUsers()) {
            // Панель открыта всем (нет ни одного администратора) — как panel.class.php
            $this->issuePass('admin', '', 1, 'front');
        } elseif (!empty($user['ID']) && (!empty($user['IS_ADMIN']) || !$admin_only)) {
            // Права пользователя config.php перепроверяет по БД на каждом запросе
            $this->issuePass('user', $user['USERNAME'], 0, 'front');
        } else {
            $out['ACCESS_DENIED'] = 1;
            $out['ADMIN_ONLY'] = $admin_only ? 1 : 0;
            if (isset($session->data['TINYFM'])) {
                unset($session->data['TINYFM']);
            }
            return;
        }

        $out['READONLY'] = !empty($this->config['READONLY']) ? 1 : 0;
        $out['FM_URL'] = ROOTHTML . 'modules/' . $this->name . '/tinyfilemanager.php';
    }

    /**
     * «Только для администраторов» — включено, пока явно не выключено.
     */
    function isAdminOnly()
    {
        return !is_array($this->config) || !isset($this->config['ADMIN_ONLY']) || !empty($this->config['ADMIN_ONLY']);
    }

    /**
     * «Тема как в MajorDoMo» — включено, пока явно не выключено.
     */
    function isThemeSync()
    {
        return !is_array($this->config) || !isset($this->config['THEME_SYNC']) || !empty($this->config['THEME_SYNC']);
    }

    /**
     * «Язык как в MajorDoMo» — включено, пока явно не выключено.
     */
    function isLangSync()
    {
        return !is_array($this->config) || !isset($this->config['LANG_SYNC']) || !empty($this->config['LANG_SYNC']);
    }

    /**
     * Темы редактора ace — тот же список, что в меню редактора Tiny File Manager 2.6.
     * name => array(название, тёмная)
     */
    function aceThemes()
    {
        $light = array(
            'chrome' => 'Chrome', 'clouds' => 'Clouds', 'crimson_editor' => 'Crimson Editor', 'dawn' => 'Dawn',
            'dreamweaver' => 'Dreamweaver', 'eclipse' => 'Eclipse', 'github' => 'GitHub', 'iplastic' => 'IPlastic',
            'solarized_light' => 'Solarized Light', 'textmate' => 'TextMate', 'tomorrow' => 'Tomorrow',
            'xcode' => 'XCode', 'kuroir' => 'Kuroir', 'katzenmilch' => 'KatzenMilch', 'sqlserver' => 'SQL Server',
        );
        $dark = array(
            'ambiance' => 'Ambiance', 'chaos' => 'Chaos', 'clouds_midnight' => 'Clouds Midnight', 'dracula' => 'Dracula',
            'cobalt' => 'Cobalt', 'gruvbox' => 'Gruvbox', 'gob' => 'Green on Black', 'idle_fingers' => 'idle Fingers',
            'kr_theme' => 'krTheme', 'merbivore' => 'Merbivore', 'merbivore_soft' => 'Merbivore Soft',
            'mono_industrial' => 'Mono Industrial', 'monokai' => 'Monokai', 'pastel_on_dark' => 'Pastel on dark',
            'solarized_dark' => 'Solarized Dark', 'terminal' => 'Terminal', 'tomorrow_night' => 'Tomorrow Night',
            'tomorrow_night_blue' => 'Tomorrow Night Blue', 'tomorrow_night_bright' => 'Tomorrow Night Bright',
            'tomorrow_night_eighties' => 'Tomorrow Night 80s', 'twilight' => 'Twilight', 'vibrant_ink' => 'Vibrant Ink',
        );
        $res = array();
        foreach ($light as $k => $v) {
            $res[$k] = array($v, 0);
        }
        foreach ($dark as $k => $v) {
            $res[$k] = array($v, 1);
        }
        return $res;
    }

    /**
     * Темы подсветки highlight.js, которые есть на диске: встроенные (assets/) и
     * дополнительные (assets-extra/, в т.ч. base16/…). Имя — без «.min.css».
     */
    function hljsThemes()
    {
        $res = array();
        foreach (array('assets', 'assets-extra') as $dir) {
            $base = DIR_MODULES . $this->name . '/' . $dir . '/highlight/styles/';
            foreach (array('', 'base16/') as $sub) {
                foreach ((array)glob($base . $sub . '*.min.css') as $file) {
                    if (is_string($file) && $file !== '') {
                        $res[] = $sub . basename($file, '.min.css');
                    }
                }
            }
        }
        $res = array_values(array_unique($res));
        sort($res);
        return $res;
    }

    /**
     * Нет ни одного пользователя с IS_ADMIN=1 — панель MajorDoMo открыта всем.
     */
    function noAdminUsers()
    {
        $tmp = SQLSelectOne("SELECT ID FROM users WHERE IS_ADMIN=1");
        return empty($tmp['ID']);
    }

    /**
     * Пропуск в сессии MajorDoMo для modules/tinyfm/config.php
     * MODE 'admin' — проверяется по AUTHORIZED (или OPEN_PANEL);
     * MODE 'user'  — по SITE_USERNAME и БД (users, настройка ADMIN_ONLY).
     * THEME_SRC 'panel' — тема панели: SETTINGS_THEME + cookie "theme" (common_header.html);
     * THEME_SRC 'front' — тема фронтенда: THEME (application.class.php) или SETTINGS_THEME.
     */
    function issuePass($mode, $username, $open_panel, $theme_src = 'panel')
    {
        global $session;
        $site_theme = defined('SETTINGS_THEME') ? (string)SETTINGS_THEME : '';
        if ($theme_src === 'front' && defined('THEME')) {
            $site_theme = (string)THEME;
        }
        $root_cfg = isset($this->config['ROOT_PATH']) ? (string)$this->config['ROOT_PATH'] : '';
        $session->data['TINYFM'] = array(
            'MODE' => $mode,
            'USER' => (string)$username,
            'ROOT' => ($root_cfg !== '' && is_dir($root_cfg)) ? $root_cfg : rtrim(ROOT, '/'),
            'READONLY' => !empty($this->config['READONLY']) ? 1 : 0,
            'TZ' => date_default_timezone_get(),
            'OPEN_PANEL' => $open_panel ? 1 : 0,
            'THEME_SYNC' => $this->isThemeSync() ? 1 : 0,
            'THEME_SRC' => $theme_src === 'front' ? 'front' : 'panel',
            'THEME' => $site_theme,
            'LANG_SYNC' => $this->isLangSync() ? 1 : 0,
            'LANG' => defined('SETTINGS_SITE_LANGUAGE') ? (string)SETTINGS_SITE_LANGUAGE : '',
            'ACE_THEME' => isset($this->config['ACE_THEME']) ? (string)$this->config['ACE_THEME'] : '',
            'HLJS_THEME' => isset($this->config['HLJS_THEME']) ? (string)$this->config['HLJS_THEME'] : '',
            'TS' => time(),
        );
    }

    function assetsInstalled()
    {
        return is_file(DIR_MODULES . $this->name . '/' . self::ASSETS_MARKER);
    }

    /**
     * Состояние скачивания: status ('' | running | ok | error), log (последние строки),
     * time (когда), error. «running» без записей в журнале дольше 6 минут — прервано
     * (curl ждёт не дольше 5 минут на пакет).
     */
    function assetsStatus()
    {
        require_once DIR_MODULES . $this->name . '/assets_install.php';
        $dir = tinyfm_assets_status_dir(ROOT . 'cms/cached');
        clearstatcache();
        $res = array('status' => '', 'log' => '', 'time' => '', 'error' => '');
        $state = @json_decode((string)@file_get_contents($dir . '/state.json'), true);
        if (!is_array($state) || empty($state['status'])) {
            return $res;
        }
        $res['status'] = in_array($state['status'], array('running', 'ok', 'error'), true) ? $state['status'] : '';
        $res['error'] = isset($state['error']) ? (string)$state['error'] : '';
        $log_file = $dir . '/install.log';
        $lines = is_file($log_file) ? (array)@file($log_file, FILE_IGNORE_NEW_LINES) : array();
        $res['log'] = implode("\n", array_slice($lines, -60));
        $ts = !empty($state['finished']) ? (int)$state['finished'] : (isset($state['started']) ? (int)$state['started'] : 0);
        $res['time'] = $ts ? date('d.m.Y H:i', $ts) : '';
        if ($res['status'] === 'running') {
            $last = max(is_file($log_file) ? (int)filemtime($log_file) : 0, isset($state['started']) ? (int)$state['started'] : 0);
            if (time() - $last > 360) {
                $res['status'] = 'error';
                $res['error'] = 'прервано: журнал не обновлялся больше 6 минут';
                $res['log'] .= ($res['log'] !== '' ? "\n" : '') . 'Прервано.';
            }
        }
        return $res;
    }

    /**
     * Кнопка «Скачать»: скачивает дополнительные библиотеки из npm в modules/tinyfm/assets-extra/.
     * Запускается фоновым процессом PHP (PATH_TO_PHP), чтобы страница не ждала минутами,
     * а журнал был виден сразу. Если фоновый запуск не сработал за 10 секунд (нет exec/popen,
     * неверный PATH_TO_PHP) — скачивает в этом же запросе, журнал будет виден после.
     *
     * Сессия MajorDoMo (prj) закрывается до запуска: PHP держит файл сессии заблокированным
     * весь запрос, и пока он идёт, ВСЕ остальные страницы панели этого браузера ждут.
     */
    function installAssets()
    {
        global $session;
        require_once DIR_MODULES . $this->name . '/assets_install.php';
        $dir = tinyfm_assets_status_dir(ROOT . 'cms/cached');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if ($this->assetsStatus()['status'] === 'running') {
            return; // уже идёт
        }
        $job_file = $dir . '/job.json';
        $job = array(
            'module_dir' => rtrim(DIR_MODULES, '/') . '/' . $this->name,
            'tmp_dir' => ROOT . 'cms/cached',
            'registry' => defined('TINYFM_NPM_REGISTRY') ? (string)TINYFM_NPM_REGISTRY : '',
            'proxy' => (defined('USE_PROXY') && USE_PROXY != '') ? (string)USE_PROXY : '',
            'proxy_auth' => (defined('USE_PROXY_AUTH') && USE_PROXY_AUTH != '') ? (string)USE_PROXY_AUTH : '',
            'started' => time(),
        );
        @file_put_contents($dir . '/install.log', date('H:i:s') . " Запуск скачивания…\n");
        tinyfm_assets_state_write($dir, array('status' => 'running', 'started' => time()));
        if (@file_put_contents($job_file, json_encode($job)) === false) {
            tinyfm_assets_state_write($dir, array('status' => 'error', 'started' => time(), 'finished' => time(),
                'error' => 'нет прав на запись в ' . $dir));
            return;
        }
        @chmod($job_file, 0600);

        // Дальше в этом запросе сессия не меняется: сохраняем и снимаем блокировку
        if (is_object($session) && method_exists($session, 'save')) {
            $session->save();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $php = defined('PATH_TO_PHP') && PATH_TO_PHP != '' ? (string)PATH_TO_PHP : 'php';
        if (strpos($php, '"') === false && strpos($php, "'") === false && is_file($php)) {
            $php = escapeshellarg($php); // путь с пробелами (Windows: C:\Program Files\...)
        }
        if (tinyfm_assets_spawn($php . ' ' . escapeshellarg(DIR_MODULES . $this->name . '/assets_install.php')
            . ' --job ' . escapeshellarg($job_file))
        ) {
            for ($i = 0; $i < 100; $i++) {
                clearstatcache(); // иначе is_file() отдаёт закэшированный результат
                if (!is_file($job_file)) {
                    break;
                }
                usleep(100000); // фоновый процесс забирает задание
            }
        }
        clearstatcache();
        if (is_file($job_file)) {
            @file_put_contents($dir . '/install.log', date('H:i:s')
                . " Фоновый запуск не удался — скачивание в этом запросе (страница откроется по окончании)\n", FILE_APPEND);
            tinyfm_assets_run_job($job_file);
        }
    }

    function install($data = '')
    {
        parent::install();
    }

    function uninstall()
    {
        global $session;
        if (isset($session->data['TINYFM'])) {
            unset($session->data['TINYFM']);
        }
        parent::uninstall();
    }
}
