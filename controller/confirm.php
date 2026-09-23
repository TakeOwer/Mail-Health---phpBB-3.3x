<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\controller;

/**
 * Lands here when the member clicks the link in the verification e-mail.
 * Reaching this page at all proves the new address receives mail.
 */
class confirm
{
	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \salvocortesiano\mailhealth\service\manager */
	protected $manager;

	public function __construct(
		\phpbb\controller\helper $helper,
		\phpbb\language\language $language,
		\salvocortesiano\mailhealth\service\manager $manager
	)
	{
		$this->helper = $helper;
		$this->language = $language;
		$this->manager = $manager;
	}

	/**
	 * @param int    $user_id
	 * @param string $token
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle($user_id, $token)
	{
		$this->language->add_lang('common', 'salvocortesiano/mailhealth');

		$confirmed = $this->manager->confirm_token((int) $user_id, (string) $token);

		if ($confirmed)
		{
			return $this->helper->message('MAILHEALTH_VERIFY_DONE', [], 'MAILHEALTH_VERIFY_TITLE');
		}

		return $this->helper->message('MAILHEALTH_VERIFY_FAILED', [], 'MAILHEALTH_VERIFY_TITLE');
	}
}
