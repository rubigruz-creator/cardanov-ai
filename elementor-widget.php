<?php
// Elementor Widget для Cardanov AI Agent
if (!defined('ABSPATH')) exit;

class CardanovAI_Elementor_Widget extends \Elementor\Widget_Base {
    
    public function get_name() {
        return 'cardanov_ai';
    }
    
    public function get_title() {
        return 'Cardanov AI';
    }
    
    public function get_icon() {
        return 'eicon-robot';
    }
    
    public function get_categories() {
        return ['general'];
    }
    
    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <div class="cardanov-ai-elementor-widget">
            <button class="cardanov-ai-elementor-btn" 
                    onclick="if(typeof jQuery!=='undefined')jQuery('#cardanov-ai-toggle').click();else alert('Откройте виджет в правом нижнем углу');">
                🤖 Задать вопрос AI
            </button>
            
            <style>
            .cardanov-ai-elementor-btn {
                background: #1a5fb4;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 25px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 500;
                transition: all 0.3s;
            }
            .cardanov-ai-elementor-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            }
            </style>
        </div>
        <?php
    }
}

// Регистрация виджета
add_action('elementor/widgets/register', function($widgets_manager) {
    $widgets_manager->register(new CardanovAI_Elementor_Widget());
});