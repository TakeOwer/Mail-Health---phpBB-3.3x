<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth;

class ext extends \phpbb\extension\base
{
	/**
	 * Refuse to enable on unsupported environments.
	 * Note: the IMAP extension is NOT required to install, only to run the
	 * fetcher. The ACP shows a warning when it is missing.
	 */
	public function is_enableable()
	{
		$config = $this->container->get('config');

		return phpbb_version_compare($config['version'], '3.3.0', '>=')
			&& phpbb_version_compare($config['version'], '4.0.0-dev', '<')
			&& version_compare(PHP_VERSION, '7.2.0', '>=');
	}
}
