<?php

namespace Joomla\Plugin\System\Dogqnocaptcha\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

class Dogqnocaptcha extends CMSPlugin
{
    public function onBeforeCompileHead()
    {
        $this->removeRecaptchaJs();
    }

    public function onRsformFrontendBeforeShowForm($formId, &$form)
    {
        $this->removeCaptchaComponents($form);
    }

    protected function removeRecaptchaJs(): void
    {
        $input = Factory::getApplication()->input;
        $agent = $input->server->getString('HTTP_USER_AGENT', '');
        $flowReceived = $input->getString('flow_id', '');
        $flowExpected = $this->params->get('flow_id', '');

        if ($flowExpected && $flowReceived === $flowExpected) {

            $patterns = [];
            $uaList = $this->params->get('ua_list', []);
            if (is_array($uaList)) {
                foreach ($uaList as $item) {
                    if (!empty($item['useragent'])) {
                        $patterns[] = trim($item['useragent']);
                    }
                }
            }

            // If UA list is empty, skip pattern check (flow_id alone is enough)
            $checkUa = empty($patterns) || false;
            foreach ($patterns as $pattern) {
                if ($pattern && stripos($agent, $pattern) !== false) {
                    $checkUa = true;
                    break;
                }
            }

            if ($checkUa) {
                $doc = Factory::getApplication()->getDocument();

                if ($doc instanceof \JDocumentHtml) {
                    $headData = $doc->getHeadData();

                    if (!empty($headData['scripts'])) {
                        foreach ($headData['scripts'] as $script => $attrs) {
                            if (str_contains($script, 'www.google.com/recaptcha') || str_contains($script, 'www.recaptcha.net/recaptcha')) {
                                unset($headData['scripts'][$script]);
                            }
                        }

                        $doc->setHeadData($headData);
                    }
                }
            }
        }
    }

    protected function removeCaptchaComponents(&$form): void
    {
        $input = Factory::getApplication()->input;
        $agent = $input->server->getString('HTTP_USER_AGENT', '');
        $flowReceived = $input->getString('flow_id', '');
        $flowExpected = $this->params->get('flow_id', '');

        if ($flowExpected && $flowReceived === $flowExpected) {

            $patterns = [];
            $uaList = $this->params->get('ua_list', []);
            if (is_array($uaList)) {
                foreach ($uaList as $item) {
                    if (!empty($item['useragent'])) {
                        $patterns[] = trim($item['useragent']);
                    }
                }
            }

            $checkUa = empty($patterns) || false;
            foreach ($patterns as $pattern) {
                if ($pattern && stripos($agent, $pattern) !== false) {
                    $checkUa = true;
                    break;
                }
            }

            if ($checkUa) {
                if (!empty($form->Components)) {
                    foreach ($form->Components as $id => $component) {
                        if (in_array($component->ComponentTypeId, RSFormProHelper::$captchaFields)) {
                            unset($form->Components[$id]);
                        }
                    }
                }

                $form->RemoveCaptchaLogged = true;
            }
        }
    }
}
