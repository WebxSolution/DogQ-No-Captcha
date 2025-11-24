<?php

namespace Joomla\Plugin\System\Dogqnocaptcha\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Document\HtmlDocument as JDocumentHtml;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

class Dogqnocaptcha extends CMSPlugin
{
    protected string $tokenReceived = '';
    protected string $tokenExpected = '';
    protected bool   $tokenReady    = false;
    protected string $paramName     = 'xf3p';
    protected bool   $checkUa       = false;

    /** @var bool Internally track whether we disabled RSFormPro's Recaptcha field */
    protected bool $recaptchaDisabled = false;

    /**
     * onAfterRoute – compute tokens, check UA, and temporarily unpublish RSFormPro Recaptcha field
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        $this->computeTokens();

        // Collect UA patterns
        $uaList  = (array) $this->params->get('ua_list', []);
        $patterns = [];
        foreach ($uaList as $uaRow) {
            if (!empty($uaRow->useragent)) {
                $patterns[] = trim($uaRow->useragent);
            }
        }

        $agent   = $app->input->server->getString('HTTP_USER_AGENT', '');
        $checkUa = empty($patterns); // if no patterns, skip UA check

        if (!$checkUa) {
            foreach ($patterns as $pattern) {
                if ($pattern && stripos($agent, $pattern) !== false) {
                    $checkUa = true;
                    break;
                }
            }
        }

        // Apply final decision
        $this->checkUa = $checkUa;

        // --- IMPORTANT ---
        // If UA is allowed AND token matches, we disable the RSFormPro Recaptcha field in DB.
        // This prevents RSFormPro from validating recaptcha on form submit.
        // We'll restore (republish) it in onAfterRender.
        $formId = $app->input->getInt('formId', 0);

		// Fallback: If RSForm is embedded in an article ({rsform X})
		if ($formId == 0) {
			$formId = $this->detectFormIdFromArticle();
		}
		
		
		$option = $app->input->get('option',''); 
        if (in_array($option, ['com_rsform','com_content']) && $formId > 0 && $this->tokenReceived === $this->tokenExpected && $this->checkUa) {
            $this->disableRecaptchaField($formId);
            $this->recaptchaDisabled = true;
        }
		else if(in_array($option, ['com_rsform','com_content'])  && $formId > 0)
		{			
			// Downside: we always re-enable the Rscaptcha field even if it was legitimately
			// disabled on a form.
			// There is also a small possibility that while the DogQ test is running,
			// someone could be submitting a real form at the same time,
			// which could break the submission during our automated test.
			$this->enableRecaptchaField($formId);
		}
    }

	/**
     * onBeforeCompileHead – remove Recaptcha JS from head (only if token & UA are valid)
     */
    public function onBeforeCompileHead(): void
    {
        if (!$this->tokenReady) {
            $this->computeTokens();
        }

        if ($this->tokenReceived !== $this->tokenExpected || !$this->checkUa) {
            return;
        }

        $doc = Factory::getApplication()->getDocument();
        if (!($doc instanceof JDocumentHtml)) {
            return;
        }

        $headData = $doc->getHeadData();

        // Remove external recaptcha scripts
        if (!empty($headData['scripts'])) {
            foreach ($headData['scripts'] as $script => $attrs) {
                if (
                    str_contains($script, '/plg_system_rsfprecaptchav3/js/script.js') ||
                    str_contains($script, 'www.google.com/recaptcha') ||
                    str_contains($script, 'www.recaptcha.net/recaptcha') ||
                    str_contains($script, 'www.gstatic.com/recaptcha')
                ) {
                    unset($headData['scripts'][$script]);
                }
            }
        }

        // Remove inline Recaptcha snippets
        if (!empty($headData['script']) && is_array($headData['script'])) {
            foreach ($headData['script'] as $mime => $scripts) {
                if (!is_array($scripts)) continue;
                foreach ($scripts as $hash => $scriptCode) {
                    if (is_string($scriptCode)) {
                        if (
                            str_contains($scriptCode, 'grecaptcha') ||
                            str_contains($scriptCode, 'recaptcha') ||
                            str_contains($scriptCode, 'RSFormProReCAPTCHAv3')
                        ) {
                            unset($headData['script'][$mime][$hash]);
                        }
                    }
                }
                if (empty($headData['script'][$mime])) {
                    unset($headData['script'][$mime]);
                }
            }
        }

        $doc->setHeadData($headData);
    }

	/**
     * onRsformFrontendInitFormDisplay – remove template placeholders for recaptcha
     */
    public function onRsformFrontendInitFormDisplay($args)
    {
        if (!empty($args['formLayout']) && is_string($args['formLayout'])) {
            $args['formLayout'] = preg_replace(
                '/\{recp?aptcha:[^}]+}/i', //Have to account for recaptcha Typo in RSform plugin hence the [p?]
                '',
                $args['formLayout']
            );
        }
    }

   
    /**
     * Disable RSFormPro Recaptcha field for this form.
     */
	protected function disableRecaptchaField(int $formId): void
	{
		$db = Factory::getDbo();

		// Always use names, not IDs
		$typeNames = ['recaptchav3', 'recaptchav2'];

		foreach ($typeNames as $typeName) {

			$componentIds = $this->getRsformComponentIds($formId, $typeName, null);

			if (empty($componentIds)) {
				continue;
			}

			foreach ($componentIds as $cid) {
				try {
					$query = $db->getQuery(true)
						->update($db->quoteName('#__rsform_components'))
						->set($db->quoteName('published') . ' = 0')
						->where($db->quoteName('formId') . ' = :formid')
						->where($db->quoteName('ComponentId') . ' = :cid');

					$query->bind(':formid', $formId, ParameterType::INTEGER);
					$query->bind(':cid', $cid, ParameterType::INTEGER);

					$db->setQuery($query)->execute();

				} catch (\Throwable $e) {
					// silent fail
				}
			}
		}
	}


