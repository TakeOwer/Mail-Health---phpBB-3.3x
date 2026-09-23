<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var \phpbb\template\template */
	protected $template;

	/** @var \salvocortesiano\mailhealth\service\manager */
	protected $manager;

	public function __construct(
		\phpbb\template\template $template,
		\salvocortesiano\mailhealth\service\manager $manager
	)
	{
		$this->template = $template;
		$this->manager = $manager;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup'	=> 'load_language',
			'core.page_header'	=> 'show_notice',
		];
	}

	public function load_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = [
			'ext_name' => 'salvocortesiano/mailhealth',
			'lang_set' => 'common',
		];
		$event['lang_set_ext'] = $lang_set_ext;
	}

	/**
	 * Tells the member his address is bouncing, with a link to fix it.
	 * This is the piece that keeps support requests down: an account that
	 * silently stops receiving mail generates far more questions than a
	 * clear notice does.
	 */
	public function show_notice($event)
	{
		if (!$this->manager->current_user_has_problem())
		{
			return;
		}

		$this->template->assign_vars([
			'S_MAILHEALTH_NOTICE'	=> true,
			'U_MAILHEALTH_FIX'		=> append_sid(generate_board_url() . '/ucp.' . $GLOBALS['phpEx'], 'i=ucp_profile&amp;mode=reg_details'),
		]);
	}
}
