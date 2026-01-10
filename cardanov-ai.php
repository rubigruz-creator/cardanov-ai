<?php
/**
 * Plugin Name: Cardanov AI Agent
 * Plugin URI: https://cardanov.ru/
 * Description: ИИ-агент для ответов на вопросы пользователей
 * Version: 3.4.2
 * Author: Cardanov Team
 * License: GPL v2 or later
 * Text Domain: cardanov-ai
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CARDANOV_AI_VERSION', '3.4.2');
define('CARDANOV_AI_PATH', plugin_dir_path(__FILE__));
define('CARDANOV_AI_URL', plugin_dir_url(__FILE__));

class CardanovAIAgent {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('init', [$this, 'init']);
        
        add_action('wp_ajax_cardanov_ai_ask', [$this, 'ajax_handler']);
        add_action('wp_ajax_nopriv_cardanov_ai_ask', [$this, 'ajax_handler']);
        add_action('wp_ajax_cardanov_ai_check_table', [$this, 'ajax_check_table']);
        add_action('wp_ajax_cardanov_ai_create_table', [$this, 'ajax_create_table']);
        add_action('wp_ajax_cardanov_ai_export_logs', [$this, 'ajax_export_logs']);
        add_action('wp_ajax_cardanov_ai_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_cardanov_ai_force_create', [$this, 'ajax_force_create']);
        add_action('wp_ajax_cardanov_ai_export_knowledge', [$this, 'ajax_export_knowledge']);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_cardanov_ai_save_knowledge', [$this, 'handle_save_knowledge']);
        
        // Виджет показывается только если включен в настройках
        if (get_option('cardanov_ai_enabled', '1') === '1') {
            add_action('wp_footer', [$this, 'display_widget']);
        }
        
        add_action('elementor/widgets/register', [$this, 'register_elementor_widget']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
    }
    
    public function activate() {
        $this->create_table();
        $this->create_log_table();
        
        add_option('cardanov_ai_button_text', 'Задать вопрос');
        add_option('cardanov_ai_button_color', '#1a5fb4');
        add_option('cardanov_ai_welcome_message', 'Здравствуйте! Я помощник компании Автотехногарант. Спросите меня о ремонте карданных валов, ценах, адресе или графике работы.');
        add_option('cardanov_ai_enabled', '1'); // По умолчанию включен
        add_option('cardanov_ai_excluded_pages', ''); // Пусто - нет исключений
    }
    
    public function deactivate() {}
    
    public function init() {
        load_plugin_textdomain('cardanov-ai', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    private function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                question varchar(255) NOT NULL,
                answer text NOT NULL,
                keywords varchar(255),
                category varchar(50) DEFAULT 'general',
                priority int(3) DEFAULT 5,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $wpdb->query($sql);
            
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($count == 0) {
                $this->add_default_data($table_name);
            }
        }
    }
    
    private function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cardanov_ai_logs';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                question varchar(500) NOT NULL,
                answer_found tinyint(1) DEFAULT 0,
                response_time float DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                $index_exists = $wpdb->get_var("
                    SELECT COUNT(*) 
                    FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '$table_name' 
                    AND index_name = 'answer_found'
                ");
                
                if (!$index_exists) {
                    $wpdb->query("ALTER TABLE $table_name ADD INDEX answer_found (answer_found)");
                }
                
                $index_exists = $wpdb->get_var("
                    SELECT COUNT(*) 
                    FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '$table_name' 
                    AND index_name = 'created_at'
                ");
                
                if (!$index_exists) {
                    $wpdb->query("ALTER TABLE $table_name ADD INDEX created_at (created_at)");
                }
            }
        }
        
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    public function force_create_tables() {
        $this->create_table();
        $logs_created = $this->create_log_table();
        
        global $wpdb;
        $knowledge_table = $wpdb->prefix . 'cardanov_ai_knowledge';
        $logs_table = $wpdb->prefix . 'cardanov_ai_logs';
        
        $knowledge_exists = $wpdb->get_var("SHOW TABLES LIKE '$knowledge_table'") == $knowledge_table;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table;
        
        return [
            'knowledge' => $knowledge_exists ? '✅ Создана' : '❌ Ошибка',
            'logs' => $logs_exists ? '✅ Создана' : '❌ Ошибка',
            'logs_created' => $logs_created
        ];
    }
    
    private function add_default_data($table_name) {
        global $wpdb;
        
        $default_data = [
            [
                'question' => 'ремонт карданных валов',
                'answer' => 'Мы специализируемся на ремонте карданных валов любой сложности. Используем оригинальные запчасти и современное оборудование.',
                'keywords' => 'кардан, карданный вал, ремонт кардана',
                'category' => 'services',
                'priority' => 10
            ],
            [
                'question' => 'ремонт рулевых тяг',
                'answer' => 'Выполняем профессиональный ремонт рулевых тяг с гарантией качества. Диагностика и ремонт в день обращения.',
                'keywords' => 'рулевая тяга, наконечник рулевой, ремонт рулевого',
                'category' => 'services',
                'priority' => 9
            ],
            [
                'question' => 'цены',
                'answer' => 'Стоимость ремонта зависит от модели автомобиля и сложности работ. Для точного расчета цены нужна диагностика.',
                'keywords' => 'сколько стоит, цена, прайс, стоимость',
                'category' => 'prices',
                'priority' => 8
            ],
            [
                'question' => 'адрес',
                'answer' => 'Наш сервис расположен по адресу: Москва, Щербинка, ул. Космонавтов, 16А.',
                'keywords' => 'где находитесь, адрес, как проехать',
                'category' => 'contacts',
                'priority' => 9
            ],
            [
                'question' => 'телефон',
                'answer' => 'Наш телефон: +7 991 690-79-49. Звоните для консультации и записи.',
                'keywords' => 'номер телефона, контакты, позвонить',
                'category' => 'contacts',
                'priority' => 9
            ],
            [
                'question' => 'график работы',
                'answer' => 'Мы работаем с 9:00 до 19:00 ежедневно, без выходных и перерывов.',
                'keywords' => 'часы работы, во сколько открываетесь',
                'category' => 'schedule',
                'priority' => 7
            ]
        ];
        
        foreach ($default_data as $data) {
            $wpdb->insert($table_name, $data);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Cardanov AI Agent',
            'AI Agent',
            'manage_options',
            'cardanov-ai',
            [$this, 'admin_main_page'],
            'dashicons-robot',
            30
        );
        
        add_submenu_page(
            'cardanov-ai',
            'База знаний',
            'База знаний',
            'manage_options',
            'cardanov-ai-knowledge',
            [$this, 'admin_knowledge_page']
        );
        
        add_submenu_page(
            'cardanov-ai',
            'Логи вопросов',
            'Логи вопросов',
            'manage_options',
            'cardanov-ai-logs',
            [$this, 'admin_logs_page']
        );
        
        add_submenu_page(
            'cardanov-ai',
            'Импорт/Экспорт',
            'Импорт/Экспорт',
            'manage_options',
            'cardanov-ai-import',
            [$this, 'admin_import_page']
        );
        
        add_submenu_page(
            'cardanov-ai',
            'Настройки',
            'Настройки',
            'manage_options',
            'cardanov-ai-settings',
            [$this, 'admin_settings_page']
        );
    }
    
    public function admin_styles($hook) {
        if (strpos($hook, 'cardanov-ai') !== false) {
            ?>
            <style>
            .cardanov-ai-stats {
                display: flex;
                gap: 20px;
                margin: 20px 0;
                flex-wrap: wrap;
            }
            .cardanov-ai-stat-box {
                flex: 1;
                min-width: 200px;
                background: white;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
            }
            .cardanov-ai-stat-number {
                font-size: 36px;
                font-weight: bold;
                color: #1a5fb4;
                margin: 10px 0;
            }
            .cardanov-ai-quick-actions {
                display: flex;
                gap: 10px;
                margin: 20px 0;
                flex-wrap: wrap;
            }
            .cardanov-ai-category-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .category-general { background: #e8f4ff; color: #1a5fb4; }
            .category-services { background: #d4edda; color: #155724; }
            .category-prices { background: #fff3cd; color: #856404; }
            .category-contacts { background: #f8d7da; color: #721c24; }
            .category-schedule { background: #d1ecf1; color: #0c5460; }
            .priority-badge {
                display: inline-block;
                width: 24px;
                height: 24px;
                line-height: 24px;
                text-align: center;
                background: #1a5fb4;
                color: white;
                border-radius: 50%;
                font-weight: bold;
            }
            .status-active {
                color: #46b450;
                font-weight: 500;
            }
            .status-inactive {
                color: #a7aaad;
            }
            .button-delete {
                background: #dc3232 !important;
                border-color: #dc3232 !important;
                color: white !important;
            }
            .button-delete:hover {
                background: #a00 !important;
                border-color: #a00 !important;
            }
            @media (max-width: 782px) {
                .cardanov-ai-stat-box {
                    min-width: 100%;
                }
            }
            </style>
            <?php
        }
    }
    
    public function admin_main_page() {
        global $wpdb;
        $knowledge_table = $wpdb->prefix . 'cardanov_ai_knowledge';
        $logs_table = $wpdb->prefix . 'cardanov_ai_logs';
        
        $knowledge_exists = $wpdb->get_var("SHOW TABLES LIKE '$knowledge_table'") == $knowledge_table;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table;
        
        $total = $knowledge_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $knowledge_table") : 0;
        $active = $knowledge_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $knowledge_table WHERE is_active = 1") : 0;
        
        if ($logs_exists) {
            $total_questions = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");
            $today_questions = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE DATE(created_at) = CURDATE()");
            $unanswered = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE answer_found = 0");
        }
        ?>
        <div class="wrap">
            <h1>🤖 Cardanov AI Agent v<?php echo CARDANOV_AI_VERSION; ?></h1>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 1000px; margin: 20px 0;">
                <h2>Статус системы</h2>
                
                <div class="cardanov-ai-stats">
                    <div class="cardanov-ai-stat-box">
                        <h3>Таблица знаний</h3>
                        <div class="cardanov-ai-stat-number" style="color: <?php echo $knowledge_exists ? '#46b450' : '#dc3232'; ?>">
                            <?php echo $knowledge_exists ? '✓' : '✗'; ?>
                        </div>
                        <p><?php echo $knowledge_exists ? 'Работает' : 'Не создана'; ?></p>
                    </div>
                    
                    <div class="cardanov-ai-stat-box">
                        <h3>Записей в базе</h3>
                        <div class="cardanov-ai-stat-number"><?php echo $total; ?></div>
                        <p>всего / <?php echo $active; ?> активных</p>
                    </div>
                    
                    <div class="cardanov-ai-stat-box">
                        <h3>Таблица логов</h3>
                        <div class="cardanov-ai-stat-number" style="color: <?php echo $logs_exists ? '#46b450' : '#dc3232'; ?>">
                            <?php echo $logs_exists ? '✓' : '✗'; ?>
                        </div>
                        <p><?php echo $logs_exists ? 'Сбор данных' : 'Не создана'; ?></p>
                    </div>
                    
                    <?php if ($logs_exists): ?>
                    <div class="cardanov-ai-stat-box">
                        <h3>Вопросов сегодня</h3>
                        <div class="cardanov-ai-stat-number"><?php echo $today_questions ?? 0; ?></div>
                        <p><?php echo $total_questions ?? 0; ?> всего</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!$knowledge_exists || !$logs_exists): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                    <h3 style="margin-top: 0;">⚠ Проблема с таблицами!</h3>
                    
                    <?php if (!$knowledge_exists): ?>
                    <p><strong>Таблица знаний:</strong> ❌ Не существует</p>
                    <?php endif; ?>
                    
                    <?php if (!$logs_exists): ?>
                    <p><strong>Таблица логов:</strong> ❌ Не существует</p>
                    <?php endif; ?>
                    
                    <p>На BeGet иногда возникают проблемы с созданием таблиц. Используйте кнопку ниже:</p>
                    
                    <button id="force-create-tables" class="button button-primary" style="margin-top: 10px;">
                        🔧 Принудительно создать таблицы
                    </button>
                    
                    <div id="force-create-result" style="margin-top: 10px;"></div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('#force-create-tables').on('click', function() {
                            var btn = $(this);
                            btn.prop('disabled', true).text('Создание...');
                            
                            $.post(ajaxurl, {
                                action: 'cardanov_ai_force_create',
                                nonce: '<?php echo wp_create_nonce("cardanov_ai_force_create"); ?>'
                            }, function(response) {
                                if (response.success) {
                                    var html = '<div style="color: green; padding: 10px; background: #d4edda; border-radius: 4px;">';
                                    html += '<strong>✅ Результат:</strong><br>';
                                    html += 'Таблица знаний: ' + response.data.knowledge + '<br>';
                                    html += 'Таблица логов: ' + response.data.logs + '<br>';
                                    html += '</div>';
                                    
                                    $('#force-create-result').html(html);
                                    btn.text('🔧 Таблицы созданы');
                                    
                                    setTimeout(function() {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    $('#force-create-result').html('<div style="color: red; padding: 10px; background: #f8d7da; border-radius: 4px;">❌ ' + response.data.message + '</div>');
                                    btn.prop('disabled', false).text('🔧 Принудительно создать таблицы');
                                }
                            }).fail(function() {
                                $('#force-create-result').html('<div style="color: red; padding: 10px; background: #f8d7da; border-radius: 4px;">❌ Ошибка сервера</div>');
                                btn.prop('disabled', false).text('🔧 Принудительно создать таблицы');
                            });
                        });
                    });
                    </script>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border: 1px solid #ddd; border-radius: 4px; max-width: 1000px;">
                <h3>Быстрые действия</h3>
                <div class="cardanov-ai-quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge'); ?>" class="button button-primary">
                        📚 Управление базой знаний
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-logs'); ?>" class="button button-primary">
                        📊 Логи вопросов
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-import'); ?>" class="button button-primary">
                        📥 Импорт/Экспорт
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-settings'); ?>" class="button">
                        ⚙️ Настройки виджета
                    </a>
                    <button class="button" onclick="testAI()">
                        🧪 Тест AI
                    </button>
                </div>
                
                <?php if ($logs_exists && ($unanswered ?? 0) > 0): ?>
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 4px; border: 1px solid #ffeaa7;">
                    <h4 style="margin-top: 0;">⚠ Есть вопросы без ответов!</h4>
                    <p>Пользователи задали <strong><?php echo $unanswered; ?> вопросов</strong>, на которые AI не нашел ответов.</p>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-logs'); ?>" class="button button-small">
                        🔍 Посмотреть лог
                    </a>
                </div>
                <?php endif; ?>
                
                <script>
                function testAI() {
                    jQuery.post(ajaxurl, {
                        action: 'cardanov_ai_ask',
                        question: 'ремонт'
                    }, function(response) {
                        if (response.success) {
                            alert('✅ AI работает!\n\nВопрос: ремонт\nОтвет: ' + response.data.answer);
                        } else {
                            alert('❌ Ошибка: ' + response.data.message);
                        }
                    });
                }
                </script>
            </div>
        </div>
        <?php
    }
    
    public function admin_knowledge_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            echo '<div class="wrap">';
            echo '<h1>📚 База знаний AI Agent</h1>';
            echo '<div class="notice notice-error"><p><strong>❌ ТАБЛИЦА НЕ СУЩЕСТВУЕТ!</strong></p>';
            echo '<p>Вернитесь на главную страницу плагина и создайте таблицу.</p></div>';
            echo '</div>';
            return;
        }
        
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            
            if (wp_verify_nonce($nonce, 'delete_knowledge_' . $id)) {
                $result = $wpdb->delete($table_name, ['id' => $id]);
                
                if ($result !== false) {
                    echo '<script>
                    if (window.history.replaceState) {
                        window.history.replaceState(null, null, "' . admin_url('admin.php?page=cardanov-ai-knowledge') . '");
                    }
                    </script>';
                    
                    echo '<div class="notice notice-success is-dismissible"><p>✅ Запись успешно удалена!</p></div>';
                }
            }
        }
        
        if (isset($_GET['message'])) {
            $messages = [
                'saved' => '<div class="notice notice-success is-dismissible"><p>✅ Запись успешно сохранена!</p></div>',
                'error' => '<div class="notice notice-error is-dismissible"><p>❌ Ошибка сохранения записи!</p></div>'
            ];
            
            if (isset($messages[$_GET['message']])) {
                echo $messages[$_GET['message']];
            }
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($action == 'edit') {
            $this->render_edit_form($id);
            return;
        }
        
        $this->render_knowledge_list();
    }
    
    private function render_knowledge_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        $items = $wpdb->get_results("SELECT * FROM $table_name ORDER BY priority DESC, id DESC");
        ?>
        <div class="wrap">
            <h1>📚 База знаний AI Agent</h1>
            
            <div style="margin: 20px 0;">
                <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge&action=edit'); ?>" class="button button-primary">➕ Добавить новую запись</a>
                <a href="<?php echo admin_url('admin.php?page=cardanov-ai-logs'); ?>" class="button">📊 Посмотреть логи вопросов</a>
                <a href="<?php echo admin_url('admin.php?page=cardanov-ai-import'); ?>" class="button">📥 Импорт CSV</a>
            </div>
            
            <?php if (empty($items)): ?>
                <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 4px; border: 1px solid #ddd;">
                    <p style="font-size: 18px; margin-bottom: 10px;">База знаний пуста</p>
                    <p>Добавьте первую запись, чтобы AI мог отвечать на вопросы</p>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-import'); ?>" class="button button-primary" style="margin-top: 15px;">
                        📥 Быстрый импорт 30 вопросов
                    </a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>Вопрос</th>
                            <th>Ключевые слова</th>
                            <th width="100">Категория</th>
                            <th width="80">Приоритет</th>
                            <th width="80">Статус</th>
                            <th width="200">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo $item->id; ?></td>
                            <td>
                                <strong><?php echo esc_html($item->question); ?></strong>
                                <div style="color: #666; font-size: 13px; margin-top: 5px;">
                                    <?php echo esc_html(wp_trim_words($item->answer, 10)); ?>
                                </div>
                            </td>
                            <td><?php echo esc_html($item->keywords); ?></td>
                            <td>
                                <?php
                                $categories = [
                                    'general' => '<span class="cardanov-ai-category-badge category-general">Общие</span>',
                                    'services' => '<span class="cardanov-ai-category-badge category-services">Услуги</span>',
                                    'prices' => '<span class="cardanov-ai-category-badge category-prices">Цены</span>',
                                    'contacts' => '<span class="cardanov-ai-category-badge category-contacts">Контакты</span>',
                                    'schedule' => '<span class="cardanov-ai-category-badge category-schedule">График</span>'
                                ];
                                echo $categories[$item->category] ?? $item->category;
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="priority-badge"><?php echo $item->priority; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($item->is_active): ?>
                                    <span class="status-active">✓ Активна</span>
                                <?php else: ?>
                                    <span class="status-inactive">✗ Неактивна</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge&action=edit&id=' . $item->id); ?>" 
                                   class="button button-small">✏️ Редактировать</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=cardanov-ai-knowledge&action=delete&id=' . $item->id), 'delete_knowledge_' . $item->id); ?>" 
                                   class="button button-small button-delete" 
                                   onclick="return confirm('Удалить эту запись?')">🗑️ Удалить</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                    <p><strong>💡 Совет:</strong> Для быстрого заполнения базы используйте импорт CSV.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_edit_form($id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        $item = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id)) : null;
        
        if (!$id && isset($_GET['auto_question'])) {
            $auto_question = sanitize_text_field($_GET['auto_question']);
            $item = (object)[
                'question' => $auto_question,
                'keywords' => $auto_question,
                'answer' => '',
                'category' => 'general',
                'priority' => 5,
                'is_active' => 1
            ];
        }
        
        if ($id && !$item) {
            echo '<div class="notice notice-error"><p>Запись не найдена!</p></div>';
            $this->render_knowledge_list();
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo $id ? '✏️ Редактирование записи' : '➕ Добавление записи'; ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="cardanov_ai_save_knowledge">
                <?php wp_nonce_field('cardanov_ai_save_knowledge', 'cardanov_ai_nonce'); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="question">Вопрос/Тема:</label></th>
                        <td>
                            <input type="text" id="question" name="question" 
                                   value="<?php echo $item ? esc_attr($item->question) : ''; ?>" 
                                   class="regular-text" required style="width: 100%; max-width: 500px;">
                            <p class="description">Основной вопрос или тема</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="keywords">Ключевые слова:</label></th>
                        <td>
                            <input type="text" id="keywords" name="keywords" 
                                   value="<?php echo $item ? esc_attr($item->keywords) : ''; ?>" 
                                   class="regular-text" style="width: 100%; max-width: 500px;">
                            <p class="description">Синонимы через запятую</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="answer">Ответ:</label></th>
                        <td>
                            <textarea id="answer" name="answer" rows="6" class="large-text" required style="width: 100%; max-width: 500px;"><?php 
                                echo $item ? esc_textarea($item->answer) : '';
                            ?></textarea>
                            <p class="description">Полный ответ для пользователя</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="category">Категория:</label></th>
                        <td>
                            <select id="category" name="category" style="width: 100%; max-width: 300px;">
                                <option value="general" <?php selected($item ? $item->category : 'general', 'general'); ?>>Общие</option>
                                <option value="services" <?php selected($item ? $item->category : 'general', 'services'); ?>>Услуги</option>
                                <option value="prices" <?php selected($item ? $item->category : 'general', 'prices'); ?>>Цены</option>
                                <option value="contacts" <?php selected($item ? $item->category : 'general', 'contacts'); ?>>Контакты</option>
                                <option value="schedule" <?php selected($item ? $item->category : 'general', 'schedule'); ?>>График работы</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="priority">Приоритет (1-10):</label></th>
                        <td>
                            <input type="range" id="priority" name="priority" min="1" max="10" 
                                   value="<?php echo $item ? $item->priority : 5; ?>"
                                   oninput="document.getElementById('priority-value').textContent = this.value" 
                                   style="width: 200px; vertical-align: middle;">
                            <span id="priority-value" style="margin-left: 10px; font-weight: bold; font-size: 16px; color: #1a5fb4;">
                                <?php echo $item ? $item->priority : 5; ?>
                            </span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Статус:</th>
                        <td>
                            <label>
                                <input type="checkbox" id="is_active" name="is_active" value="1" 
                                    <?php checked($item ? $item->is_active : 1, 1); ?>>
                                Активная запись
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        💾 <?php echo $id ? 'Сохранить изменения' : 'Добавить запись'; ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge'); ?>" class="button button-large">
                        ← Назад к списку
                    </a>
                </p>
            </form>
        </div>
        <?php
    }
    
    public function handle_save_knowledge() {
        if (!isset($_POST['cardanov_ai_nonce']) || !wp_verify_nonce($_POST['cardanov_ai_nonce'], 'cardanov_ai_save_knowledge')) {
            wp_die('Ошибка безопасности!');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав!');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $data = [
            'question' => isset($_POST['question']) ? sanitize_text_field($_POST['question']) : '',
            'keywords' => isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '',
            'answer' => isset($_POST['answer']) ? sanitize_textarea_field($_POST['answer']) : '',
            'category' => isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'general',
            'priority' => isset($_POST['priority']) ? intval($_POST['priority']) : 5,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        
        if (empty($data['question']) || empty($data['answer'])) {
            wp_redirect(admin_url('admin.php?page=cardanov-ai-knowledge&message=error'));
            exit;
        }
        
        if ($id > 0) {
            $result = $wpdb->update($table_name, $data, ['id' => $id]);
        } else {
            $result = $wpdb->insert($table_name, $data);
        }
        
        if ($result !== false) {
            wp_redirect(admin_url('admin.php?page=cardanov-ai-knowledge&message=saved'));
        } else {
            wp_redirect(admin_url('admin.php?page=cardanov-ai-knowledge&message=error'));
        }
        
        exit;
    }
    
    public function admin_logs_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_logs';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            echo '<div class="wrap">';
            echo '<h1>📊 Логи вопросов</h1>';
            echo '<div class="notice notice-error"><p><strong>❌ ТАБЛИЦА ЛОГОВ НЕ СУЩЕСТВУЕТ!</strong></p>';
            echo '<p>Вернитесь на главную страницу плагина и нажмите "Принудительно создать таблицы".</p>';
            echo '<p><a href="' . admin_url('admin.php?page=cardanov-ai') . '" class="button button-primary">Перейти на главную</a></p>';
            echo '</div>';
            return;
        }
        
        $total_questions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $answered_questions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE answer_found = 1");
        $unanswered_questions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE answer_found = 0");
        $today_questions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = CURDATE()");
        
        $popular_unanswered = $wpdb->get_results("
            SELECT question, COUNT(*) as count 
            FROM $table_name 
            WHERE answer_found = 0 
            GROUP BY question 
            ORDER BY count DESC 
            LIMIT 20
        ");
        
        $recent_questions = $wpdb->get_results("
            SELECT * FROM $table_name 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        
        ?>
        <div class="wrap">
            <h1>📊 Логи вопросов пользователей</h1>
            
            <div class="cardanov-ai-stats" style="margin: 20px 0;">
                <div class="cardanov-ai-stat-box">
                    <h3>Всего вопросов</h3>
                    <div class="cardanov-ai-stat-number"><?php echo $total_questions; ?></div>
                    <p>с момента установки</p>
                </div>
                
                <div class="cardanov-ai-stat-box">
                    <h3>Отвечено</h3>
                    <div class="cardanov-ai-stat-number" style="color: #46b450;"><?php echo $answered_questions; ?></div>
                    <p>AI нашел ответ</p>
                </div>
                
                <div class="cardanov-ai-stat-box">
                    <h3>Без ответа</h3>
                    <div class="cardanov-ai-stat-number" style="color: #dc3232;"><?php echo $unanswered_questions; ?></div>
                    <p>нужно добавить в базу</p>
                </div>
                
                <div class="cardanov-ai-stat-box">
                    <h3>Сегодня</h3>
                    <div class="cardanov-ai-stat-number"><?php echo $today_questions; ?></div>
                    <p>вопросов за сегодня</p>
                </div>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0;">
                <h3>🔥 Популярные вопросы без ответа</h3>
                <p>Эти вопросы пользователи задают чаще всего, но в базе знаний нет ответов:</p>
                
                <?php if ($popular_unanswered): ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th>Вопрос</th>
                                <th width="100">Количество</th>
                                <th width="150">Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popular_unanswered as $index => $item): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo esc_html($item->question); ?></strong>
                                    <div style="color: #666; font-size: 13px; margin-top: 5px;">
                                        <small>Спросили <?php echo $item->count; ?> раз</small>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <span class="priority-badge"><?php echo $item->count; ?></span>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge&action=edit&auto_question=' . urlencode($item->question)); ?>" 
                                       class="button button-small button-primary">
                                        ➕ Добавить в базу
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="padding: 20px; text-align: center; color: #666;">
                        🎉 Отлично! Все вопросы имеют ответы в базе знаний!
                    </p>
                <?php endif; ?>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0;">
                <h3>🕒 Последние вопросы</h3>
                <p>Последние 50 вопросов от пользователей:</p>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th>Вопрос</th>
                            <th width="120">Статус</th>
                            <th width="100">Время</th>
                            <th width="120">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_questions as $item): ?>
                        <tr>
                            <td><?php echo $item->id; ?></td>
                            <td><?php echo esc_html($item->question); ?></td>
                            <td style="text-align: center;">
                                <?php if ($item->answer_found): ?>
                                    <span style="color: #46b450; font-weight: 500;">✓ Ответ найден</span>
                                <?php else: ?>
                                    <span style="color: #dc3232; font-weight: 500;">✗ Нет ответа</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo number_format($item->response_time, 2); ?>с
                            </td>
                            <td style="text-align: center;">
                                <?php echo date('d.m.Y H:i', strtotime($item->created_at)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3>⚡ Быстрые действия</h3>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <a href="<?php echo admin_url('admin.php?page=cardanov-ai-knowledge&action=edit'); ?>" 
                       class="button button-primary">
                        📝 Добавить новый вопрос
                    </a>
                    
                    <button class="button" onclick="exportLogs()">
                        📥 Экспорт логов (CSV)
                    </button>
                    
                    <button class="button" onclick="clearOldLogs()">
                        🗑️ Очистить старые логи
                    </button>
                </div>
            </div>
            
            <script>
            function exportLogs() {
                var url = '<?php echo admin_url('admin-ajax.php'); ?>';
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = url;
                form.innerHTML = '<input type="hidden" name="action" value="cardanov_ai_export_logs">';
                document.body.appendChild(form);
                form.submit();
            }
            
            function clearOldLogs() {
                if (confirm('Удалить логи старше 30 дней? Это действие нельзя отменить.')) {
                    jQuery.post(ajaxurl, {
                        action: 'cardanov_ai_clear_logs',
                        nonce: '<?php echo wp_create_nonce('cardanov_ai_admin'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            alert('❌ ' + response.data.message);
                        }
                    });
                }
            }
            </script>
        </div>
        <?php
    }
    
    public function admin_import_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        ?>
        <div class="wrap">
            <h1>📥 Импорт базы знаний из CSV</h1>
            
            <?php
            // Обработка загрузки файла
            if (isset($_POST['submit_import']) && isset($_FILES['csv_file'])) {
                $this->handle_csv_import();
            }
            ?>
            
            <div style="max-width: 800px; margin-top: 20px;">
                <div style="background: #f8f9fa; padding: 20px; border-radius: 4px; border: 1px solid #ddd;">
                    <h3>Быстрое заполнение базы</h3>
                    <p>Загрузите CSV файл с вопросами и ответами:</p>
                    
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('cardanov_ai_import_csv', 'cardanov_ai_nonce'); ?>
                        
                        <p><strong>Формат CSV:</strong></p>
                        <pre style="background: white; padding: 10px; border: 1px solid #ccc; border-radius: 3px;">
вопрос,ответ,ключевые слова,категория,приоритет
Сколько стоит ремонт кардана?,Стоимость от 3000 до 15000 рублей,цена,стоимость,прайс,prices,8
Где находится сервис?,Москва, Щербинка, ул. Космонавтов, 16А,адрес,место,как проехать,contacts,9
                        </pre>
                        
                        <p style="margin-top: 15px;">
                            <input type="file" name="csv_file" accept=".csv" required style="padding: 8px;">
                        </p>
                        
                        <p>
                            <button type="submit" name="submit_import" class="button button-primary button-large">
                                📥 Импортировать CSV
                            </button>
                        </p>
                    </form>
                </div>
                
                <div style="margin-top: 30px; padding: 15px; background: #e8f4ff; border-radius: 4px;">
                    <h3>⚡ Готовый файл для импорта</h3>
                    <p>Создайте CSV файл с 30 вопросами для автосервиса:</p>
                    
                    <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 3px; margin-top: 10px;">
                        <p><strong>Скопируйте этот текст в блокнот и сохраните как questions.csv:</strong></p>
                        <textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; padding: 10px; background: #f8f9fa;">
вопрос,ответ,ключевые слова,категория,приоритет
Сколько стоит ремонт карданного вала?,Стоимость ремонта карданного вала зависит от модели автомобиля и сложности повреждения. Цена начинается от 3000 рублей. Для точного расчета нужна диагностика.,цена,стоимость,прайс,кардан,ремонт,prices,8
Где находится ваш сервис?,Наш сервис расположен по адресу: Москва, Щербинка, ул. Космонавтов, 16А. Есть удобный подъезд и парковка.,адрес,место,расположение,карта,проезд,contacts,9
Какой график работы?,Мы работаем ежедневно с 9:00 до 19:00 без выходных и перерывов.,часы работы,график,во сколько,расписание,когда открыто,schedule,7
Есть ли гарантия на ремонт?,Да, на все виды ремонтных работ мы даем гарантию от 6 месяцев. На замененные детали - гарантия до 1 года.,гарантия,срок,обеспечение,страховка,services,8
Ремонтируете ли рулевые тяги?,Да, мы специализируемся на ремонте рулевых тяг и наконечников. Работы выполняем с гарантией качества.,рулевая тяга,наконечник,ремонт руля,services,9
Сколько времени занимает ремонт?,Ремонт карданного вала обычно занимает 1-2 дня. Рулевые тяги ремонтируем за 2-3 часа. Срочный ремонт возможен за дополнительную плату.,время,сроки,длительность,как быстро,schedule,6
Нужна ли предварительная запись?,Запись желательна, но мы принимаем и без записи. Для записи звоните по телефону +7 991 690-79-49.,запись,регистрация,бронирование,контакты,8
Какие марки автомобилей обслуживаете?,Обслуживаем все марки автомобилей: отечественные и иностранные. Специализируемся на легковых и коммерческих авто.,марки,автомобили,бренды,модели,services,7
Есть ли выездная диагностика?,Да, возможен выезд мастера для диагностики. Стоимость выезда от 1000 рублей в зависимости от района.,выезд,диагностика,мастер,домой,services,6
Принимаете безналичный расчет?,Да, принимаем банковские карты, переводы. Также работаем с юридическими лицами по безналичному расчету.,оплата,карта,безнал,расчет,prices,7
Какие запчасти используете?,Используем оригинальные и качественные аналоги. Все запчасти имеют сертификаты качества.,запчасти,детали,комплектующие,материалы,services,8
Есть ли скидки постоянным клиентам?,Да, для постоянных клиентов действует система скидок. Также проводим акции и специальные предложения.,скидки,акции,постоянным,бонусы,prices,6
Можно ли получить консультацию по телефону?,Да, консультируем по телефону +7 991 690-79-49. Отвечаем на вопросы о ремонте, ценах, графике работы.,консультация,телефон,звонок,совет,contacts,9
Какие симптомы неисправности кардана?,Стук при трогании, вибрация на скорости, щелчки при повороте - признаки неисправности карданного вала.,симптомы,признаки,неисправность,диагностика,services,8
Работаете с юридическими лицами?,Да, работаем с юридическими лицами, предоставляем все необходимые документы для бухгалтерии.,юр лица,организации,документы,договор,services,6
Есть ли услуга эвакуатора?,Да, можем организовать эвакуацию автомобиля до сервиса. Стоимость от 2000 рублей.,эвакуатор,буксировка,транспортировка,services,5
Какое оборудование используете?,Используем профессиональное оборудование для диагностики и ремонта. Регулярно обновляем техническую базу.,оборудование,техника,инструменты,services,7
Даете ли справку для страховой?,Да, предоставляем все необходимые документы для страховой компании после ремонта.,документы,справки,страховая,отчет,services,6
Ремонтируете коммерческие автомобили?,Да, ремонтируем карданные валы на грузовых автомобилях, микроавтобусах, спецтехнике.,грузовые,коммерческие,фургоны,газели,services,7
Есть ли услуга срочного ремонта?,Да, выполняем срочный ремонт за дополнительную плату. Минимальное время - 3 часа.,срочный,быстрый,экспресс,services,6
Какие платежные системы принимаете?,Принимаем Visa, Mastercard, МИР. Также наличные и переводы через Сбербанк Онлайн.,оплата,карты,наличные,переводы,prices,7
Работаете в праздничные дни?,Да, работаем в праздничные дни по обычному графику.,праздники,выходные,график,schedule,6
Есть ли видеонаблюдение в сервисе?,Да, вся территория сервиса находится под видеонаблюдением для безопасности автомобилей.,безопасность,видеонаблюдение,охрана,services,5
Предоставляете ли подменный автомобиль?,Нет, услуги подменного автомобиля не предоставляем.,подменный,авто на время,замена,services,4
Можно ли посмотреть отзывы о сервисе?,Отзывы наших клиентов можно посмотреть на сайте или в наших группах в социальных сетях.,отзывы,рекомендации,мнения,клиенты,general,7
Есть ли рассрочка платежа?,Рассрочка платежа не предоставляется. Возможна оплата частями по договоренности.,рассрочка,оплата частями,кредит,prices,5
Работаете с дизельными автомобилями?,Да, ремонтируем карданные валы на дизельных автомобилях всех марок.,дизель,топливо,двигатель,services,6
Есть ли система онлайн-записи?,Онлайн-запись через сайт временно не работает. Записывайтесь по телефону.,онлайн запись,сайт,интернет,contacts,5
Какая зона обслуживания?,Обслуживаем Москву и Московскую область. Возможен выезд в пределах 50 км от МКАД.,Москва,область,МО,регион,services,6
Сколько лет на рынке?,Работаем на рынке услуг с 2010 года. Имеем большой опыт ремонтов.,опыт,стаж,лет,история,general,8
                        </textarea>
                    </div>
                </div>
                
                <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 4px;">
                    <h3>📊 Экспорт текущей базы</h3>
                    <p>Скачать все вопросы в CSV файл:</p>
                    <a href="<?php echo admin_url('admin-ajax.php?action=cardanov_ai_export_knowledge'); ?>" 
                       class="button">
                        📤 Экспорт базы знаний
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function handle_csv_import() {
        if (!isset($_POST['cardanov_ai_nonce']) || !wp_verify_nonce($_POST['cardanov_ai_nonce'], 'cardanov_ai_import_csv')) {
            echo '<div class="notice notice-error"><p>❌ Ошибка безопасности!</p></div>';
            return;
        }
        
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>❌ Недостаточно прав!</p></div>';
            return;
        }
        
        $file = $_FILES['csv_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>❌ Ошибка загрузки файла!</p></div>';
            return;
        }
        
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            echo '<div class="notice notice-error"><p>❌ Файл должен быть в формате CSV!</p></div>';
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        
        $handle = fopen($file['tmp_name'], 'r');
        $imported = 0;
        $skipped = 0;
        $row = 0;
        
        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $row++;
            
            // Пропускаем заголовок
            if ($row === 1) continue;
            
            // Проверяем количество полей
            if (count($data) < 2) {
                $skipped++;
                continue;
            }
            
            $question = trim(sanitize_text_field($data[0]));
            $answer = trim(sanitize_textarea_field($data[1]));
            $keywords = isset($data[2]) ? trim(sanitize_text_field($data[2])) : '';
            $category = isset($data[3]) ? trim(sanitize_text_field($data[3])) : 'general';
            $priority = isset($data[4]) ? intval($data[4]) : 5;
            
            if (empty($question) || empty($answer)) {
                $skipped++;
                continue;
            }
            
            // Проверяем, нет ли уже такого вопроса
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE question = %s",
                $question
            ));
            
            if (!$exists) {
                $result = $wpdb->insert($table_name, [
                    'question' => $question,
                    'answer' => $answer,
                    'keywords' => $keywords,
                    'category' => in_array($category, ['general', 'services', 'prices', 'contacts', 'schedule']) ? $category : 'general',
                    'priority' => max(1, min(10, $priority)),
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]);
                
                if ($result !== false) {
                    $imported++;
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>✅ Импорт завершен!</strong></p>';
        echo '<p>Добавлено записей: ' . $imported . '</p>';
        if ($skipped > 0) {
            echo '<p>Пропущено (дубликаты или ошибки): ' . $skipped . '</p>';
        }
        echo '</div>';
    }
    
    public function ajax_export_knowledge() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав!');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        
        $items = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id ASC", ARRAY_A);
        
        if (empty($items)) {
            wp_die('Нет данных для экспорта');
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cardanov_ai_knowledge_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Заголовки CSV
        fputcsv($output, ['question', 'answer', 'keywords', 'category', 'priority', 'is_active']);
        
        foreach ($items as $item) {
            fputcsv($output, [
                $item['question'],
                $item['answer'],
                $item['keywords'] ?? '',
                $item['category'] ?? 'general',
                $item['priority'] ?? 5,
                $item['is_active'] ?? 1
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    public function admin_settings_page() {
        // Получаем все страницы сайта
        $pages = get_pages([
            'post_type' => 'page',
            'post_status' => 'publish',
            'number' => 100,
            'sort_column' => 'post_title',
            'sort_order' => 'ASC'
        ]);
        
        // Получаем исключенные страницы (исправленная версия)
        $excluded_pages_option = get_option('cardanov_ai_excluded_pages', '');
        
        if (is_array($excluded_pages_option)) {
            $excluded_array = $excluded_pages_option;
        } else {
            $excluded_array = $excluded_pages_option ? explode(',', $excluded_pages_option) : [];
        }
        
        $excluded_array = array_filter(array_map('intval', $excluded_array));
        ?>
        <div class="wrap">
            <h1>⚙️ Настройки AI Agent</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('cardanov_ai_settings');
                do_settings_sections('cardanov_ai_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cardanov_ai_button_text">Текст кнопки:</label></th>
                        <td>
                            <input type="text" id="cardanov_ai_button_text" 
                                name="cardanov_ai_button_text" 
                                value="<?php echo esc_attr(get_option('cardanov_ai_button_text', 'Задать вопрос')); ?>" 
                                class="regular-text">
                            <p class="description">Текст на кнопке виджета</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="cardanov_ai_button_color">Цвет кнопки:</label></th>
                        <td>
                            <input type="color" id="cardanov_ai_button_color" 
                                name="cardanov_ai_button_color" 
                                value="<?php echo esc_attr(get_option('cardanov_ai_button_color', '#1a5fb4')); ?>">
                            <p class="description">Основной цвет виджета</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="cardanov_ai_welcome_message">Приветственное сообщение:</label></th>
                        <td>
                            <textarea id="cardanov_ai_welcome_message" 
                                    name="cardanov_ai_welcome_message" 
                                    rows="4" class="large-text"><?php 
                                echo esc_textarea(get_option('cardanov_ai_welcome_message', 'Здравствуйте! Я помощник компании Автотехногарант. Спросите меня о ремонте карданных валов, ценах, адресе или графике работы.'));
                            ?></textarea>
                            <p class="description">Сообщение при открытии чата</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Показывать агента на сайте:</th>
                        <td>
                            <?php 
                            $enabled = get_option('cardanov_ai_enabled', '1');
                            $checked = $enabled === '1' ? 'checked' : '';
                            ?>
                            <label>
                                <input type="checkbox" id="cardanov_ai_enabled" 
                                    name="cardanov_ai_enabled" value="1" <?php echo $checked; ?>>
                                Включить AI агента на сайте
                            </label>
                            <p class="description">Если выключено, виджет не будет показываться на сайте</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Исключить страницы:</th>
                        <td>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white;">
                                <?php if ($pages): ?>
                                    <?php foreach ($pages as $page): ?>
                                        <?php 
                                        $checked = in_array($page->ID, $excluded_array) ? 'checked' : '';
                                        $page_title = $page->post_title ?: '(Без названия)';
                                        ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="cardanov_ai_excluded_pages[]" 
                                                value="<?php echo $page->ID; ?>" <?php echo $checked; ?>>
                                            <?php echo esc_html($page_title); ?> 
                                            <small style="color: #666;">(ID: <?php echo $page->ID; ?>)</small>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>Нет созданных страниц</p>
                                <?php endif; ?>
                            </div>
                            <p class="description">На этих страницах AI агент не будет отображаться</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Сохранить настройки'); ?>
            </form>
        </div>
        <?php
    }
    
    public function display_widget() {
        // Проверяем, включен ли агент
        if (get_option('cardanov_ai_enabled', '1') !== '1') {
            return; // Не показываем если выключен
        }
        
        // Проверяем, исключена ли текущая страница
        $current_page_id = get_the_ID();
        if ($current_page_id) {
            $excluded_pages = get_option('cardanov_ai_excluded_pages', '');
            
            // ИСПРАВЛЕНИЕ: Правильная обработка массива
            if (is_array($excluded_pages)) {
                $excluded_array = $excluded_pages;
            } else {
                $excluded_array = $excluded_pages ? explode(',', $excluded_pages) : [];
            }
            
            $excluded_array = array_filter(array_map('intval', $excluded_array));
            
            if (in_array($current_page_id, $excluded_array)) {
                return; // Не показываем на исключенной странице
            }
        }
        
        $button_text = get_option('cardanov_ai_button_text', 'Задать вопрос');
        $button_color = get_option('cardanov_ai_button_color', '#1a5fb4');
        $welcome_message = get_option('cardanov_ai_welcome_message', 'Здравствуйте! Я помощник компании Автотехногарант. Спросите меня о ремонте карданных валов, ценах, адресе или графике работы.');
        
        // Создаем nonce для AJAX
        $ajax_nonce = wp_create_nonce('cardanov_ai_ask');
        ?>
        <div id="cardanov-ai-widget">
            <button id="cardanov-ai-toggle">
                🤖 <?php echo esc_html($button_text); ?>
            </button>
            
            <div id="cardanov-ai-container">
                <div class="cardanov-ai-header">
                    <span>🤖 Помощник Автотехногарант</span>
                    <button id="cardanov-ai-close">×</button>
                </div>
                
                <div class="cardanov-ai-messages">
                    <div class="ai-message bot"><?php echo esc_html($welcome_message); ?></div>
                </div>
                
                <div class="cardanov-ai-input-area">
                    <input type="text" id="cardanov-ai-input" placeholder="Введите ваш вопрос...">
                    <button id="cardanov-ai-send">Отправить</button>
                </div>
            </div>
        </div>
        
        <style>
        #cardanov-ai-widget {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        #cardanov-ai-toggle {
            background: <?php echo esc_attr($button_color); ?>;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transition: all 0.3s;
        }
        
        #cardanov-ai-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        
        #cardanov-ai-container {
            display: none;
            width: 350px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        
        .cardanov-ai-header {
            background: <?php echo esc_attr($button_color); ?>;
            color: white;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        
        #cardanov-ai-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        #cardanov-ai-close:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .cardanov-ai-messages {
            height: 300px;
            overflow-y: auto;
            padding: 16px;
            background: #fafafa;
        }
        
        .ai-message {
            padding: 12px 16px;
            border-radius: 18px;
            max-width: 85%;
            word-wrap: break-word;
            margin-bottom: 10px;
            animation: fadeIn 0.3s;
        }
        
        .ai-message.bot {
            background: white;
            border: 1px solid #e0e0e0;
            margin-right: auto;
        }
        
        .ai-message.user {
            background: <?php echo esc_attr($button_color); ?>;
            color: white;
            margin-left: auto;
        }
        
        .cardanov-ai-input-area {
            display: flex;
            padding: 12px;
            border-top: 1px solid #e0e0e0;
            background: white;
            gap: 8px;
        }
        
        #cardanov-ai-input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 14px;
            outline: none;
        }
        
        #cardanov-ai-input:focus {
            border-color: <?php echo esc_attr($button_color); ?>;
        }
        
        #cardanov-ai-send {
            background: <?php echo esc_attr($button_color); ?>;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            transition: opacity 0.2s;
        }
        
        #cardanov-ai-send:hover {
            opacity: 0.9;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .typing-indicator {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
        }
        
        .typing-indicator span {
            width: 8px;
            height: 8px;
            background: #ccc;
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }
        
        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-6px); }
        }
        
        @media (max-width: 480px) {
            #cardanov-ai-container {
                width: calc(100vw - 40px);
                right: 20px;
                left: 20px;
                bottom: 80px;
            }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var widget = $('#cardanov-ai-widget');
            var container = $('#cardanov-ai-container');
            var toggle = $('#cardanov-ai-toggle');
            var close = $('#cardanov-ai-close');
            var input = $('#cardanov-ai-input');
            var send = $('#cardanov-ai-send');
            var messages = $('.cardanov-ai-messages');
            
            // Nonce для AJAX запросов
            var ajax_nonce = '<?php echo esc_js($ajax_nonce); ?>';
            
            var isOpen = false;
            var isLoading = false;
            
            toggle.on('click', function() {
                isOpen = !isOpen;
                container.toggle();
                if (isOpen) input.focus();
            });
            
            close.on('click', function() {
                isOpen = false;
                container.hide();
            });
            
            function sendMessage() {
                var text = input.val().trim();
                if (!text || isLoading) return;
                
                addMessage(text, 'user');
                input.val('');
                isLoading = true;
                
                var typing = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
                messages.append(typing);
                scrollToBottom();
                
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'cardanov_ai_ask',
                    question: text,
                    nonce: ajax_nonce  // Добавляем nonce
                }, function(response) {
                    $('.typing-indicator').remove();
                    isLoading = false;
                    
                    if (response.success) {
                        addMessage(response.data.answer, 'bot');
                    } else {
                        addMessage('Ошибка: ' + (response.data?.message || 'неизвестная ошибка'), 'bot');
                    }
                }).fail(function() {
                    $('.typing-indicator').remove();
                    isLoading = false;
                    addMessage('Ошибка соединения. Позвоните нам: +7 991 690-79-49', 'bot');
                });
            }
            
            send.on('click', sendMessage);
            input.on('keypress', function(e) {
                if (e.key === 'Enter') sendMessage();
            });
            
            function addMessage(text, type) {
                var msg = $('<div class="ai-message ' + type + '">').text(text);
                messages.append(msg);
                scrollToBottom();
            }
            
            function scrollToBottom() {
                messages.scrollTop(messages[0].scrollHeight);
            }
        });
        </script>
        <?php
    }
    
    public function ajax_handler() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        $log_table = $wpdb->prefix . 'cardanov_ai_logs';
        
        // Проверка nonce (но не блокируем если его нет для обратной совместимости)
        $nonce = $_POST['nonce'] ?? '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'cardanov_ai_ask')) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }
        
        $question = sanitize_text_field($_POST['question'] ?? '');
        $start_time = microtime(true);
        
        if (empty($question)) {
            wp_send_json_error(['message' => 'Вопрос не может быть пустым']);
        }
        
        $log_result = $wpdb->insert($log_table, [
            'question' => $question,
            'answer_found' => 0,
            'response_time' => 0
        ]);
        
        $log_id = $log_result ? $wpdb->insert_id : 0;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            $fallback_answers = [
                'ремонт' => 'Мы специализируемся на ремонте карданных валов и рулевых тяг.',
                'адрес' => 'Наш адрес: Москва, Щербинка, ул. Космонавтов, 16А',
                'телефон' => 'Наш телефон: +7 991 690-79-49',
                'график' => 'Работаем ежедневно с 9:00 до 19:00',
                'цена' => 'Стоимость ремонта зависит от сложности. Точную цену можно узнать после диагностики.',
                'кардан' => 'Ремонт карданных валов любой сложности с гарантией.',
                'тяг' => 'Ремонт рулевых тяг с гарантией 6 месяцев.'
            ];
            
            $question_lower = strtolower($question);
            foreach ($fallback_answers as $key => $answer) {
                if (strpos($question_lower, $key) !== false) {
                    if ($log_id) {
                        $wpdb->update($log_table, 
                            ['answer_found' => 1, 'response_time' => microtime(true) - $start_time],
                            ['id' => $log_id]
                        );
                    }
                    
                    wp_send_json_success(['answer' => $answer]);
                }
            }
            
            if ($log_id) {
                $wpdb->update($log_table, 
                    ['answer_found' => 1, 'response_time' => microtime(true) - $start_time],
                    ['id' => $log_id]
                );
            }
            
            wp_send_json_success(['answer' => 'Здравствуйте! Я могу ответить на вопросы о ремонте, адресе, телефоне или графике работы.']);
        }
        
        $items = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1 ORDER BY priority DESC");
        
        if (empty($items)) {
            if ($log_id) {
                $wpdb->update($log_table, 
                    ['answer_found' => 0, 'response_time' => microtime(true) - $start_time],
                    ['id' => $log_id]
                );
            }
            
            wp_send_json_success(['answer' => 'База знаний настроена, но пуста. Добавьте вопросы в админке.']);
        }
        
        $best_match = null;
        $best_score = 0;
        $question_lower = strtolower($question);
        
        foreach ($items as $item) {
            $score = 0;
            
            if (!empty($item->keywords)) {
                $keywords = explode(',', $item->keywords);
                foreach ($keywords as $keyword) {
                    $keyword = trim(strtolower($keyword));
                    if (!empty($keyword) && strpos($question_lower, $keyword) !== false) {
                        $score += 3;
                    }
                }
            }
            
            if (strpos(strtolower($item->question), $question_lower) !== false) {
                $score += 5;
            }
            
            $score += ($item->priority * 0.1);
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $item;
            }
        }
        
        if ($best_match && $best_score >= 1) {
            if ($log_id) {
                $wpdb->update($log_table, 
                    ['answer_found' => 1, 'response_time' => microtime(true) - $start_time],
                    ['id' => $log_id]
                );
            }
            
            wp_send_json_success([
                'answer' => $best_match->answer,
                'score' => $best_score
            ]);
        } else {
            if ($log_id) {
                $wpdb->update($log_table, 
                    ['answer_found' => 0, 'response_time' => microtime(true) - $start_time],
                    ['id' => $log_id]
                );
            }
            
            $default_answers = [
                'Здравствуйте! Я могу ответить на вопросы о ремонте карданных валов, рулевых тяг, ценах, адресе и графике работы.',
                'Позвоните нам для подробной консультации: +7 991 690-79-49',
                'Задайте вопрос более конкретно, например: "Сколько стоит ремонт?" или "Какой ваш адрес?"'
            ];
            
            $random_answer = $default_answers[array_rand($default_answers)];
            
            wp_send_json_success([
                'answer' => $random_answer,
                'score' => 0
            ]);
        }
    }
    
    public function ajax_check_table() {
        check_ajax_referer('cardanov_ai_admin', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_exists) {
            $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $active = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_active = 1");
            
            wp_send_json_success([
                'table_exists' => true,
                'total' => $total,
                'active' => $active,
                'message' => "✅ Таблица существует\nВсего записей: $total\nАктивных: $active"
            ]);
        } else {
            wp_send_json_success([
                'table_exists' => false,
                'message' => '❌ Таблица не существует'
            ]);
        }
    }
    
    public function ajax_create_table() {
        check_ajax_referer('cardanov_ai_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав!');
        }
        
        $this->create_table();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_knowledge';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_exists) {
            wp_send_json_success([
                'success' => true,
                'message' => 'Таблица успешно создана!'
            ]);
        } else {
            wp_send_json_error([
                'success' => false,
                'message' => 'Не удалось создать таблицу'
            ]);
        }
    }
    
    public function ajax_export_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав!');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_logs';
        
        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC", ARRAY_A);
        
        if (empty($logs)) {
            wp_die('Нет данных для экспорта');
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cardanov_ai_logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['ID', 'Вопрос', 'Ответ найден', 'Время ответа (сек)', 'Дата и время']);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['question'],
                $log['answer_found'] ? 'Да' : 'Нет',
                number_format($log['response_time'], 2),
                $log['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    public function ajax_clear_logs() {
        check_ajax_referer('cardanov_ai_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав!');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cardanov_ai_logs';
        
        $result = $wpdb->query("
            DELETE FROM $table_name 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        if ($result !== false) {
            wp_send_json_success([
                'message' => 'Удалено ' . $result . ' записей старше 30 дней'
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Ошибка при удалении логов'
            ]);
        }
    }
    
    public function ajax_force_create() {
        check_ajax_referer('cardanov_ai_force_create', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав!']);
        }
        
        $result = $this->force_create_tables();
        
        wp_send_json_success([
            'knowledge' => $result['knowledge'],
            'logs' => $result['logs'],
            'message' => 'Таблицы обновлены'
        ]);
    }
    
    public function register_settings() {
        register_setting('cardanov_ai_settings', 'cardanov_ai_button_text', 'sanitize_text_field');
        register_setting('cardanov_ai_settings', 'cardanov_ai_button_color', 'sanitize_hex_color');
        register_setting('cardanov_ai_settings', 'cardanov_ai_welcome_message', 'sanitize_textarea_field');
        register_setting('cardanov_ai_settings', 'cardanov_ai_enabled', array(
            'type' => 'string',
            'sanitize_callback' => function($value) {
                return $value === '1' ? '1' : '0';
            },
            'default' => '1'
        ));
        
        // Для массива исключенных страниц
        register_setting('cardanov_ai_settings', 'cardanov_ai_excluded_pages', array(
            'type' => 'array',
            'sanitize_callback' => function($value) {
                if (empty($value)) return array();
                if (is_array($value)) {
                    return array_map('intval', $value);
                }
                // Для обратной совместимости со старыми данными
                $pages = explode(',', $value);
                return array_filter(array_map('intval', $pages));
            },
            'default' => array()
        ));
    }
    
    public function register_elementor_widget($widgets_manager) {
        $widget_file = CARDANOV_AI_PATH . 'elementor-widget.php';
        
        if (!file_exists($widget_file)) {
            error_log('Cardanov AI: Файл elementor-widget.php не найден по пути: ' . $widget_file);
            return;
        }
        
        include_once $widget_file;
        
        return;
    }
}

function cardanov_ai_agent_init() {
    return CardanovAIAgent::instance();
}

add_action('plugins_loaded', 'cardanov_ai_agent_init');