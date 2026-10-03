<?php
//Default Configuration
$CONFIG = '{"lang":"ru","error_reporting":false,"show_hidden":true,"hide_Cols":false,"theme":"dark"}';
/*
 * ВНИМАНИЕ: первые 3 строки этого файла перезаписывает сам Tiny File Manager
 * (FM_Config::save() при сохранении настроек на его странице «Настройки»).
 * Не добавляйте ничего выше этого комментария и не меняйте формат строки $CONFIG.
 *
 * Внешний конфиг Tiny File Manager для модуля MajorDoMo "tinyfm".
 * Подключается из tinyfilemanager.php через штатный механизм:
 *     $config_file = __DIR__ . '/config.php'; @include($config_file);
 * Сам tinyfilemanager.php не модифицируется.
 *
 * Доступ: только из сессии MajorDoMo, в которой открывался модуль в панели
 * управления (флаг TINYFM ставит tinyfm::admin()). Собственная авторизация
 * Tiny File Manager (admin/admin@123) отключена и недоступна.
 */

// Прямой вызов этого файла по HTTP — ничего не делаем.
if (!defined('APP_TITLE')) {
    http_response_code(403);
    exit;
}

$tinyfm_session = null;
$tinyfm_prj_lang = null; // $_SESSION['lang'] — переключение языка через ?lang= (load_settings.php)

// --- Чтение сессии MajorDoMo (lib/session.class.php: имя "prj", данные в $_SESSION['DATA']) ---
if (isset($_COOKIE['prj']) && is_string($_COOKIE['prj'])
    && preg_match('/^[a-zA-Z0-9,-]{1,256}$/', $_COOKIE['prj'])
    && session_status() === PHP_SESSION_NONE
) {
    session_name('prj');
    session_id($_COOKIE['prj']);
    if (@session_start()) {
        $tinyfm_expired = isset($_SESSION['expire']) && $_SESSION['expire'] < time();
        if (!$tinyfm_expired && isset($_SESSION['DATA']) && is_string($_SESSION['DATA'])) {
            $tinyfm_data = @unserialize($_SESSION['DATA'], array('allowed_classes' => false));
            if (is_array($tinyfm_data)) {
                $tinyfm_session = $tinyfm_data;
                if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
                    $tinyfm_prj_lang = $_SESSION['lang'];
                }
                // Как в session::__construct(): продлеваем сессию на 1 час
                $_SESSION['expire'] = time() + 60 * 60;
            }
        }
        session_write_close();
    }
    // Возвращаем tinyfilemanager.php ЕГО сессию: после session_write_close() PHP
    // помнит ID сессии "prj", а session_id('') не сбрасывает его, а задаёт пустой ID
    // (session_start() тогда создаёт новую сессию и не читает cookie). Поэтому ID
    // сессии TFM выставляем явно — из его cookie или новый.
    $_SESSION = array();
    $tinyfm_fm_name = defined('FM_SESSION_ID') ? FM_SESSION_ID : 'filemanager';
    if (isset($_COOKIE[$tinyfm_fm_name]) && is_string($_COOKIE[$tinyfm_fm_name])
        && preg_match('/^[a-zA-Z0-9,-]{1,256}$/', $_COOKIE[$tinyfm_fm_name])
    ) {
        session_id($_COOKIE[$tinyfm_fm_name]);
    } else {
        session_id(session_create_id());
    }
    unset($tinyfm_expired, $tinyfm_data, $tinyfm_fm_name);
}

