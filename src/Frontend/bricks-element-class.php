<?php

declare(strict_types=1);

namespace EasyBusyConnect\Frontend;

if (!class_exists('\Bricks\Element')) {
    return;
}

/**
 * Bricks element wrapper. Kept in its own file because Bricks requires a file
 * path when registering an element, and the class must not be parsed when the
 * theme is absent.
 */
class BricksBookingElement extends \Bricks\Element
{
    public $category = 'easybusy';
    public $name = 'easybusy-booking';
    public $icon = 'ti-calendar';

    public function get_label(): string
    {
        return esc_html__('EasyBusy booking form', 'easybusy-connect');
    }

    public function set_controls(): void
    {
        $this->controls['title'] = [
            'tab'   => 'content',
            'label' => esc_html__('Heading', 'easybusy-connect'),
            'type'  => 'text',
        ];
        $this->controls['service'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Preselected service id', 'easybusy-connect'),
            'type'        => 'number',
            'description' => esc_html__('Optional. EasyBusy serviceId to start from.', 'easybusy-connect'),
        ];
        $this->controls['doctor'] = [
            'tab'   => 'content',
            'label' => esc_html__('Preselected doctor id', 'easybusy-connect'),
            'type'  => 'number',
        ];
    }

    public function render(): void
    {
        echo "<div {$this->render_attributes('_root')}>";
        echo BricksElement::markup(
            (string) ($this->settings['service'] ?? ''),
            (string) ($this->settings['doctor'] ?? ''),
            (string) ($this->settings['title'] ?? '')
        );
        echo '</div>';
    }
}
