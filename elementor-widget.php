<?php
// elementor-widget.php - МИНИМАЛЬНАЯ РАБОЧАЯ ВЕРСИЯ
if (!defined('ABSPATH')) exit;

// Класс виджета
class CardanovAI_Elementor_Widget extends \Elementor\Widget_Base {
    
    public function get_name() { return 'cardanov_ai'; }
    public function get_title() { return 'Cardanov AI'; }
    public function get_icon() { return 'eicon-robot'; }
    public function get_categories() { return ['basic']; }
    
    protected function render() {
        $button_text = get_option('cardanov_ai_button_text', '🤖 Задать вопрос AI');
        $button_color = get_option('cardanov_ai_button_color', '#1a5fb4');
        ?>
        <button class="cardanov-ai-elementor-btn" 
                style="background:<?php echo $button_color; ?>;color:white;padding:12px 24px;border-radius:25px;border:none;cursor:pointer;font-size:16px;"
                onclick="if(typeof jQuery!=='undefined')jQuery('#cardanov-ai-toggle').click();else alert('Откройте виджет в правом нижнем углу');">
            <?php echo esc_html($button_text); ?>
        </button>
        <?php
    }
}


// Регистрация - УНИВЕРСАЛЬНЫЙ СПОСОБ
add_action('elementor/widgets/widgets_registered', function() {
    \Elementor\Plugin::instance()->widgets_manager->register_widget_type(new CardanovAI_Elementor_Widget());
});