// --- Проверка прав ---
// MODE 'admin' (или пропуск без MODE из прежней версии) — та же логика, что в panel.class.php.
// MODE 'user'  — вход с фронтенда (/apps/tinyfm.html): пользователь из пропуска должен
// совпадать с текущим SITE_USERNAME, а БД на каждом запросе должна подтверждать доступ:
// пользователь существует и (IS_ADMIN=1 или «Только для администраторов» выключено).
// Так включение галочки в настройках отзывает доступ сразу, а не по истечении сессии.
if (!function_exists('tinyfm_db_user_allowed')) {
    function tinyfm_db_user_allowed($username)
    {
        $mjd_config = realpath(__DIR__ . '/../../config.php');
        if (!$mjd_config || !is_readable($mjd_config) || !function_exists('mysqli_connect')) {
            return false;
        }
        try {
            if (!defined('DB_HOST')) {
                include_once $mjd_config; // константы DB_* MajorDoMo
            }
            if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
                return false;
            }
            $db = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if (!$db) {
                return false;
            }
            $allowed = false;
            $stmt = mysqli_prepare($db, "SELECT IS_ADMIN FROM users WHERE USERNAME=? LIMIT 1");
            if ($stmt) {
                // bind_result/fetch — работает и без mysqlnd (в отличие от get_result)
                $is_admin = null;
                mysqli_stmt_bind_param($stmt, 's', $username);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $is_admin);
                $found = mysqli_stmt_fetch($stmt) === true;
                mysqli_stmt_close($stmt);
                if ($found) {
                    if (!empty($is_admin)) {
                        $allowed = true;
                    } else {
                        $admin_only = true; // по умолчанию — только администраторы
                        $res = mysqli_query($db, "SELECT DATA FROM project_modules WHERE NAME='tinyfm' LIMIT 1");
                        $row = $res ? mysqli_fetch_assoc($res) : null;
                        if (is_array($row) && is_string($row['DATA']) && $row['DATA'] !== '') {
                            $cfg = @unserialize($row['DATA'], array('allowed_classes' => false));
                            if (is_array($cfg) && isset($cfg['ADMIN_ONLY']) && empty($cfg['ADMIN_ONLY'])) {
                                $admin_only = false;
                            }
                        }
                        $allowed = !$admin_only;
                    }
                }
            }
            mysqli_close($db);
            return $allowed;
        } catch (\Throwable $e) {
            return false; // при любой ошибке — доступа нет
        }
    }
}

$tinyfm_flag = (is_array($tinyfm_session) && isset($tinyfm_session['TINYFM']) && is_array($tinyfm_session['TINYFM']))
    ? $tinyfm_session['TINYFM'] : null;
$tinyfm_allowed = false;
if ($tinyfm_flag !== null) {
    $tinyfm_mode = isset($tinyfm_flag['MODE']) ? $tinyfm_flag['MODE'] : 'admin';
    if ($tinyfm_mode === 'admin') {
        $tinyfm_allowed = !empty($tinyfm_session['AUTHORIZED']) || !empty($tinyfm_flag['OPEN_PANEL']);
    } elseif ($tinyfm_mode === 'user') {
        $tinyfm_user = isset($tinyfm_flag['USER']) && is_string($tinyfm_flag['USER']) ? $tinyfm_flag['USER'] : '';
        $tinyfm_allowed = $tinyfm_user !== ''
            && isset($tinyfm_session['SITE_USERNAME']) && $tinyfm_session['SITE_USERNAME'] === $tinyfm_user
            && tinyfm_db_user_allowed($tinyfm_user);
    }
    unset($tinyfm_mode, $tinyfm_user);
}

if (!$tinyfm_allowed) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body style="font-family:sans-serif;padding:2em">';
    echo '<h3>Доступ запрещён</h3>';
    echo '<p>Откройте файловый менеджер из MajorDoMo:</p>';
    echo '<ul style="padding-left:1.2em;line-height:1.8">';
    echo '<li><a href="/admin.php?md=panel&action=tinyfm" target="_top">Панель управления → Tiny File Manager</a></li>';
    echo '<li><a href="/apps/tinyfm.html" target="_top">Приложения → Tiny File Manager</a></li>';
    echo '</ul>';
    echo '</body></html>';
    exit;
}

// --- Доступ разрешён: настройки Tiny File Manager ---
$use_auth = false;           // авторизацию выполнила панель MajorDoMo
$auth_users = array();
$readonly_users = array();
$global_readonly = !empty($tinyfm_flag['READONLY']);

// Корневая папка (по умолчанию — корень MajorDoMo, т.е. htdocs)
$tinyfm_root = '';
if (!empty($tinyfm_flag['ROOT']) && is_string($tinyfm_flag['ROOT'])) {
    $tinyfm_root = realpath($tinyfm_flag['ROOT']);
}
if (!$tinyfm_root || !is_dir($tinyfm_root)) {
    $tinyfm_root = realpath(__DIR__ . '/../..');
}
$root_path = $tinyfm_root;

