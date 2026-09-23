<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\service;

/**
 * Bridge towards salvocortesiano/newsletter.
 *
 * Mail Health never reaches into the other extension: it only reports whether
 * it is there and offers the filtering it needs. The call that actually uses
 * this lives on the Newsletter side, guarded by a container check, so either
 * extension keeps working on its own.
 */
class integration
{
	const NEWSLETTER = 'salvocortesiano/newsletter';

	const STATE_ENABLED		= 'enabled';
	const STATE_DISABLED	= 'disabled';
	const STATE_MISSING		= 'missing';

	/** @var \phpbb\extension\manager */
	protected $ext_manager;

	/** @var manager */
	protected $manager;

	public function __construct(\phpbb\extension\manager $ext_manager, manager $manager)
	{
		$this->ext_manager = $ext_manager;
		$this->manager = $manager;
	}

	/**
	 * @param string $name
	 * @return string One of the STATE_* constants
	 */
	public function get_state($name = self::NEWSLETTER)
	{
		if ($this->ext_manager->is_enabled($name))
		{
			return self::STATE_ENABLED;
		}

		// is_available() is true for anything present on disk, enabled or not.
		if ($this->ext_manager->is_available($name))
		{
			return self::STATE_DISABLED;
		}

		return self::STATE_MISSING;
	}

	public function is_newsletter_active()
	{
		return $this->get_state() === self::STATE_ENABLED;
	}

	/**
	 * Removes every suppressed address from a recipient list.
	 *
	 * Accepts either a flat list of addresses or a list of rows containing
	 * one, which is what a newsletter queue usually looks like.
	 *
	 * @param array  $recipients
	 * @param string $key Field holding the address when rows are passed
	 * @return array The same structure, minus the blocked entries. Keys are
	 *               preserved so the caller can keep its own indexing.
	 */
	public function filter_recipients(array $recipients, $key = '')
	{
		$blocked = array_flip($this->manager->get_suppression_list());

		if (empty($blocked))
		{
			return $recipients;
		}

		foreach ($recipients as $index => $recipient)
		{
			$email = ($key !== '' && is_array($recipient)) ? ($recipient[$key] ?? '') : $recipient;

			if (!is_string($email))
			{
				continue;
			}

			if (isset($blocked[strtolower(trim($email))]))
			{
				unset($recipients[$index]);
			}
		}

		return $recipients;
	}

	/**
	 * Every suppressed address, lower case. Meant for callers that filter in
	 * SQL rather than on an array - the Newsletter queue, for instance, is
	 * streamed from the database and never held in memory as a whole.
	 *
	 * @return array
	 */
	public function get_blocked_emails()
	{
		return $this->manager->get_suppression_list();
	}

	/**
	 * How many addresses a send would skip right now. Useful on the
	 * Newsletter side to tell the administrator before pressing send.
	 *
	 * @return int
	 */
	public function count_blocked()
	{
		return count($this->manager->get_suppression_list());
	}
}
