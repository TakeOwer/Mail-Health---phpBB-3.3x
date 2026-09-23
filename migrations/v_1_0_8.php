<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\mailhealth\migrations;

/**
 * The yes/no SSL switch becomes a three-way choice: none, SSL/TLS, STARTTLS.
 */
class v_1_0_8 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['mailhealth_imap_security']);
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\mailhealth\migrations\v_1_0_7'];
	}

	public function update_data()
	{
		return [
			// 0 = none, 1 = SSL/TLS, 2 = STARTTLS
			['config.add', ['mailhealth_imap_security', 1]],
			['custom', [[$this, 'convert_security']]],
			['config.remove', ['mailhealth_imap_ssl']],
			['config.update', ['mailhealth_version', '1.0.8']],
		];
	}

	public function revert_data()
	{
		return [
			['config.add', ['mailhealth_imap_ssl', 1]],
			['config.remove', ['mailhealth_imap_security']],
		];
	}

	/**
	 * "SSL: yes" on port 143 can never work - that port speaks plain text
	 * first. What the administrator wanted there is an encrypted connection,
	 * which on 143 means STARTTLS.
	 */
	public function convert_security()
	{
		$ssl = !empty($this->config['mailhealth_imap_ssl']);
		$port = (int) $this->config['mailhealth_imap_port'];

		if (!$ssl)
		{
			$security = 0;
		}
		else if ($port === 143)
		{
			$security = 2;
		}
		else
		{
			$security = 1;
		}

		$this->config->set('mailhealth_imap_security', $security);
	}
}