// URL для прямых ссылок на файлы: только если папка внутри DOCUMENT_ROOT
$root_url = '';
$tinyfm_docroot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($tinyfm_docroot && strpos($tinyfm_root . '/', rtrim($tinyfm_docroot, '/') . '/') === 0) {
    $root_url = trim(substr($tinyfm_root, strlen(rtrim($tinyfm_docroot, '/'))), '/');
}

// Адреса без хоста. Upstream строит абсолютные URL из HTTP_HOST:
//   FM_SELF_URL = http(s)://HTTP_HOST/PHP_SELF,  FM_ROOT_URL = http(s)://HTTP_HOST/<root_url>
// За обратным прокси HTTP_HOST — внутренний адрес (127.0.0.1:8080), и редиректы TFM
// уводят браузер туда (404). TFM определяет эти константы через «defined() ||»,
// поэтому задаём их здесь от корня сайта — браузер подставит адрес, по которому зашёл.
// Используются в редиректах (Location), форме загрузки, ссылках на файлы и превью.
if (!defined('FM_SELF_URL') && isset($_SERVER['PHP_SELF'])) {
    define('FM_SELF_URL', (string)$_SERVER['PHP_SELF']);
}
if (!defined('FM_ROOT_URL')) {
    define('FM_ROOT_URL', $root_url !== '' ? '/' . $root_url : '');
}

// Часовой пояс — как в MajorDoMo (SITE_TIMEZONE)
if (!empty($tinyfm_flag['TZ']) && is_string($tinyfm_flag['TZ'])
    && in_array($tinyfm_flag['TZ'], timezone_identifiers_list(), true)
) {
    $default_timezone = $tinyfm_flag['TZ'];
}
$datetime_format = 'd.m.Y H:i';

// Тема как в MajorDoMo. FM_Config читает глобальный $CONFIG уже ПОСЛЕ подключения
// этого файла, поэтому здесь достаточно подменить в нём "theme".
// Панель (templates/common_header.html + panel.class.php): тёмная, только если
//   SETTINGS_THEME == 'dark' и cookie "theme" установлена и не равна 'light'.
// Фронтенд (/apps/tinyfm.html): THEME из application.class.php.
if (!empty($tinyfm_flag['THEME_SYNC'])) {
    $tinyfm_site_theme = isset($tinyfm_flag['THEME']) && is_string($tinyfm_flag['THEME']) ? $tinyfm_flag['THEME'] : '';
    if (isset($tinyfm_flag['THEME_SRC']) && $tinyfm_flag['THEME_SRC'] === 'front') {
        $tinyfm_theme = ($tinyfm_site_theme === 'dark') ? 'dark' : 'light';
    } else {
        $tinyfm_cookie = (isset($_COOKIE['theme']) && is_string($_COOKIE['theme'])) ? $_COOKIE['theme'] : 'light';
        $tinyfm_theme = ($tinyfm_site_theme === 'dark' && $tinyfm_cookie !== 'light') ? 'dark' : 'light';
    }
    $tinyfm_cfg = json_decode($CONFIG, true);
    if (is_array($tinyfm_cfg)) {
        $tinyfm_cfg['theme'] = $tinyfm_theme;
        $CONFIG = json_encode($tinyfm_cfg);
    }
    // Редактор (ace) и подсветка при просмотре (highlight.js) — под ту же тему.
    // Светлые — значения TFM по умолчанию (textmate / vs), тёмные — их пары.
    // ACE_THEME и FM_HIGHLIGHTJS_STYLE TFM определяет из этих переменных ПОСЛЕ include.
    if ($tinyfm_theme === 'dark') {
        $ace_theme = 'tomorrow_night';
        $highlightjs_style = 'vs2015';
    } else {
        $ace_theme = 'textmate';
        $highlightjs_style = 'vs';
    }
    unset($tinyfm_site_theme, $tinyfm_theme, $tinyfm_cookie, $tinyfm_cfg);
}

