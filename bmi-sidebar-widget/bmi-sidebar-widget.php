<?php
/**
 * Plugin Name: BMI Sidebar Widget
 * Description: Shows your weight, BMI, and obesity class in a sidebar widget or via shortcode.
 * Version: 1.3.0
 * Author: Frop Core Labs
 * Author URI: https://fropcore.github.io/
 * License: GPL2+
 */

if (!defined('ABSPATH')) { exit; }

class BMI_Widget_Plugin {

    const OPTION = 'bmi_widget_options';

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('widgets_init', [$this, 'register_widget']);
        add_shortcode('bmi_widget', [$this, 'shortcode']);
    }

    public function register_settings() {
        register_setting(self::OPTION, self::OPTION, [$this, 'sanitize']);

        add_settings_section('bmi_main', 'BMI Settings', function () {
            echo '<p>Enter your measurements. The plugin will compute BMI and obesity class.</p>';
        }, self::OPTION);

        $fields = [
            ['weight', 'Weight', 'number'],
            ['weight_unit', 'Weight Unit', 'select', ['kg' => 'kg', 'lb' => 'lb']],
            ['height', 'Height', 'number'],
            ['height_unit', 'Height Unit', 'select', ['cm' => 'cm', 'in' => 'in']],
            ['show_updated', 'Show “Last Updated”', 'checkbox'],
            ['custom_label', 'Custom Label (optional)', 'text'],
        ];

        foreach ($fields as $f) {
            add_settings_field($f[0], $f[1], [$this, 'render_field'], self::OPTION, 'bmi_main', $f);
        }
    }

    public function add_settings_page() {
        add_options_page('BMI Widget', 'BMI Widget', 'manage_options', self::OPTION, [$this, 'settings_page']);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>BMI Widget</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION);
                do_settings_sections(self::OPTION);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_field($args) {
        $opts = get_option(self::OPTION, []);
        $key = $args[0];
        $type = $args[2];

        $val = isset($opts[$key]) ? $opts[$key] : '';

        if ($type === 'number') {
            printf('<input type="number" step="0.01" min="0" name="%s[%s]" value="%s" class="regular-text" />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($val));
        } elseif ($type === 'text') {
            printf('<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($val));
        } elseif ($type === 'select') {
            $choices = $args[3];
            printf('<select name="%s[%s]">', esc_attr(self::OPTION), esc_attr($key));
            foreach ($choices as $k => $label) {
                printf('<option value="%s"%s>%s</option>',
                    esc_attr($k),
                    selected($val, $k, false),
                    esc_html($label)
                );
            }
            echo '</select>';
        } elseif ($type === 'checkbox') {
            printf('<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
                esc_attr(self::OPTION), esc_attr($key), checked($val, '1', false), 'Enable');
        }
    }

    public function sanitize($input) {
        $out = [];
        $out['weight'] = isset($input['weight']) ? floatval($input['weight']) : 0;
        $out['weight_unit'] = in_array($input['weight_unit'] ?? 'kg', ['kg','lb'], true) ? $input['weight_unit'] : 'kg';
        $out['height'] = isset($input['height']) ? floatval($input['height']) : 0;
        $out['height_unit'] = in_array($input['height_unit'] ?? 'cm', ['cm','in'], true) ? $input['height_unit'] : 'cm';
        $out['show_updated'] = !empty($input['show_updated']) ? '1' : '0';
        $out['custom_label'] = isset($input['custom_label']) ? sanitize_text_field($input['custom_label']) : '';
        $out['updated_at'] = current_time('mysql');
        return $out;
    }

    public function register_widget() {
        register_widget('bmi_Widget');
    }

    public static function compute($opts) {
        $weight = floatval($opts['weight'] ?? 0);
        $wUnit  = $opts['weight_unit'] ?? 'kg';
        $height = floatval($opts['height'] ?? 0);
        $hUnit  = $opts['height_unit'] ?? 'cm';

        if ($weight <= 0 || $height <= 0) {
            return [null, null, null, null]; // invalid
        }

        // Convert to metric for BMI
        $kg = ($wUnit === 'lb') ? $weight * 0.45359237 : $weight;
        $m  = ($hUnit === 'in') ? $height * 0.0254 : ($height / 100);

        $bmi = $kg / ($m * $m);
        $bmi = round($bmi, 1);

        // classes
        if ($bmi < 18.5)       { $class = 'Underweight'; }
        elseif ($bmi < 25.0)   { $class = 'Normal weight'; }
        elseif ($bmi < 30.0)   { $class = 'Overweight'; }
        elseif ($bmi < 35.0)   { $class = 'Obesity class I'; }
        elseif ($bmi < 40.0)   { $class = 'Obesity class II'; }
        else                   { $class = 'Obesity class III'; }

        // Display weight using chosen unit, also include metric/imperial counterpart
        $displayWeight = ($wUnit === 'lb')
            ? sprintf('%s lb (%.1f kg)', number_format_i18n($weight, 1), $kg)
            : sprintf('%.1f kg (%.1f lb)', $kg, $kg / 0.45359237);

        return [$bmi, $class, $displayWeight, $opts['updated_at'] ?? null];
    }

    public function shortcode($atts) {
        // Shortcode handler for [bmi_widget]
        $opts = get_option(self::OPTION, []);
        return bmi_Widget::render_output($opts, []);
    }
}

class bmi_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'bmi_widget',
            'BMI/Weight Display',
            ['description' => 'Shows your weight, BMI, and obesity class.']
        );
    }

    public function widget($args, $instance) {
        $opts = get_option(BMI_Widget_Plugin::OPTION, []);
        echo $args['before_widget'];
        if (!empty($opts['custom_label'])) {
            echo $args['before_title'] . esc_html($opts['custom_label']) . $args['after_title'];
        } else {
            echo $args['before_title'] . 'My Weight' . $args['after_title'];
        }
        echo self::render_output($opts, $instance);
        echo $args['after_widget'];
    }

    public static function render_output($opts, $instance) {
        list($bmi, $class, $displayWeight, $updatedAt) = BMI_Widget_Plugin::compute($opts);

        if ($bmi === null) {
            return '<p>Please configure your measurements in <em>Settings → BMI Widget</em>.</p>';
        }

        $showUpdated = !empty($opts['show_updated']);
        ob_start();
        ?>
        <div class="bmi-widget">
            <p><strong>Weight:</strong> <?php echo esc_html($displayWeight); ?></p>
            <p><strong>BMI:</strong> <?php echo esc_html(number_format_i18n($bmi, 1)); ?>
                <?php if ($class): ?> (<?php echo esc_html($class); ?>)<?php endif; ?>
            </p>
            <?php if ($showUpdated && $updatedAt): ?>
                <p><em>Last updated: <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $updatedAt)); ?></em></p>
            <?php endif; ?>
        </div>
        <style>
            .bmi-widget { line-height:1.5; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function form($instance) {
        echo '<p>Uses values from <strong>Settings → BMI Widget</strong>. No per-widget options.</p>';
    }

    public function update($new_instance, $old_instance) {
        return $old_instance;
    }
}

new BMI_Widget_Plugin();