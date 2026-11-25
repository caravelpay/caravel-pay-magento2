<?php
declare(strict_types=1);

namespace Caravel\Pay\Block\Adminhtml\System\Config;

use Caravel\Pay\Model\Service;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** The "Connection" line in Stores › Configuration › Sales › Payment Methods › Caravel Pay. */
class Status extends Field
{
    /** @var Service */
    private $service;

    public function __construct(Context $context, Service $service, array $data = [])
    {
        parent::__construct($context, $data);
        $this->service = $service;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->statusHtml();
    }

    public function statusHtml(): string
    {
        $view = $this->service->connection()->statusView();
        $messages = $this->service->messages();
        $colors = ['connected' => '#185b00', 'not_connected' => '#e22626', 'paused' => '#9c6300', 'pending_review' => '#9c6300'];
        $html = '<p><strong style="color:' . ($colors[$view['state']] ?? '#303030') . '">' . $this->escapeHtml($messages->render($view['label'])) . '</strong> '
            . $this->escapeHtml($messages->pair($view['detail'])) . '</p>';
        $hint = $messages->pair($view['hint']);
        if ('' !== $hint) {
            $html .= '<p class="note">' . $this->escapeHtml($hint) . '</p>';
        }
        $html .= '<p class="note">' . $this->escapeHtml(__('Webhook URL: %1', $this->service->callbackUrl())) . '</p>';
        return $html;
    }
}