// Темы, выбранные в настройках модуля, — поверх автоматических (пусто = автоматически:
// при «Тема как в MajorDoMo» — светлая/тёмная пара выше, иначе — по умолчанию TFM).
if (!empty($tinyfm_flag['ACE_THEME']) && is_string($tinyfm_flag['ACE_THEME'])
    && preg_match('/^[a-z0-9_]+$/', $tinyfm_flag['ACE_THEME'])
) {
    $ace_theme = $tinyfm_flag['ACE_THEME'];
}
if (!empty($tinyfm_flag['HLJS_THEME']) && is_string($tinyfm_flag['HLJS_THEME'])
    && preg_match('#^[a-z0-9_.-]+(/[a-z0-9_.-]+)?$#i', $tinyfm_flag['HLJS_THEME'])
    && strpos($tinyfm_flag['HLJS_THEME'], '..') === false
) {
    $highlightjs_style = $tinyfm_flag['HLJS_THEME'];
}

// Язык как в MajorDoMo: SETTINGS_SITE_LANGUAGE или выбранный через ?lang= (хранится
// в $_SESSION['lang'] сессии prj — читаем на каждом запросе). Коды MajorDoMo
// (languages/*.php) и TFM (translation.json) в основном совпадают; расходятся:
// cs→cz, el→gr, zh→zh-CN (в languages/zh.php упрощённые иероглифы). Если перевода
// в translation.json нет — остаётся язык из настроек самого TFM.
if (!empty($tinyfm_flag['LANG_SYNC'])) {
    $tinyfm_lang = $tinyfm_prj_lang !== null ? $tinyfm_prj_lang
        : (isset($tinyfm_flag['LANG']) && is_string($tinyfm_flag['LANG']) ? $tinyfm_flag['LANG'] : '');
    $tinyfm_lang_map = array('cs' => 'cz', 'el' => 'gr', 'zh' => 'zh-CN');
    if (isset($tinyfm_lang_map[$tinyfm_lang])) {
        $tinyfm_lang = $tinyfm_lang_map[$tinyfm_lang];
    }
    $tinyfm_lang_ok = ($tinyfm_lang === 'en'); // английский встроен в tinyfilemanager.php
    if (!$tinyfm_lang_ok && preg_match('/^[a-zA-Z_-]{2,10}$/', $tinyfm_lang)) {
        $tinyfm_tr = @json_decode((string)@file_get_contents(__DIR__ . '/translation.json'), true);
        if (is_array($tinyfm_tr) && isset($tinyfm_tr['language']) && is_array($tinyfm_tr['language'])) {
            foreach ($tinyfm_tr['language'] as $tinyfm_item) {
                if (is_array($tinyfm_item) && isset($tinyfm_item['code']) && $tinyfm_item['code'] === $tinyfm_lang) {
                    $tinyfm_lang_ok = true;
                    break;
                }
            }
        }
    }
    if ($tinyfm_lang_ok) {
        $tinyfm_cfg = json_decode($CONFIG, true);
        if (is_array($tinyfm_cfg)) {
            $tinyfm_cfg['lang'] = $tinyfm_lang;
            $CONFIG = json_encode($tinyfm_cfg);
        }
    }
    unset($tinyfm_lang, $tinyfm_lang_map, $tinyfm_lang_ok, $tinyfm_tr, $tinyfm_item, $tinyfm_cfg);
}
unset($tinyfm_prj_lang);

// --- Быстрые переводы ---
// lng() TFM на КАЖДЫЙ вызов заново разбирает весь translation.json (~130 КБ, 34 языка):
// fm_get_translations() кэширует только чтение файла, но не json_decode. В списке файлов
// lng() вызывается по несколько раз на строку — на папке в 80 элементов это сотни
// разборов (на ПК ~0,3 с, на телефоне — секунды). Файл TFM читает по относительному
// пути 'translation.json' (от текущей папки), поэтому готовим компактную копию: все
// языки в списке (коды и названия — для выбора в настройках TFM), но перевод — только
// текущего языка, и переходим в её папку. Upstream-файлы не меняются; если копию
// записать не удалось — всё работает как раньше.
if (!function_exists('tinyfm_compact_translations')) {
    function tinyfm_compact_translations($src, $cache_root, $lang)
    {
        if (!preg_match('/^[a-zA-Z_-]{2,10}$/', $lang) || !is_readable($src)) {
            return false;
        }
        $dir = $cache_root . '/' . $lang;
        $dst = $dir . '/translation.json';
        if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
            return $dir;
        }
        $data = json_decode((string)file_get_contents($src), true);
        if (!is_array($data) || !isset($data['language']) || !is_array($data['language'])) {
            return false;
        }
        foreach ($data['language'] as $i => $item) {
            if (!is_array($item) || !isset($item['code']) || $item['code'] !== $lang) {
                $data['language'][$i]['translation'] = new stdClass();
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return false;
        }
        $tmp = $dst . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $dst)) {
            @unlink($tmp);
            return false;
        }
        return $dir;
    }
}
$tinyfm_cfg_lang = json_decode($CONFIG, true);
$tinyfm_cfg_lang = (is_array($tinyfm_cfg_lang) && isset($tinyfm_cfg_lang['lang']) && is_string($tinyfm_cfg_lang['lang']))
    ? $tinyfm_cfg_lang['lang'] : 'en';