    /**
     * Re-enable RSFormPro Recaptcha field for this form.
     */
	protected function enableRecaptchaField(int $formId): void
	{
		$db = Factory::getDbo();

		// Use names
		$typeNames = ['recaptchav3', 'recaptchav2'];

		foreach ($typeNames as $typeName) {

			$componentIds = $this->getRsformComponentIds($formId, $typeName, 0);

			if (empty($componentIds)) {
				continue;
			}

			foreach ($componentIds as $cid) {
				try {
					$query = $db->getQuery(true)
						->update($db->quoteName('#__rsform_components'))
						->set($db->quoteName('published') . ' = 1')
						->where($db->quoteName('formId') . ' = :formid')
						->where($db->quoteName('ComponentId') . ' = :cid');

					$query->bind(':formid', $formId, ParameterType::INTEGER);
					$query->bind(':cid', $cid, ParameterType::INTEGER);

					$db->setQuery($query)->execute();

				} catch (\Throwable $e) {
					// silent fail
				}
			}
		}
	}


    /**
     * Compute tokens from request + plugin params
     */
    protected function computeTokens(): void
    {
        if ($this->tokenReady) {
            return;
        }

        $input = Factory::getApplication()->input;
        $expectedParam       = $this->params->get($this->paramName, '');
        $this->tokenReceived = $input->getString($this->paramName, '');
        $this->tokenExpected = $expectedParam;
        $this->tokenReady    = true;
    }
	
	
	/**
	 * Fallback: Extract RSForm ID from article content (introtext + fulltext)
	 */
	protected function detectFormIdFromArticle(): int
	{
		$app = Factory::getApplication();
		$option = $app->input->getCmd('option', '');
		$view   = $app->input->getCmd('view', '');
		$id     = $app->input->getInt('id', 0);

		// We only handle com_content articles
		if ($option !== 'com_content' || $view !== 'article' || $id <= 0) {
			return 0;
		}

		try {
			$db = Factory::getDbo();
			$query = $db->getQuery(true)
				->select($db->quoteName(['introtext','fulltext']))
				->from($db->quoteName('#__content'))
				->where($db->quoteName('id') . ' = :id');
			$query->bind(':id', $id, ParameterType::INTEGER);

			$article = $db->setQuery($query)->loadObject();

			if (!$article) {
				return 0;
			}

			$text = $article->introtext . "\n" . $article->fulltext;

			// Works for: {rsform 3} OR {rsform 12} etc.
			if (preg_match('/\{rsform\s+(\d+)\}/i', $text, $m)) {
				return (int) $m[1];
			}
		}
		catch (\Throwable $e) {
			// Silent fail
		}

		return 0;
	}
	
	/**
	 * Returns an array of ComponentId values for components of a given type on a form.
	 *
	 * @param  int    $formId
	 * @param  string $typeName  e.g. "recaptchav3" or "recaptchav2"
	 * @param  int|null $published (1, 0, null) — null = ignore publish state
	 * @return int[]
	 */
	protected function getRsformComponentIds(int $formId, string $typeName, ?int $published = null): array
	{
		try {
			$db = \Joomla\CMS\Factory::getDbo();

			// FIRST: Get the ComponentTypeId for the component type name
			$query = $db->getQuery(true)
				->select($db->quoteName('ComponentTypeId'))
				->from($db->quoteName('#__rsform_component_types'))
				->where($db->quoteName('ComponentTypeName') . ' = :ctype');

			$query->bind(':ctype', $typeName, \Joomla\Database\ParameterType::STRING);

			$typeId = $db->setQuery($query)->loadResult();

			if (!$typeId) {
				return [];
			}

			// SECOND: Find components belonging to this form
			$query = $db->getQuery(true)
				->select($db->quoteName('ComponentId'))
				->from($db->quoteName('#__rsform_components'))
				->where($db->quoteName('formId') . ' = :fid')
				->where($db->quoteName('ComponentTypeId') . ' = :tid');

			$query->bind(':fid', $formId, \Joomla\Database\ParameterType::INTEGER);
			$query->bind(':tid', $typeId, \Joomla\Database\ParameterType::INTEGER);

			if ($published !== null) {
				$query->where($db->quoteName('published') . ' = :pub');
				$query->bind(':pub', $published, \Joomla\Database\ParameterType::INTEGER);
			}

			return $db->setQuery($query)->loadColumn() ?: [];
		}
		catch (\Throwable $e) {
			return [];
		}
	}	
	
}