$tinyfm_i18n_dir = tinyfm_compact_translations(__DIR__ . '/translation.json',
    dirname(dirname(__DIR__)) . '/cms/cached/tinyfm_i18n', $tinyfm_cfg_lang);
if ($tinyfm_i18n_dir !== false) {
    @chdir($tinyfm_i18n_dir);
}
unset($tinyfm_cfg_lang, $tinyfm_i18n_dir);

// Иконка модуля (img/modules/tinyfm.png — её же показывает панель MajorDoMo) как favicon
if ($favicon_path === '' && is_file(__DIR__ . '/../../img/modules/tinyfm.png') && isset($_SERVER['PHP_SELF'])) {
    $favicon_path = preg_replace('#/modules/tinyfm/[^/]*$#', '', (string)$_SERVER['PHP_SELF']) . '/img/modules/tinyfm.png';
}

// Подсветка кода для типичных файлов MajorDoMo
$ext_language['html'] = 'html';
$ext_language['tpl'] = 'html';
$ext_language['conf'] = 'ini';
$ext_language['cnf'] = 'ini';
$ext_language['sh'] = 'sh';

unset($tinyfm_session, $tinyfm_flag, $tinyfm_allowed, $tinyfm_root, $tinyfm_docroot);

// --- Локальные библиотеки вместо CDN ---
// Массив $external (CDN-ссылки) объявляется в tinyfilemanager.php ПОСЛЕ подключения
// этого файла, поэтому переопределить его отсюда нельзя. Вместо правки upstream-файла
// подменяем готовые теги на выходе — для каждой библиотеки отдельно и только если её
// файл есть на диске (иначе остаётся CDN):
//   assets/       — основные, входят в модуль: bootstrap, jquery, font-awesome,
//                   dropzone, datatables, highlight.js (+ темы vs / vs2015);
//   assets-extra/ — дополнительные, кнопка «Скачать» в настройках: редактор ace
//                   и все темы подсветки highlight.js.
// Версии совпадают с теми, что подключает TFM 2.6 (bootstrap/jquery сверены по SRI-хэшам).
if (!function_exists('tinyfm_local_assets')) {
    function tinyfm_local_assets($buffer)
    {
        global $external;
        if (!is_array($external) || $buffer === '' || strpos($buffer, '<') === false) {
            return $buffer;
        }
        $self = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
        $url = rtrim(str_replace('\\', '/', dirname($self)), '/') . '/';
        // $highlightjs_style TFM снимает unset() (строка ~481), берём константу
        $style = defined('FM_HIGHLIGHTJS_STYLE') ? (string)FM_HIGHLIGHTJS_STYLE : 'vs';
        if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $style) || strpos($style, '..') !== false) {
            $style = 'vs';
        }
        $style_file = 'assets-extra/highlight/styles/' . $style . '.min.css';
        if (!is_file(__DIR__ . '/' . $style_file)) {
            $style_file = 'assets/highlight/styles/' . $style . '.min.css';
        }
        // ключ $external => array(файл, шаблон тега)
        $local = array(
            'css-bootstrap' => array('assets/bootstrap/bootstrap.min.css', '<link href="%s" rel="stylesheet">'),
            'js-bootstrap' => array('assets/bootstrap/bootstrap.bundle.min.js', '<script src="%s"></script>'),
            'js-jquery' => array('assets/jquery/jquery-3.6.1.min.js', '<script src="%s"></script>'),
            'css-font-awesome' => array('assets/font-awesome/css/font-awesome.min.css', '<link rel="stylesheet" href="%s">'),
            'css-dropzone' => array('assets/dropzone/dropzone.min.css', '<link href="%s" rel="stylesheet">'),
            'js-dropzone' => array('assets/dropzone/dropzone.min.js', '<script src="%s"></script>'),
            'js-jquery-datatables' => array('assets/datatables/jquery.dataTables.min.js', '<script src="%s" defer></script>'),
            'js-highlightjs' => array('assets/highlight/highlight.min.js', '<script src="%s"></script>'),
            'css-highlightjs' => array($style_file, '<link rel="stylesheet" href="%s">'),
            'js-ace' => array('assets-extra/ace/ace.js', '<script src="%s"></script>'),
        );
        $map = array();
        $all_local = true;
        foreach ($local as $key => $item) {
            if (!isset($external[$key]) || !is_string($external[$key]) || $external[$key] === '') {
                continue;
            }
            if (is_file(__DIR__ . '/' . $item[0])) {
                // Заменяем только целые теги из $external: в содержимом файлов, которое TFM
                // выводит через htmlspecialchars/fm_enc, символ "<" экранирован и совпасть не может.
                $map[$external[$key]] = sprintf($item[1], $url . $item[0]);
            } else {
                $all_local = false;
            }
        }
        if ($all_local) {
            // preconnect к CDN не нужен, если с CDN ничего не грузится
            foreach (array('pre-jsdelivr', 'pre-cloudflare') as $key) {
                if (isset($external[$key]) && is_string($external[$key]) && $external[$key] !== '') {
                    $map[$external[$key]] = '';
                }
            }
        }
        return $map ? strtr($buffer, $map) : $buffer;
    }
}

// --- Исправление вёрстки «Улучшенного редактора» (ace) ---
// В upstream #editor позиционирован абсолютно с жёстким top:100px (150px при ширине
// <= 481px). Панель над ним (кнопки ace + «Назад/Резервная копия/…/Сохранить») при
// ширине < 576px или при переносе кнопок становится выше 100px — редактор ложится
// поверх кнопок. В iframe панели MajorDoMo и на телефоне это происходит почти всегда.
// Делаем #editor обычным блоком после панели и подгоняем высоту под окно скриптом.
// Полноэкранный режим TFM (requestFullScreen) не затрагивается: стили :fullscreen
// браузера идут с !important.
if (!function_exists('tinyfm_editor_fix')) {
    function tinyfm_editor_fix($buffer)
    {
        if ($buffer === '' || strpos($buffer, '<div id="editor"') === false) {
            return $buffer;
        }
        $pos = strrpos($buffer, '</body>');
        if ($pos === false) {
            return $buffer;
        }
        // на странице ace нижнее поле #wrapper (tinyfm_ui_fix) не нужно — высоту задаёт fit()
        $fix = '<style>#wrapper{padding-bottom:0}#editor{position:relative;top:auto;right:auto;bottom:auto;left:auto;'
            . 'margin-top:.5rem;height:calc(100vh - 170px);min-height:200px}</style>'
            . '<script>(function(){function fit(){var el=document.getElementById("editor");if(!el)return;'
            . 'var top=el.getBoundingClientRect().top+(window.pageYOffset||0);'
            . 'el.style.height=Math.max(200,window.innerHeight-top-15)+"px";'
            . 'if(window.ace&&el.env&&el.env.editor){el.env.editor.resize();}}'
            . 'window.addEventListener("load",fit);window.addEventListener("resize",fit);'
            . 'if(document.readyState!=="loading"){setTimeout(fit,0);}})();</script>';
        return substr($buffer, 0, $pos) . $fix . substr($buffer, $pos);
    }
}

// --- Единый вид родных кнопок и панели свойств TFM, недостающие переводы ---
// В upstream кнопки на разных страницах разные (группы с переносом подписей, жирные и
// обычные), панель свойств файла всегда на половину ширины, а
// часть подписей вписана строкой мимо lng(): «Delete» (просмотр), «Save» (обычный
// редактор), «Full Path», «bytes», подсказка Dropzone. Правим на выходе, upstream не трогаем.
if (!function_exists('tinyfm_ui_fix')) {
    function tinyfm_ui_text($key, $fallback)
    {
        return function_exists('lng') ? lng($key) : $fallback;
    }

    function tinyfm_ui_fix($buffer)
    {
        global $lang;
        if ($buffer === '' || strpos($buffer, '<') === false) {
            return $buffer;
        }
        $ru = (isset($lang) && $lang === 'ru');
        $map = array(
            // строки, вписанные в upstream без перевода
            '<i class="fa fa-trash"></i> Delete</a>' => '<i class="fa fa-trash"></i> ' . tinyfm_ui_text('Delete', 'Delete') . '</a>',
            "onclick=\"edit_save(this,'nrl')\"><i class=\"fa fa-floppy-o\"></i> Save" =>
                "onclick=\"edit_save(this,'nrl')\"><i class=\"fa fa-floppy-o\"></i> " . tinyfm_ui_text('Save', 'Save'),
            '<strong>Full Path:</strong>' => '<strong>' . tinyfm_ui_text('Full Path', 'Full Path') . ':</strong>',
            '<strong>Host Path:</strong>' => '<strong>' . tinyfm_ui_text('Host Path', 'Host Path') . ':</strong>',
            '<strong>Path:</strong>' => '<strong>' . tinyfm_ui_text('Path', 'Path') . ':</strong>',
        );
        if ($ru) {
            $map[' bytes</li>'] = ' байт</li>';
            // в translation.json «Download» переведено как «Загрузка» (это upload) — «Скачать»
            $map['<i class="fa fa-cloud-download"></i> Загрузка</button>'] = '<i class="fa fa-cloud-download"></i> Скачать</button>';
            $map['title="Загрузка"'] = 'title="Скачать"';
            $map["confirmDialog(event, 1211, 'Загрузка',"] = "confirmDialog(event, 1211, 'Скачать',";
        }
        $buffer = strtr($buffer, $map);

        if (strpos($buffer, '</head>') !== false) {
            $css = '<style id="tinyfm-ui">'
                // шапка: пункты меню в одну строку
                . '.main-nav .navbar-nav .nav-link{white-space:nowrap}'
                . '.main-nav .input-group{flex-wrap:nowrap}.main-nav #search-addon{min-width:5.5rem}'
                // стрелка расширенного поиска: в свёрнутом меню (< 992px) Bootstrap 5 делает
                // выпадающие списки статичными — меню вставало в ряд с полем и растягивало его;
                // обёртки .input-group-append (Bootstrap 4) не растягивали лупу/стрелку по высоте;
                // dropdown-menu-right (BS4) в BS5 не выравнивает. Меню — всегда под стрелкой справа.
                . '.main-nav .input-group .input-group-append{display:flex}'
                . '.main-nav .input-group .btn-group{position:relative}'
                . '.main-nav .input-group .dropdown-menu{position:absolute!important;top:100%;right:0;left:auto;margin-top:2px;z-index:1050}'
                // стрелка — пустой span 33×30 px, а ссылка «Загрузить» начиналась вплотную под ней
                // (в свёрнутом меню — на всю ширину) или справа от неё: промах на 1–2 px открывал
                // загрузку. Стрелка шире, отступ после поиска (mr-2 — класс BS4, в BS5 не работает),
                // в свёрнутом меню — поле выше и зазор снизу.
                . '.main-nav .input-group .dropdown-toggle{min-width:2.5rem;justify-content:center;cursor:pointer}'
                . '.main-nav .navbar-nav>.nav-item.mr-2{margin-right:.75rem}'
                . '@media (max-width:991.98px){'
                . '.main-nav .navbar-nav>.nav-item.mr-2{margin:0 0 .75rem}'
                . '.main-nav #search-addon{min-height:2.375rem}'
                . '.main-nav .input-group .dropdown-toggle{min-width:3rem}}'
                // подписи кнопок не переносятся, вес одинаковый
                // поля страницы: сверху под шапкой было 25 px (margin-top body 55 px при шапке
                // 46 px + pt-3 у формы списка), снизу 0 — кнопки под списком лежали на нижней
                // границе окна. Теперь сверху и снизу по ~16 px.
                . '#wrapper{padding-bottom:1rem}'
                . '#wrapper>form.pt-3{padding-top:.5rem!important}'
                . '#wrapper .btn{white-space:nowrap}'
                . '#wrapper .btn.fw-bold{font-weight:400!important}'
                // группы действий (просмотр, редактор, под списком): отдельные кнопки одного
                // размера с отступом; при нехватке места переносятся целые кнопки
                . '#wrapper .btn-group.flex-wrap,#wrapper .edit-file-actions .btn-group{display:inline-flex;flex-wrap:wrap;gap:.35rem}'
                . '#wrapper .edit-file-actions .btn-group{justify-content:flex-end}'
                // flex:0 0 auto — у Bootstrap 5 кнопки в группе растягиваются (flex:1 1 auto)
                . '#wrapper .btn-group.flex-wrap>.btn,#wrapper .btn-group.flex-wrap>form.btn,#wrapper .edit-file-actions .btn-group>.btn'
                . '{flex:0 0 auto;border-radius:var(--bs-border-radius-sm)!important;margin-left:0!important;padding:.25rem .6rem;font-size:.875rem;line-height:1.5}'
                // «Скачать» в просмотре — форма с лишним &nbsp; после кнопки: прячем его размер шрифта
                . '#wrapper .btn-group.flex-wrap>form.btn{font-size:0}'
                . '#wrapper .btn-group.flex-wrap>form.btn>.btn{font-size:.875rem;font-weight:400!important;color:inherit;line-height:1.5;vertical-align:baseline}'
                . '#wrapper .edit-file-actions{padding-top:.5rem}'
                . '#wrapper .path:has(.edit-file-actions)>.row>div:first-child{padding-top:.5rem}'
                // панель свойств файла: на всю ширину (до 720px), длинный путь переносится
                // (цвета — родные TFM)
                . '#wrapper ul.list-group.w-50{width:100%!important;max-width:720px}'
                . '#wrapper ul.list-group.w-50 .list-group-item{overflow-wrap:anywhere}'
                // расширенный поиск: поле и крестик на одной линии без пустой полосы,
                // результаты прокручиваются внутри окна, рамки — цветом рамок темы
                // (в upstream #ececec и в тёмной теме), строка кликабельна целиком
                . '#searchModal .modal-header{align-items:center;padding:.75rem 1rem}'
                . '#searchModal .modal-header .modal-title{flex:1 1 auto;max-width:none;margin:0 .75rem 0 0}'
                . '#searchModal .modal-header .input-group{margin-bottom:0!important;flex-wrap:nowrap}'
                . '#searchModal .modal-body{max-height:65vh;overflow-y:auto}'
                . '#searchModal ul#search-wrapper{margin:0;border:1px solid var(--bs-border-color);border-radius:var(--bs-border-radius);overflow:hidden}'
                . '#searchModal ul#search-wrapper li{padding:0;border-bottom:1px solid var(--bs-border-color)}'
                . '#searchModal ul#search-wrapper li:last-child{border-bottom:0}'
                . '#searchModal ul#search-wrapper li a{display:block;padding:.4rem .75rem;overflow-wrap:anywhere;text-decoration:none}'
                . '#searchModal ul#search-wrapper li a:hover{text-decoration:underline}'
                . '#searchModal ul#search-wrapper>p{margin:.6rem .75rem!important}'
                . '</style>';
            $pos = strpos($buffer, '</head>');
            $buffer = substr($buffer, 0, $pos) . $css . substr($buffer, $pos);
        }

        // Подсказка в области загрузки (Dropzone) — по-русски
        if ($ru && strpos($buffer, 'Dropzone.options.fileUploader') !== false) {
            $pos = strrpos($buffer, '</body>');
            if ($pos !== false) {
                $js = '<script>if(window.Dropzone&&Dropzone.options.fileUploader){'
                    . 'Dropzone.options.fileUploader.dictDefaultMessage="Перетащите файлы сюда или нажмите, чтобы выбрать";}</script>';
                $buffer = substr($buffer, 0, $pos) . $js . substr($buffer, $pos);
            }
        }
        return $buffer;
    }
}

if (!function_exists('tinyfm_output_filter')) {
    function tinyfm_output_filter($buffer)
    {
        $buffer = tinyfm_local_assets($buffer);
        $buffer = tinyfm_ui_fix($buffer);
        return tinyfm_editor_fix($buffer);
    }
}
// Скачивание файлов TFM делает while (ob_get_level()) ob_end_clean(); —
// буфер снимается и бинарные данные идут мимо этого обработчика.
// Совпасть с «<div id="editor"» или «</body>» содержимое файлов не может:
// TFM выводит его через htmlspecialchars/fm_enc.
ob_start('tinyfm_output_filter');
